<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\SessionStoreInterface;
use ChenZhanjie\Agentic\Event\EventEmitter;
use ChenZhanjie\Agentic\Persona\Persona;

/**
 * Agentic Facade — unified entry point.
 *
 * Config-driven, Hermes-inspired: an Agent is a config, not a class.
 *
 * LLM calls (chat/chatStream) bypass AgentRunner and go directly to LlmClient.
 * Agent calls (run/runStream/runWithConfig/runStreamWithConfig) go through AgentRunner.
 */
class Agentic
{
    use EventEmitter;

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly AgentRunner $runner,
        private readonly ToolRegistry $toolRegistry,
        private readonly PromptBuilder $promptBuilder,
        private readonly ?SessionStoreInterface $sessionStore = null,
        private readonly ?MessageStoreInterface $messageStore = null,
        private readonly ?PermissionApprovalStoreInterface $approvalStore = null,
        private readonly array $agentDefs = [],
        private readonly array $defaults = [],
    ) {
    }

    /**
     * Pure LLM chat — bypasses AgentRunner entirely.
     */
    public function chat(array $messages, array $options = []): LlmResponse
    {
        $config = array_merge($this->defaults, $this->agentDefs['__llm__'] ?? []);

        $llmOptions = [];
        $provider = $options['provider'] ?? $config['provider'] ?? null;
        if ($provider !== null) {
            $llmOptions['provider'] = $provider;
        }
        $model = $options['model'] ?? $options['model_override'] ?? $config['model'] ?? null;
        if ($model !== null) {
            $llmOptions['model'] = $model;
        }

        return $this->llmClient->chat($messages, $llmOptions);
    }

    /**
     * Execute a named agent (non-streaming).
     */
    public function run(string $agentName, array $messages, array $options = []): AgentResult
    {
        $config = $this->getAgentConfig($agentName);

        return $this->runner->run($messages, $config, $options);
    }

    /**
     * Execute a named agent with streaming (SSE events via onEvent callback).
     */
    public function runStream(string $agentName, array $messages, ?callable $onEvent = null, array $options = []): AgentResult
    {
        $config = $this->getAgentConfig($agentName);

        return $this->runner->runStream($messages, $config, $options, $onEvent);
    }

    /**
     * Execute an agent with a dynamic config (bypasses agent name lookup).
     *
     * Accepts either an Agent DTO or a legacy array config.
     * Merges $this->defaults as the base layer, then agent config on top.
     * Supports conversation_id option for automatic history load/append.
     *
     * @param Agent|array $agentConfig  Agent DTO or full config array
     * @param array       $messages     New messages for this turn
     * @param array       $options      Runtime options: conversation_id, runtime_context, etc.
     */
    public function runWithConfig(Agent|array $agentConfig, array $messages, array $options = []): AgentResult
    {
        $config = $this->resolveAgentConfig($agentConfig);
        $fullMessages = $this->resolveMessages($messages, $options);

        $result = $this->runner->run($fullMessages, $config, $options);

        if ($result->isComplete()) {
            $this->persistMessages($options, $messages, $result);
        }

        return $result;
    }

    /**
     * Execute an agent with dynamic config + streaming support.
     *
     * @param Agent|array $agentConfig  Agent DTO or full config array
     */
    public function runStreamWithConfig(Agent|array $agentConfig, array $messages, ?callable $onEvent = null, array $options = []): AgentResult
    {
        $config = $this->resolveAgentConfig($agentConfig);
        $fullMessages = $this->resolveMessages($messages, $options);

        $result = $this->runner->runStream($fullMessages, $config, $options, $onEvent);

        if ($result->isComplete()) {
            $this->persistMessages($options, $messages, $result);
        }

        return $result;
    }

    /**
     * Pure LLM streaming chat — forwards chunks to onChunk callback.
     * Bypasses AgentRunner entirely.
     */
    public function chatStream(array $messages, callable $onChunk, array $options = []): LlmResponse
    {
        $config = array_merge($this->defaults, $this->agentDefs['__llm__'] ?? []);

        $llmOptions = [];
        $provider = $options['provider'] ?? $config['provider'] ?? null;
        if ($provider !== null) {
            $llmOptions['provider'] = $provider;
        }
        $model = $options['model'] ?? $options['model_override'] ?? $config['model'] ?? null;
        if ($model !== null) {
            $llmOptions['model'] = $model;
        }

        return $this->llmClient->chatStream($messages, $llmOptions, $onChunk);
    }

    /**
     * List all defined agent names.
     */
    public function agents(): array
    {
        return array_keys($this->agentDefs);
    }

    /**
     * List available (enabled) tool names.
     */
    public function availableTools(): array
    {
        return $this->toolRegistry->getAvailableNames();
    }

    /**
     * Get the persona for a named agent.
     */
    public function persona(string $agentName): ?Persona
    {
        if (!isset($this->agentDefs[$agentName])) {
            return null;
        }

        $persona = $this->agentDefs[$agentName]['persona'] ?? null;
        if ($persona instanceof Persona) {
            return $persona;
        }

        return null;
    }

    /**
     * Check if an agent is defined.
     */
    public function has(string $agentName): bool
    {
        return isset($this->agentDefs[$agentName]);
    }

    /**
     * Set the human input resolver (injected into AskTool at dispatch time).
     */
    public function setHumanInputResolver(HumanInputResolverInterface $resolver): void
    {
        $this->runner->setHumanInputResolver($resolver);
    }

    // ── Permission Approval API ──

    public function approveAll(?string $sessionId = null): void
    {
        $this->approvalStore?->approveAll($sessionId);
    }

    public function approveTool(string $toolOrPattern, ?string $sessionId = null): void
    {
        $this->approvalStore?->approve($toolOrPattern, $sessionId);
    }

    public function revokeAll(?string $sessionId = null): void
    {
        $this->approvalStore?->revokeAll($sessionId);
    }

    public function revokeTool(string $toolOrPattern, ?string $sessionId = null): void
    {
        $this->approvalStore?->revoke($toolOrPattern, $sessionId);
    }

    /**
     * Resume a suspended agent session.
     */
    public function resume(string $sessionId): AgentResult
    {
        if ($this->sessionStore === null) {
            throw new \RuntimeException('SessionStore not configured');
        }

        $state = $this->sessionStore->getAndDelete($sessionId, 'suspended_agent');

        if (empty($state) || !isset($state['messages'])) {
            throw new \RuntimeException("Session {$sessionId} not found or expired");
        }

        return $this->runner->resume($state, $sessionId);
    }

    private function resolveMessages(array $newMessages, array $options): array
    {
        $conversationId = $options['conversation_id'] ?? null;

        if ($conversationId === null || $this->messageStore === null) {
            return $newMessages;
        }

        $history = $this->messageStore->load($conversationId);

        if (empty($history)) {
            return $newMessages;
        }

        return array_merge($history, $newMessages);
    }

    private function persistMessages(array $options, array $newMessages, AgentResult $result): void
    {
        $conversationId = $options['conversation_id'] ?? null;

        if ($conversationId === null || $this->messageStore === null) {
            return;
        }

        $toAppend = $newMessages;
        $toAppend[] = ['role' => 'assistant', 'content' => $result->content];

        $this->messageStore->append($conversationId, $toAppend);
    }

    private function getAgentConfig(string $agentName): array
    {
        if (!isset($this->agentDefs[$agentName])) {
            throw new \InvalidArgumentException("Agent [{$agentName}] is not defined");
        }

        return array_replace_recursive(
            $this->defaults,
            $this->agentDefs[$agentName],
        );
    }

    private function resolveAgentConfig(Agent|array $agentConfig): array
    {
        return $agentConfig instanceof Agent
            ? array_replace_recursive($this->defaults, $agentConfig->toArray())
            : array_replace_recursive($this->defaults, $agentConfig);
    }
}
