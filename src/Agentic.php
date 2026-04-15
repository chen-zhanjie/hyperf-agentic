<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\SessionStoreInterface;
use ChenZhanjie\Agentic\Event\AgentEventType;
use ChenZhanjie\Agentic\Event\EventEmitter;
use ChenZhanjie\Agentic\Persona\Persona;

/**
 * Agentic Facade — Layer 4 unified entry point.
 *
 * Config-driven, Hermes-inspired: an Agent is a config, not a class.
 */
class Agentic
{
    use EventEmitter;

    /** @var array<string, array> Agent definitions from agentic/agents.php */
    private readonly array $agentDefs;

    /** @var array Default settings from agentic/agentic.php */
    private readonly array $defaults;

    public function __construct(
        private readonly AgentRunner $runner,
        private readonly ToolRegistry $toolRegistry,
        private readonly PromptBuilder $promptBuilder,
        private readonly ?SessionStoreInterface $sessionStore = null,
        array $agentDefs = [],
        array $defaults = [],
    ) {
        $this->agentDefs = $agentDefs;
        $this->defaults = $defaults;
    }

    /**
     * Pure LLM chat (passthrough, no agent loop).
     */
    public function chat(array $messages, array $options = []): string|array
    {
        $config = array_merge(
            $this->defaults,
            $this->agentDefs['__llm__'] ?? [],
            ['max_iterations' => 1, 'tools' => []],
        );

        $result = $this->runner->run($messages, $config, $options);

        if (is_string($result->content)) {
            return $result->content;
        }

        return $result->toArray();
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

        return $this->runner->run($messages, $config, $options, $onEvent);
    }

    /**
     * Pure LLM streaming chat — forwards chunks to onChunk callback.
     */
    public function chatStream(array $messages, callable $onChunk, array $options = []): void
    {
        $config = array_merge(
            $this->defaults,
            $this->agentDefs['__llm__'] ?? [],
        );

        $llmOptions = [
            'provider' => $config['provider'] ?? null,
            'model' => $options['model_override'] ?? $config['model'] ?? null,
        ];

        $this->runner->chatStream($messages, $llmOptions, $onChunk);
    }

    /**
     * List all defined agent names.
     */
    public function agents(): array
    {
        return array_keys($this->agentDefs);
    }

    /**
     * List all registered tool names.
     */
    public function tools(): array
    {
        return $this->toolRegistry->getAvailableNames();
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

    /**
     * Get merged agent config: defaults → agent def → runtime overrides.
     */
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
}
