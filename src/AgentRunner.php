<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\Contract\RiskyToolInterface;
use ChenZhanjie\Agentic\Event\AgentEventType;
use ChenZhanjie\Agentic\Event\EventEmitter;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\Tool\Builtin\AskTool;

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
        private readonly ?SkillRegistry $skillRegistry = null,
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

        $persona = $this->resolvePersona($agentConfig);
        $systemPrompt = $agentConfig['system_prompt'] ?? '';
        $scene = $agentConfig['scene'] ?? 'http';
        $toolWhitelist = $agentConfig['tools'] ?? [];

        $activeRegistry = $this->toolRegistry;
        if (!empty($toolWhitelist)) {
            $activeRegistry = $this->toolRegistry->only($toolWhitelist);
        }

        // Resolve active guardrails (per-agent filtering)
        $activeGuardrails = $this->guardrailRunner;
        $guardrailWhitelist = $agentConfig['guardrails'] ?? [];
        if (!empty($guardrailWhitelist)) {
            $activeGuardrails = $this->guardrailRunner->only($guardrailWhitelist);
        }

        // Build per-request context
        $context = new AgentRunContext(
            guardrails: $activeGuardrails,
            toolGuardrails: $this->toolGuardrailRunner,
            permissionPolicy: $this->permissionPolicy,
            humanInputResolver: $this->humanInputResolver,
            agentToolHandlers: $this->agentToolHandlers,
        );

        $toolSchemas = $activeRegistry->getAvailableSchemas();
        $enabledSkills = $agentConfig['skills'] ?? [];
        $systemMessage = $this->promptBuilder->build(
            persona: $persona,
            agentName: $agentName,
            tools: $activeRegistry,
            runtimeContext: [],
            budget: $budget,
            systemPrompt: $systemPrompt,
            scene: $scene,
            skillRegistry: $this->skillRegistry,
            enabledSkills: $enabledSkills,
            costBudget: $costBudget,
        );

        $this->promptBuilder->reset();

        $fullMessages = array_merge(
            [['role' => 'system', 'content' => $systemMessage]],
            $messages,
        );

        $iterations = 0;
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $totalToolCalls = 0;

        $result = $this->runLoop(
            $fullMessages, $systemMessage, $toolSchemas, $budget, $remainingIterations,
            $costBudget, [], null, $startTime,
            $iterations, $totalPromptTokens, $totalCompletionTokens, $totalToolCalls,
            $context, null, 5000,
        );

        if ($result === null && $budget->consumeGrace() && !$costBudget->isExceeded()) {
            $result = $this->runGraceTurn(
                $fullMessages, $systemMessage, $toolSchemas, $budget,
                $costBudget, [], null, $startTime,
                $iterations, $totalPromptTokens, $totalCompletionTokens, $totalToolCalls,
                $context, null,
            );
        }

        if ($result === null) {
            return AgentResult::budgetExhausted($iterations, $remainingIterations);
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

        // Apply guardrail mode overrides from config
        $guardrailModes = $agentConfig['guardrail_modes'] ?? [];
        if (!empty($guardrailModes)) {
            $modeMap = [];
            foreach ($guardrailModes as $name => $modeStr) {
                $modeMap[$name] = GuardrailMode::from($modeStr);
            }
            $activeGuardrails = $activeGuardrails->withModes($modeMap);
        }

        // Build per-request context
        $cancellationToken = null;
        $cancellationTimeoutMs = (int) ($agentConfig['cancellation_timeout_ms'] ?? 0);
        if ($cancellationTimeoutMs > 0) {
            $cancellationToken = CancellationToken::withTimeout($cancellationTimeoutMs);
        }

        $context = new AgentRunContext(
            guardrails: $activeGuardrails,
            toolGuardrails: $this->toolGuardrailRunner,
            permissionPolicy: $this->permissionPolicy,
            humanInputResolver: $this->humanInputResolver,
            agentToolHandlers: $this->agentToolHandlers,
            cancellationToken: $cancellationToken,
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
            return AgentResult::guardrailBlocked('input', $blockResult->reason, $this->elapsedMs($startTime));
        }

        // Phase 3: System prompt resolution
        $toolSchemas = $activeRegistry->getAvailableSchemas();
        $enabledSkills = $agentConfig['skills'] ?? [];
        $systemMessage = $this->promptBuilder->build(
            persona: $persona,
            agentName: $persona->name,
            tools: $activeRegistry,
            runtimeContext: $options['runtime_context'] ?? [],
            budget: $budget,
            systemPrompt: $systemPrompt,
            scene: $scene,
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
        $iterations = 0;
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $totalToolCalls = 0;

        $asyncGuardrailTimeout = (int) ($agentConfig['async_guardrail_timeout'] ?? 5000);

        $result = $this->runLoop(
            $fullMessages, $systemMessage, $toolSchemas, $budget, $maxIterations,
            $costBudget, $options, $onEvent, $startTime,
            $iterations, $totalPromptTokens, $totalCompletionTokens, $totalToolCalls,
            $context,
            $inputGuardContext,
            $asyncGuardrailTimeout,
        );

        // Grace turn: budget exhausted but LLM gets one more chance to wrap up
        if ($result === null && $budget->consumeGrace() && !$costBudget->isExceeded()) {
            $result = $this->runGraceTurn(
                $fullMessages, $systemMessage, $toolSchemas, $budget,
                $costBudget, $options, $onEvent, $startTime,
                $iterations, $totalPromptTokens, $totalCompletionTokens, $totalToolCalls,
                $context,
                $inputGuardContext,
            );
        }

        // If grace turn also didn't produce a result, or grace was already used
        if ($result === null) {
            $this->emitEvent($onEvent, AgentEventType::BUDGET_EXCEEDED, [
                'iterations' => $iterations,
                'max' => $maxIterations,
            ]);
            return AgentResult::budgetExhausted($iterations, $maxIterations);
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
        IterationBudget $budget,
        int $maxIterations,
        CostBudget $costBudget,
        array $options,
        ?callable $onEvent,
        int $startTime,
        int &$iterations,
        int &$totalPromptTokens,
        int &$totalCompletionTokens,
        int &$totalToolCalls,
        AgentRunContext $context,
        ?AsyncGuardrailContext $inputGuardContext = null,
        int $asyncGuardrailTimeout = 5000,
    ): ?AgentResult {
        while ($budget->consume() && !$costBudget->isExceeded() && !$context->isCancelled()) {
            // Check async input guardrails (may complete between iterations)
            if ($inputGuardContext !== null && $inputGuardContext->isBlocked()) {
                $blockResult = $inputGuardContext->getBlockResult();
                $blockName = $inputGuardContext->getBlockName() ?? 'input_async';
                $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_RECALLED, [
                    'type' => 'input',
                    'name' => $blockName,
                    'reason' => $blockResult->reason,
                ]);
                return AgentResult::guardrailBlocked('input_async', $blockResult->reason, $this->elapsedMs($startTime));
            }

            ++$iterations;

            $turnResult = $this->executeTurn(
                $fullMessages, $systemMessage, $toolSchemas, $budget,
                $costBudget, $options, $onEvent, $startTime,
                $iterations, $totalPromptTokens, $totalCompletionTokens, $totalToolCalls,
                $context, $asyncGuardrailTimeout,
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
        IterationBudget $budget,
        CostBudget $costBudget,
        array $options,
        ?callable $onEvent,
        int $startTime,
        int &$iterations,
        int &$totalPromptTokens,
        int &$totalCompletionTokens,
        int &$totalToolCalls,
        AgentRunContext $context,
        ?AsyncGuardrailContext $inputGuardContext = null,
    ): ?AgentResult {
        ++$iterations;

        // Build ephemeral prompt with grace message (budget.isExhausted() + grace consumed)
        $ephemeral = $this->promptBuilder->buildEphemeralPrompt(
            runtimeContext: $options['runtime_context'] ?? [],
            budget: $budget,
        );

        $fullMessages[0]['content'] = $systemMessage . "\n\n---\n\n" . $ephemeral;

        // Middleware — before LLM call
        $llmOptions = $this->middleware->beforeLlmCall($fullMessages, [
            'tools' => $toolSchemas,
        ]);

        $this->emitEvent($onEvent, AgentEventType::THINKING, [
            'iteration' => $iterations,
        ]);

        $response = $this->llmClient->chat($fullMessages, $llmOptions);
        $responseArray = $this->normalizeResponse($response);
        $content = $responseArray['content'];
        $toolCalls = $responseArray['tool_calls'] ?? [];
        $usage = $responseArray['usage'] ?? [];

        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalPromptTokens += $promptTokens;
        $totalCompletionTokens += $completionTokens;
        $costBudget->consume($promptTokens, $completionTokens);

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
                return AgentResult::guardrailBlocked('output', $blockResult->reason, $this->elapsedMs($startTime));
            }

            $result = AgentResult::complete(
                content: $textContent,
                iterations: $iterations,
                elapsedMs: $this->elapsedMs($startTime),
                promptTokens: $totalPromptTokens,
                completionTokens: $totalCompletionTokens,
                toolCalls: $totalToolCalls,
            );

            $result = $this->middleware->afterLoop($result);

            $this->emitEvent($onEvent, AgentEventType::COMPLETE, [
                'iterations' => $iterations,
                'elapsed_ms' => $result->elapsedMs,
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
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
                    elapsedMs: $this->elapsedMs($startTime),
                );
            }

            return $result;
        }

        // Grace turn still called tools — process them but don't loop further
        $totalToolCalls += count($toolCalls);

        $fullMessages[] = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];

        $this->processToolCalls($toolCalls, $fullMessages, $onEvent, 'grace', false, $context);

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
        IterationBudget $budget,
        CostBudget $costBudget,
        array $options,
        ?callable $onEvent,
        int $startTime,
        int &$iterations,
        int &$totalPromptTokens,
        int &$totalCompletionTokens,
        int &$totalToolCalls,
        AgentRunContext $context,
        int $asyncGuardrailTimeout = 5000,
    ): ?AgentResult {
        // Build ephemeral prompt for this turn
        $ephemeral = $this->promptBuilder->buildEphemeralPrompt(
            runtimeContext: $options['runtime_context'] ?? [],
            budget: $budget,
            costBudget: $costBudget,
        );

        // Update system message with ephemeral layer
        $fullMessages[0]['content'] = $systemMessage . "\n\n---\n\n" . $ephemeral;

        // Middleware — before LLM call
        $llmOptions = $this->middleware->beforeLlmCall($fullMessages, [
            'tools' => $toolSchemas,
        ]);

        // Emit thinking event
        $this->emitEvent($onEvent, AgentEventType::THINKING, [
            'iteration' => $iterations,
        ]);

        // Call LLM
        $response = $this->llmClient->chat($fullMessages, $llmOptions);

        // Parse response
        $responseArray = $this->normalizeResponse($response);
        $content = $responseArray['content'];
        $toolCalls = $responseArray['tool_calls'] ?? [];
        $usage = $responseArray['usage'] ?? [];

        // Track token usage
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalPromptTokens += $promptTokens;
        $totalCompletionTokens += $completionTokens;
        $costBudget->consume($promptTokens, $completionTokens);

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
                return AgentResult::guardrailBlocked('output', $blockResult->reason, $this->elapsedMs($startTime));
            }

            // Middleware — after loop
            $result = AgentResult::complete(
                content: $textContent,
                iterations: $iterations,
                elapsedMs: $this->elapsedMs($startTime),
                promptTokens: $totalPromptTokens,
                completionTokens: $totalCompletionTokens,
                toolCalls: $totalToolCalls,
            );

            $result = $this->middleware->afterLoop($result);

            $this->emitEvent($onEvent, AgentEventType::COMPLETE, [
                'iterations' => $iterations,
                'elapsed_ms' => $result->elapsedMs,
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
            ]);

            // Wait for async guardrails to complete
            if ($outputGuardContext->hasAsyncGuardrails() && !$outputGuardContext->allCompleted()) {
                $outputGuardContext->await($asyncGuardrailTimeout);
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
                    elapsedMs: $this->elapsedMs($startTime),
                );
            }

            return $result;
        }

        // Tool calls — process each one
        $totalToolCalls += count($toolCalls);

        // Append assistant message with tool_calls
        $fullMessages[] = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];

        $this->processToolCalls($toolCalls, $fullMessages, $onEvent, (string) $iterations, true, $context);

        return null; // Tool calls processed — continue loop
    }

    /**
     * Process a batch of tool calls: emit events, dispatch, append results.
     * When enforceParallel is true, parallel tools are skipped if any non-parallel tool is present.
     */
    private function processToolCalls(
        array $toolCalls,
        array &$fullMessages,
        ?callable $onEvent,
        string $callIdPrefix,
        bool $enforceParallel,
        AgentRunContext $context,
    ): void {
        // Pre-check: detect non-parallel tools in the batch
        $hasNonParallel = false;
        if ($enforceParallel && count($toolCalls) > 1) {
            foreach ($toolCalls as $tc) {
                if (!$this->isToolParallelAllowed($tc['function']['name'] ?? '')) {
                    $hasNonParallel = true;
                    break;
                }
            }
        }

        foreach ($toolCalls as $i => $toolCall) {
            $callId = $toolCall['id'] ?? ($callIdPrefix . '_' . $i);
            $toolName = $toolCall['function']['name'] ?? '';
            $argumentsStr = $toolCall['function']['arguments'] ?? '{}';
            $arguments = json_decode($argumentsStr, true) ?? [];

            // Skip parallel tools when non-parallel tools are present in this batch
            if ($hasNonParallel && $this->isToolParallelAllowed($toolName)) {
                $fullMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => 'Tool call skipped: non-parallel tool detected in batch. Re-request if still needed.',
                ];
                continue;
            }

            $this->emitEvent($onEvent, AgentEventType::TOOL_CALL, [
                'call_id' => $callId,
                'name' => $toolName,
                'arguments' => $arguments,
            ]);

            $toolResult = $this->dispatchTool($toolName, $arguments, $context, $onEvent);

            $this->emitEvent($onEvent, AgentEventType::TOOL_RESULT, [
                'call_id' => $callId,
                'name' => $toolName,
                'result' => $toolResult,
                'success' => true,
            ]);

            $fullMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'content' => $toolResult,
            ];
        }
    }

    /**
     * Dispatch a tool call through the chain:
     * 1. Middleware beforeToolCall (interception)
     * 2. Agent-level handler
     * 3. ToolRegistry standard dispatch
     */
    private function dispatchTool(string $name, array $arguments, AgentRunContext $context, ?callable $onEvent = null): string
    {
        // Step 0: Tool guardrail — check input (can block or sanitize arguments)
        $inputCheck = $context->toolGuardrails->checkToolInput($name, $arguments);
        if ($inputCheck !== null && $inputCheck->blocked) {
            $this->emitEvent($onEvent, AgentEventType::TOOL_BLOCKED, [
                'name' => $name,
                'reason' => $inputCheck->reason,
            ]);
            return "工具调用被拦截 [{$name}]: {$inputCheck->reason}";
        }

        // Step 0.5: Permission check — deny/ask based on risk level
        $riskLevel = ToolRiskLevel::LOW;
        try {
            $tool = $this->toolRegistry->resolve($name);
            if ($tool instanceof RiskyToolInterface) {
                $riskLevel = $tool->riskLevel();
            }
        } catch (\InvalidArgumentException) {
            // Unknown tool — will be handled by ToolRegistry::execute()
        }

        $decision = $context->permissionPolicy->decide($name, $riskLevel, $arguments);
        if ($decision === ToolPermissionDecision::DENY) {
            $this->emitEvent($onEvent, AgentEventType::TOOL_DENIED, [
                'name' => $name,
                'reason' => 'Permission policy denied',
            ]);
            return "工具 [{$name}] 被权限策略拒绝";
        }
        if ($decision === ToolPermissionDecision::ASK) {
            if ($context->humanInputResolver === null || !$context->humanInputResolver->isBlocking()) {
                return "工具 [{$name}] 需要人工确认，但当前环境不支持交互式确认";
            }
            $approval = $context->humanInputResolver->ask(
                "工具 [{$name}] 请求执行权限（风险等级: {$riskLevel->value}）。是否允许？",
                [],
            );
            if (!($approval['confirmed'] ?? false)) {
                return "工具 [{$name}] 被用户拒绝执行";
            }
        }

        // Step 1: Middleware interception
        $intercepted = $this->middleware->beforeToolCall($name, $arguments);
        if ($intercepted !== null) {
            $this->middleware->afterToolCall($name, $arguments, $intercepted);
            return $intercepted;
        }

        // Step 2: Agent-level handler
        if (isset($context->agentToolHandlers[$name])) {
            try {
                $result = ($context->agentToolHandlers[$name])($arguments);
                $resultText = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string) $result;
            } catch (\Throwable $e) {
                $resultText = "工具执行错误 [{$name}]: " . $e->getMessage();
            }

            // Tool guardrail — check output
            $outputCheck = $context->toolGuardrails->checkToolOutput($name, $arguments, $resultText);
            if ($outputCheck !== null && $outputCheck->blocked) {
                $this->middleware->afterToolCall($name, $arguments, "工具输出被拦截: {$outputCheck->reason}");
                return "工具输出被拦截 [{$name}]: {$outputCheck->reason}";
            }

            $this->middleware->afterToolCall($name, $arguments, $resultText);
            return $resultText;
        }

        // Step 3: ToolRegistry dispatch
        // Inject resolver into AskTool if applicable
        if ($name === 'ask' && $context->humanInputResolver !== null) {
            try {
                $tool = $this->toolRegistry->resolve($name);
                if ($tool instanceof AskTool) {
                    $tool->setResolver($context->humanInputResolver);
                }
            } catch (\InvalidArgumentException) {
                // Tool not registered, fall through to execute() which handles the error
            }
        }

        $executionResult = $this->toolRegistry->execute($name, $arguments);
        $resultText = $executionResult->toText();

        // Tool guardrail — check output
        $outputCheck = $context->toolGuardrails->checkToolOutput($name, $arguments, $resultText);
        if ($outputCheck !== null && $outputCheck->blocked) {
            $this->middleware->afterToolCall($name, $arguments, "工具输出被拦截: {$outputCheck->reason}");
            return "工具输出被拦截 [{$name}]: {$outputCheck->reason}";
        }

        $this->middleware->afterToolCall($name, $arguments, $resultText);

        return $resultText;
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

    private function elapsedMs(int $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }

    /**
     * Check if a tool allows parallel execution.
     */
    private function isToolParallelAllowed(string $toolName): bool
    {
        try {
            $tool = $this->toolRegistry->resolve($toolName);
            return $tool->isParallelAllowed();
        } catch (\InvalidArgumentException) {
            return true; // Unknown tools default to parallel-allowed
        }
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
