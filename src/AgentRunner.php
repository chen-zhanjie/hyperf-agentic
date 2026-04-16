<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\Event\AgentEventType;
use ChenZhanjie\Agentic\Event\EventEmitter;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\Skill\SkillRegistry;

/**
 * Agent Runner — conversation loop + tool dispatch chain + guardrails + middleware.
 *
 * Layer 3 of the 5-layer architecture.
 */
class AgentRunner
{
    use EventEmitter;

    /** @var array<string, callable> Agent-level tool handlers (bypass ToolRegistry) */
    private array $agentToolHandlers = [];

    /** @var HumanInputResolverInterface|null Injected before dispatch for AskTool */
    private ?HumanInputResolverInterface $humanInputResolver = null;

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly PromptBuilder $promptBuilder,
        private readonly ToolRegistry $toolRegistry,
        private readonly GuardrailRunner $guardrailRunner,
        private readonly MiddlewarePipeline $middleware,
        private readonly ToolGuardrailRunner $toolGuardrailRunner,
        private readonly ToolPermissionPolicyInterface $permissionPolicy,
        private readonly ?PermissionApprovalStoreInterface $approvalStore = null,
        private readonly ?SkillRegistry $skillRegistry = null,
        private readonly ?ToolDispatcher $toolDispatcher = null,
    ) {}

    /**
     * Register an agent-level tool handler.
     * Agent-level handlers take priority over ToolRegistry dispatch.
     */
    public function registerAgentTool(string $name, callable $handler): void
    {
        $this->agentToolHandlers[$name] = $handler;
    }

    /**
     * Set the human input resolver (injected into AskTool at dispatch time).
     */
    public function setHumanInputResolver(HumanInputResolverInterface $resolver): void
    {
        $this->humanInputResolver = $resolver;
    }

    /**
     * Get the tool dispatcher (lazy-created if not injected).
     */
    private function toolDispatcher(): ToolDispatcher
    {
        return $this->toolDispatcher ?? new ToolDispatcher(
            $this->toolRegistry,
            $this->middleware,
            $this->permissionPolicy,
        );
    }

    /**
     * Stream LLM chat chunks directly — no agent loop, passthrough to LlmClient.
     */
    public function chatStream(array $messages, array $options, callable $onChunk): void
    {
        $this->llmClient->chatStream($messages, $options, $onChunk);
    }

    /**
     * Resume a suspended agent session.
     * Restores state from SessionStore and continues the conversation loop.
     *
     * @param array  $state     Suspended state (messages, remaining_iterations, agent_config, agent_name)
     * @param string $sessionId Session ID
     */
    public function resume(array $state, string $sessionId): AgentResult
    {
        $startTime = hrtime(true);

        // Validate required state fields
        $messages = $state['messages'] ?? [];
        if (empty($messages) || !is_array($messages)) {
            throw new \InvalidArgumentException('Invalid resume state: messages must be a non-empty array');
        }

        $remainingIterations = (int) ($state['remaining_iterations'] ?? 5);
        if ($remainingIterations < 1) {
            throw new \InvalidArgumentException('Invalid resume state: remaining_iterations must be >= 1');
        }

        $agentConfig = $state['agent_config'] ?? [];
        $agentName = $state['agent_name'] ?? 'Assistant';

        // Setup
        $budget = new IterationBudget(maxTotal: $remainingIterations);
        $costBudget = new CostBudget(maxTotalTokens: (int) ($agentConfig['max_cost_tokens'] ?? 0) ?: PHP_INT_MAX);

        $setup = $this->resolveRunSetup($agentConfig, $sessionId);

        $context = new AgentRunContext(
            guardrails: $setup['activeGuardrails'],
            toolGuardrails: $this->toolGuardrailRunner,
            permissionPolicy: $setup['policy'],
            approvalStore: $setup['requestApprovalStore'],
            humanInputResolver: $this->humanInputResolver,
            agentToolHandlers: $this->agentToolHandlers,
            sessionId: $setup['sessionId'],
        );

        $toolSchemas = $setup['activeRegistry']->getAvailableSchemas();
        $enabledSkills = $agentConfig['skills'] ?? [];
        $systemMessage = $this->promptBuilder->build(
            persona: $setup['persona'],
            agentName: $agentName,
            tools: $setup['activeRegistry'],
            runtimeContext: [],
            budget: $budget,
            systemPrompt: $setup['systemPrompt'],
            scene: $setup['scene'],
            skillRegistry: $this->skillRegistry,
            enabledSkills: $enabledSkills,
            costBudget: $costBudget,
        );

        $this->promptBuilder->reset();

        $fullMessages = array_merge(
            [['role' => 'system', 'content' => $systemMessage]],
            $messages,
        );

        $loop = new LoopState(
            startTime: $startTime,
            budget: $budget,
            costBudget: $costBudget,
            maxIterations: $remainingIterations,
            asyncGuardrailTimeout: 5000,
        );

        $result = $this->runLoop(
            $fullMessages, $systemMessage, $toolSchemas,
            [], null, $loop, $context, null,
        );

        if ($result === null && $budget->consumeGrace() && !$costBudget->isExceeded()) {
            $result = $this->runGraceTurn(
                $fullMessages, $systemMessage, $toolSchemas,
                [], null, $loop, $context, null,
            );
        }

        if ($result === null) {
            return AgentResult::budgetExhausted($loop->iterations, $remainingIterations);
        }

        return $result;
    }

    /**
     * Execute the agent conversation loop.
     *
     * @param array $messages Conversation history. Each message is an array with:
     *   - role:    string       'user' | 'assistant' | 'tool'
     *   - content: string|null  Text content (null when tool_calls present)
     *   - tool_calls: array     (assistant only) Array of tool call objects
     *   - tool_call_id: string  (tool only) ID of the tool call being answered
     * @param array $agentConfig Agent configuration:
     *   - persona:          Persona|string|null  Persona object, markdown string, or null for default
     *   - tools:            string[]             Tool name whitelist (empty = all tools)
     *   - skills:           string[]             Skill name whitelist (empty = all skills)
     *   - system_prompt:    string               Additional system prompt text
     *   - max_iterations:   int                  Max iteration rounds (default: 15)
     *   - max_cost_tokens:  int                  Max total token budget (default: unlimited)
     *   - scene:            string               Runtime scene: 'http', 'cli', etc.
     *   - guardrails:       string[]             Guardrail name whitelist (empty = all guardrails)
     * @param array         $options     Runtime overrides (runtime_context, etc.)
     * @param callable|null $onEvent     Event callback: callable(string $type, array $payload): void
     */
    public function run(
        array $messages,
        array $agentConfig = [],
        array $options = [],
        ?callable $onEvent = null,
    ): AgentResult {
        $startTime = hrtime(true);
        $this->promptBuilder->reset();

        // Phase 1: Setup
        $maxIterations = (int) ($agentConfig['max_iterations'] ?? 15);
        $budget = new IterationBudget(maxTotal: $maxIterations);
        $costBudget = new CostBudget(
            maxTotalTokens: (int) ($agentConfig['max_cost_tokens'] ?? 0) ?: PHP_INT_MAX,
        );

        $loop = new LoopState(
            startTime: $startTime,
            budget: $budget,
            costBudget: $costBudget,
            maxIterations: $maxIterations,
            asyncGuardrailTimeout: (int) ($agentConfig['async_guardrail_timeout'] ?? 5000),
        );

        $sessionId = $options['conversation_id'] ?? null;
        $setup = $this->resolveRunSetup($agentConfig, $sessionId);

        // Build per-request context (with cancellation token)
        $cancellationToken = null;
        $cancellationTimeoutMs = (int) ($agentConfig['cancellation_timeout_ms'] ?? 0);
        if ($cancellationTimeoutMs > 0) {
            $cancellationToken = CancellationToken::withTimeout($cancellationTimeoutMs);
        }

        $context = new AgentRunContext(
            guardrails: $setup['activeGuardrails'],
            toolGuardrails: $this->toolGuardrailRunner,
            permissionPolicy: $setup['policy'],
            approvalStore: $setup['requestApprovalStore'],
            humanInputResolver: $this->humanInputResolver,
            agentToolHandlers: $this->agentToolHandlers,
            cancellationToken: $cancellationToken,
            sessionId: $setup['sessionId'],
        );

        // Phase 2: Input guardrails (async-aware)
        $inputGuardContext = $context->guardrails->checkInputAsync($messages);
        if ($inputGuardContext->isBlocked()) {
            $blockResult = $inputGuardContext->getBlockResult();
            $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_BLOCKED, [
                'type' => 'input',
                'name' => $inputGuardContext->getBlockName() ?? 'input_guard',
                'reason' => $blockResult->reason,
            ]);
            return AgentResult::guardrailBlocked('input', $blockResult->reason, $loop->elapsedMs());
        }

        // Phase 3: System prompt resolution
        $toolSchemas = $setup['activeRegistry']->getAvailableSchemas();
        $enabledSkills = $agentConfig['skills'] ?? [];
        $systemMessage = $this->promptBuilder->build(
            persona: $setup['persona'],
            agentName: $setup['persona']->name,
            tools: $setup['activeRegistry'],
            runtimeContext: $options['runtime_context'] ?? [],
            budget: $budget,
            systemPrompt: $setup['systemPrompt'],
            scene: $setup['scene'],
            skillRegistry: $this->skillRegistry,
            enabledSkills: $enabledSkills,
            costBudget: $costBudget,
        );

        // Phase 5: Middleware — before loop
        $messages = $this->middleware->beforeLoop($messages, $agentConfig);

        // Emit started event
        $this->emitEvent($onEvent, AgentEventType::STARTED, [
            'agent' => $persona->name,
        ]);

        // Build the full message array with system prompt
        $fullMessages = array_merge(
            [['role' => 'system', 'content' => $systemMessage]],
            $messages,
        );

        // Phase 6: Main loop
        $result = $this->runLoop(
            $fullMessages, $systemMessage, $toolSchemas,
            $options, $onEvent, $loop, $context, $inputGuardContext,
        );

        // Grace turn: budget exhausted but LLM gets one more chance to wrap up
        if ($result === null && $budget->consumeGrace() && !$costBudget->isExceeded()) {
            $result = $this->runGraceTurn(
                $fullMessages, $systemMessage, $toolSchemas,
                $options, $onEvent, $loop, $context, $inputGuardContext,
            );
        }

        // If grace turn also didn't produce a result, or grace was already used
        if ($result === null) {
            $this->emitEvent($onEvent, AgentEventType::BUDGET_EXCEEDED, [
                'iterations' => $loop->iterations,
                'max' => $maxIterations,
            ]);
            return AgentResult::budgetExhausted($loop->iterations, $maxIterations);
        }

        return $result;
    }

    /**
     * Run the main iteration loop.
     * Returns AgentResult on success, or null when budget is exhausted.
     */
    private function runLoop(
        array &$fullMessages,
        string $systemMessage,
        array $toolSchemas,
        array $options,
        ?callable $onEvent,
        LoopState $loop,
        AgentRunContext $context,
        ?AsyncGuardrailContext $inputGuardContext = null,
    ): ?AgentResult {
        while ($loop->budget->consume() && !$loop->costBudget->isExceeded() && !$context->isCancelled()) {
            // Check async input guardrails (may complete between iterations)
            if ($inputGuardContext !== null && $inputGuardContext->isBlocked()) {
                $blockResult = $inputGuardContext->getBlockResult();
                $blockName = $inputGuardContext->getBlockName() ?? 'input_async';
                $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_RECALLED, [
                    'type' => 'input',
                    'name' => $blockName,
                    'reason' => $blockResult->reason,
                ]);
                return AgentResult::guardrailBlocked('input_async', $blockResult->reason, $loop->elapsedMs());
            }

            ++$loop->iterations;

            $turnResult = $this->executeTurn(
                $fullMessages, $systemMessage, $toolSchemas,
                $options, $onEvent, $loop, $context,
            );

            if ($turnResult !== null) {
                return $turnResult;
            }
            // Tool calls processed — continue loop
        }

        return null; // Budget exhausted
    }

    /**
     * Execute one grace turn after budget exhaustion.
     */
    private function runGraceTurn(
        array &$fullMessages,
        string $systemMessage,
        array $toolSchemas,
        array $options,
        ?callable $onEvent,
        LoopState $loop,
        AgentRunContext $context,
        ?AsyncGuardrailContext $inputGuardContext = null,
    ): ?AgentResult {
        ++$loop->iterations;

        // Build ephemeral prompt with grace message (budget.isExhausted() + grace consumed)
        $ephemeral = $this->promptBuilder->buildEphemeralPrompt(
            runtimeContext: $options['runtime_context'] ?? [],
            budget: $loop->budget,
        );

        $fullMessages[0]['content'] = $systemMessage . "\n\n---\n\n" . $ephemeral;

        // Middleware — before LLM call
        $llmOptions = $this->middleware->beforeLlmCall($fullMessages, [
            'tools' => $toolSchemas,
        ]);

        $this->emitEvent($onEvent, AgentEventType::THINKING, [
            'iteration' => $loop->iterations,
        ]);

        $response = $this->llmClient->chat($fullMessages, $llmOptions);
        $responseArray = $this->normalizeResponse($response);
        $content = $responseArray['content'];
        $toolCalls = $responseArray['tool_calls'] ?? [];
        $usage = $responseArray['usage'] ?? [];

        $loop->recordUsage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        );

        $this->middleware->afterLlmCall($responseArray, $usage);

        // No tool calls → text response → clean finish via grace
        if (empty($toolCalls)) {
            $textContent = is_string($content) ? $content : (string) ($content ?? '');

            $outputGuardContext = $context->guardrails->checkOutputAsync($textContent);
            if ($outputGuardContext->isBlocked()) {
                $blockResult = $outputGuardContext->getBlockResult();
                $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_BLOCKED, [
                    'type' => 'output',
                    'name' => $outputGuardContext->getBlockName() ?? 'output_guard',
                    'reason' => $blockResult->reason,
                ]);
                return AgentResult::guardrailBlocked('output', $blockResult->reason, $loop->elapsedMs());
            }

            $result = AgentResult::complete(
                content: $textContent,
                iterations: $loop->iterations,
                elapsedMs: $loop->elapsedMs(),
                promptTokens: $loop->totalPromptTokens,
                completionTokens: $loop->totalCompletionTokens,
                toolCalls: $loop->totalToolCalls,
            );

            $result = $this->middleware->afterLoop($result);

            $this->emitEvent($onEvent, AgentEventType::COMPLETE, [
                'iterations' => $loop->iterations,
                'elapsed_ms' => $result->elapsedMs,
                'prompt_tokens' => $loop->totalPromptTokens,
                'completion_tokens' => $loop->totalCompletionTokens,
            ]);

            // Wait for async guardrails
            if ($outputGuardContext->hasAsyncGuardrails() && !$outputGuardContext->allCompleted()) {
                $outputGuardContext->await(5000);
            }

            if ($outputGuardContext->isBlocked()) {
                $blockResult = $outputGuardContext->getBlockResult();
                $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_RECALLED, [
                    'type' => 'output',
                    'name' => $outputGuardContext->getBlockName() ?? 'output_async',
                    'reason' => $blockResult->reason,
                ]);
                return AgentResult::recalled(
                    content: $textContent,
                    reason: $blockResult->reason,
                    elapsedMs: $loop->elapsedMs(),
                );
            }

            return $result;
        }

        // Grace turn still called tools — process them but don't loop further
        $loop->recordToolCalls(count($toolCalls));

        $fullMessages[] = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];

        $this->toolDispatcher()->processToolCalls($toolCalls, $fullMessages, $onEvent, 'grace', false, $context);

        return null; // Grace turn didn't produce text → truly exhausted
    }

    /**
     * Execute a single turn in the loop.
     * Returns AgentResult if completed (text response), null if tool calls were processed.
     */
    private function executeTurn(
        array &$fullMessages,
        string $systemMessage,
        array $toolSchemas,
        array $options,
        ?callable $onEvent,
        LoopState $loop,
        AgentRunContext $context,
    ): ?AgentResult {
        // Build ephemeral prompt for this turn
        $ephemeral = $this->promptBuilder->buildEphemeralPrompt(
            runtimeContext: $options['runtime_context'] ?? [],
            budget: $loop->budget,
            costBudget: $loop->costBudget,
        );

        // Update system message with ephemeral layer
        $fullMessages[0]['content'] = $systemMessage . "\n\n---\n\n" . $ephemeral;

        // Middleware — before LLM call
        $llmOptions = $this->middleware->beforeLlmCall($fullMessages, [
            'tools' => $toolSchemas,
        ]);

        // Emit thinking event
        $this->emitEvent($onEvent, AgentEventType::THINKING, [
            'iteration' => $loop->iterations,
        ]);

        // Call LLM
        $response = $this->llmClient->chat($fullMessages, $llmOptions);

        // Parse response
        $responseArray = $this->normalizeResponse($response);
        $content = $responseArray['content'];
        $toolCalls = $responseArray['tool_calls'] ?? [];
        $usage = $responseArray['usage'] ?? [];

        // Track token usage
        $loop->recordUsage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        );

        // Middleware — after LLM call
        $this->middleware->afterLlmCall($responseArray, $usage);

        // No tool calls → text response → done
        if (empty($toolCalls)) {
            $textContent = is_string($content) ? $content : (string) ($content ?? '');

            // Phase 7: Output guardrail check (async-aware)
            $outputGuardContext = $context->guardrails->checkOutputAsync($textContent);

            // Sync guardrail blocked immediately (before output reaches client)
            if ($outputGuardContext->isBlocked() && !$outputGuardContext->hasAsyncGuardrails()) {
                $blockResult = $outputGuardContext->getBlockResult();
                $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_BLOCKED, [
                    'type' => 'output',
                    'name' => $outputGuardContext->getBlockName() ?? 'output_guard',
                    'reason' => $blockResult->reason,
                ]);
                return AgentResult::guardrailBlocked('output', $blockResult->reason, $loop->elapsedMs());
            }

            // Middleware — after loop
            $result = AgentResult::complete(
                content: $textContent,
                iterations: $loop->iterations,
                elapsedMs: $loop->elapsedMs(),
                promptTokens: $loop->totalPromptTokens,
                completionTokens: $loop->totalCompletionTokens,
                toolCalls: $loop->totalToolCalls,
            );

            $result = $this->middleware->afterLoop($result);

            $this->emitEvent($onEvent, AgentEventType::COMPLETE, [
                'iterations' => $loop->iterations,
                'elapsed_ms' => $result->elapsedMs,
                'prompt_tokens' => $loop->totalPromptTokens,
                'completion_tokens' => $loop->totalCompletionTokens,
            ]);

            // Wait for async guardrails to complete
            if ($outputGuardContext->hasAsyncGuardrails() && !$outputGuardContext->allCompleted()) {
                $outputGuardContext->await($loop->asyncGuardrailTimeout);
            }

            // Async guardrail blocked after output → recall
            if ($outputGuardContext->isBlocked()) {
                $blockResult = $outputGuardContext->getBlockResult();
                $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_RECALLED, [
                    'type' => 'output',
                    'name' => $outputGuardContext->getBlockName() ?? 'output_async',
                    'reason' => $blockResult->reason,
                ]);
                return AgentResult::recalled(
                    content: $textContent,
                    reason: $blockResult->reason,
                    elapsedMs: $loop->elapsedMs(),
                );
            }

            return $result;
        }

        // Tool calls — process each one
        $loop->recordToolCalls(count($toolCalls));

        // Append assistant message with tool_calls
        $fullMessages[] = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];

        $this->toolDispatcher()->processToolCalls($toolCalls, $fullMessages, $onEvent, (string) $loop->iterations, true, $context);

        return null; // Tool calls processed — continue loop
    }

    /**
     * Shared setup: resolve persona, tools, guardrails, permissions from agent config.
     * Used by both run() and resume() to eliminate duplication.
     *
     * @return array{persona:Persona,systemPrompt:string,scene:string,activeRegistry:ToolRegistry,activeGuardrails:GuardrailRunner,policy:ToolPermissionPolicyInterface,sessionId:?string,requestApprovalStore:?PermissionApprovalStoreInterface}
     */
    private function resolveRunSetup(array $agentConfig, ?string $sessionId): array
    {
        $persona = $this->resolvePersona($agentConfig);
        $systemPrompt = $agentConfig['system_prompt'] ?? '';
        $scene = $agentConfig['scene'] ?? 'http';
        $toolWhitelist = $agentConfig['tools'] ?? [];

        // Resolve active tool registry
        $activeRegistry = $this->toolRegistry;
        if (!empty($toolWhitelist)) {
            $activeRegistry = $this->toolRegistry->only($toolWhitelist);
        }

        // Resolve active guardrails (per-agent filtering + mode overrides)
        $activeGuardrails = $this->guardrailRunner;
        $guardrailWhitelist = $agentConfig['guardrails'] ?? [];
        if (!empty($guardrailWhitelist)) {
            $activeGuardrails = $this->guardrailRunner->only($guardrailWhitelist);
        }

        $guardrailModes = $agentConfig['guardrail_modes'] ?? [];
        if (!empty($guardrailModes)) {
            $modeMap = [];
            foreach ($guardrailModes as $name => $modeStr) {
                $modeMap[$name] = GuardrailMode::from($modeStr);
            }
            $activeGuardrails = $activeGuardrails->withModes($modeMap);
        }

        // Build per-request permission policy
        $policy = $this->resolvePermissionPolicy($agentConfig);

        // Clone approval store for per-request isolation
        $requestApprovalStore = $this->approvalStore !== null ? clone $this->approvalStore : null;
        $this->applyAutoApprove($agentConfig, $sessionId, $requestApprovalStore);

        return [
            'persona' => $persona,
            'systemPrompt' => $systemPrompt,
            'scene' => $scene,
            'activeRegistry' => $activeRegistry,
            'activeGuardrails' => $activeGuardrails,
            'policy' => $policy,
            'sessionId' => $sessionId,
            'requestApprovalStore' => $requestApprovalStore,
        ];
    }

    /**
     * Resolve per-request permission policy from agent config.
     */
    private function resolvePermissionPolicy(array $agentConfig): ToolPermissionPolicyInterface
    {
        $permissionConfig = $agentConfig['tool_permissions'] ?? [];
        $hasPermissionConfig = !empty($permissionConfig) || isset($agentConfig['permission_mode']);

        if ($hasPermissionConfig) {
            $mode = PermissionMode::from($agentConfig['permission_mode'] ?? 'default');
            return new ConfigToolPermissionPolicy(
                rules: [
                    'allow' => $permissionConfig['allow'] ?? [],
                    'ask'   => $permissionConfig['ask'] ?? [],
                    'deny'  => $permissionConfig['deny'] ?? [],
                ],
                defaultAskThreshold: ToolRiskLevel::from($permissionConfig['default_ask_threshold'] ?? 'high'),
                mode: $mode,
            );
        }

        return $this->permissionPolicy;
    }

    /**
     * Apply auto_approve config — pre-populate the per-request approval store.
     */
    private function applyAutoApprove(array $agentConfig, ?string $sessionId, ?PermissionApprovalStoreInterface $store): void
    {
        $permissionConfig = $agentConfig['tool_permissions'] ?? [];
        $autoApprove = $permissionConfig['auto_approve'] ?? $agentConfig['auto_approve'] ?? null;

        if ($autoApprove === null || $store === null) {
            return;
        }

        if ($autoApprove === true) {
            $store->approveAll($sessionId);
        } elseif (is_array($autoApprove)) {
            foreach ($autoApprove as $pattern) {
                $store->approve($pattern, $sessionId);
            }
        }
    }

    /**
     * Resolve a Persona from agentConfig.
     */
    private function resolvePersona(array $agentConfig): Persona
    {
        $persona = $agentConfig['persona'] ?? null;

        if ($persona instanceof Persona) {
            return $persona;
        }

        if (is_array($persona)) {
            return Persona::fromArray($persona);
        }

        if (is_string($persona) && $persona !== '') {
            return Persona::fromMarkdown($persona);
        }

        // Default persona
        return new Persona(
            name: 'Assistant',
            content: 'You are a helpful AI assistant.',
        );
    }

    /**
     * Normalize LLM response to array format.
     */
    private function normalizeResponse(string|array $response): array
    {
        if (is_string($response)) {
            return [
                'content' => $response,
                'tool_calls' => [],
                'usage' => [],
            ];
        }

        return array_merge([
            'content' => '',
            'tool_calls' => [],
            'usage' => [],
        ], $response);
    }

    /**
     * Emit event to both internal listeners and external callback.
     */
    private function emitEvent(?callable $onEvent, AgentEventType $type, array $payload = []): void
    {
        // Internal EventEmitter
        $this->emit($type, $payload);

        // External callback
        if ($onEvent !== null) {
            $onEvent($type->value, $payload);
        }
    }
}
