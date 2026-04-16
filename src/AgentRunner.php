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
 * Agent Runner — thin orchestrator for the agent conversation loop.
 *
 * Delegates turn execution to TurnExecutor and tool dispatch to ToolDispatcher.
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
    public function chatStream(array $messages, array $options, callable $onChunk): array
    {
        return $this->llmClient->chatStream($messages, $options, $onChunk);
    }

    /**
     * Resume a suspended agent session.
     *
     * @param array  $state     Suspended state (messages, remaining_iterations, agent_config, agent_name)
     * @param string $sessionId Session ID
     */
    public function resume(array $state, string $sessionId): AgentResult
    {
        $startTime = hrtime(true);

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

        $executor = $this->createTurnExecutor();

        $result = $this->runLoop($executor, $fullMessages, $systemMessage, $toolSchemas, [], null, $loop, $context, null, false);

        if ($result === null && $budget->consumeGrace() && !$costBudget->isExceeded()) {
            $result = $executor->execute(
                $fullMessages, $systemMessage, $toolSchemas,
                [], null, $loop, $context, false, true,
            );
        }

        if ($result === null) {
            return AgentResult::budgetExhausted($loop->iterations, $remainingIterations);
        }

        return $result;
    }

    /**
     * Execute the agent conversation loop (non-streaming).
     */
    public function run(
        array $messages,
        array $agentConfig = [],
        array $options = [],
        ?callable $onEvent = null,
    ): AgentResult {
        $ctx = $this->buildRunContext($messages, $agentConfig, $options);
        return $this->executeRun($ctx, $onEvent, stream: false);
    }

    /**
     * Execute a streaming agent run.
     */
    public function runStream(
        array $messages,
        array $agentConfig = [],
        array $options = [],
        ?callable $onEvent = null,
    ): AgentResult {
        $ctx = $this->buildRunContext($messages, $agentConfig, $options);
        return $this->executeRun($ctx, $onEvent, stream: true);
    }

    /**
     * Create a TurnExecutor instance.
     */
    private function createTurnExecutor(): TurnExecutor
    {
        return new TurnExecutor(
            llmClient: $this->llmClient,
            promptBuilder: $this->promptBuilder,
            middleware: $this->middleware,
            toolDispatcher: $this->toolDispatcher(),
        );
    }

    /**
     * Build the shared run context used by both run() and runStream().
     */
    private function buildRunContext(array $messages, array $agentConfig, array $options): array
    {
        $startTime = hrtime(true);
        $this->promptBuilder->reset();

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

        $inputGuardContext = $context->guardrails->checkInputAsync($messages);

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

        $messages = $this->middleware->beforeLoop($messages, $agentConfig);

        $fullMessages = array_merge(
            [['role' => 'system', 'content' => $systemMessage]],
            $messages,
        );

        return [
            'maxIterations' => $maxIterations,
            'budget' => $budget,
            'costBudget' => $costBudget,
            'loop' => $loop,
            'context' => $context,
            'inputGuardContext' => $inputGuardContext,
            'toolSchemas' => $toolSchemas,
            'systemMessage' => $systemMessage,
            'fullMessages' => $fullMessages,
            'options' => $options,
            'persona' => $setup['persona'],
            'model' => $options['model'] ?? $agentConfig['model'] ?? '',
        ];
    }

    /**
     * Execute the agent loop (sync or streaming).
     */
    private function executeRun(array $ctx, ?callable $onEvent, bool $stream): AgentResult
    {
        $loop = $ctx['loop'];
        $context = $ctx['context'];
        $inputGuardContext = $ctx['inputGuardContext'];
        $budget = $ctx['budget'];
        $costBudget = $ctx['costBudget'];
        $maxIterations = $ctx['maxIterations'];
        $fullMessages = $ctx['fullMessages'];
        $systemMessage = $ctx['systemMessage'];
        $toolSchemas = $ctx['toolSchemas'];
        $options = $ctx['options'];

        if ($inputGuardContext->isBlocked()) {
            $blockResult = $inputGuardContext->getBlockResult();
            $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_BLOCKED, [
                'type' => 'input',
                'name' => $inputGuardContext->getBlockName() ?? 'input_guard',
                'reason' => $blockResult->reason,
            ]);
            return AgentResult::guardrailBlocked('input', $blockResult->reason, $loop->elapsedMs());
        }

        $this->emitEvent($onEvent, AgentEventType::STARTED, [
            'agent' => $ctx['persona']->name ?? 'Assistant',
            'model' => $ctx['model'] ?? '',
        ]);

        $executor = $this->createTurnExecutor();

        $result = $this->runLoop(
            $executor, $fullMessages, $systemMessage, $toolSchemas,
            $options, $onEvent, $loop, $context, $inputGuardContext, $stream,
        );

        if ($result === null && $budget->consumeGrace() && !$costBudget->isExceeded()) {
            $result = $executor->execute(
                $fullMessages, $systemMessage, $toolSchemas,
                $options, $onEvent, $loop, $context, $stream, true,
            );
        }

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
        TurnExecutor $executor,
        array &$fullMessages,
        string $systemMessage,
        array $toolSchemas,
        array $options,
        ?callable $onEvent,
        LoopState $loop,
        AgentRunContext $context,
        ?AsyncGuardrailContext $inputGuardContext = null,
        bool $stream = false,
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

            $turnResult = $executor->execute(
                $fullMessages, $systemMessage, $toolSchemas,
                $options, $onEvent, $loop, $context, $stream,
            );

            if ($turnResult !== null) {
                return $turnResult;
            }
            // Tool calls processed — continue loop
        }

        return null; // Budget exhausted
    }

    /**
     * Shared setup: resolve persona, tools, guardrails, permissions from agent config.
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

        return new Persona(
            name: 'Assistant',
            content: 'You are a helpful AI assistant.',
        );
    }

    /**
     * Emit event to both internal listeners and external callback.
     */
    private function emitEvent(?callable $onEvent, AgentEventType $type, array $payload = []): void
    {
        $this->emit($type, $payload);

        if ($onEvent !== null) {
            $onEvent($type->value, $payload);
        }
    }
}
