<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\RiskyToolInterface;
use ChenZhanjie\Agentic\Event\AgentEventType;
use ChenZhanjie\Agentic\Event\EventEmitter;
use ChenZhanjie\Agentic\Support\ApprovalPrompts;
use ChenZhanjie\Agentic\Tool\Builtin\AskTool;

/**
 * Tool dispatch chain — guardrails, permission checks, execution.
 *
 * Extracted from AgentRunner to keep concerns separated.
 * Uses its own EventEmitter trait for tool-related events.
 */
class ToolDispatcher
{
    use EventEmitter;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly MiddlewarePipeline $middleware,
        private readonly Contract\ToolPermissionPolicyInterface $permissionPolicy,
    ) {}

    /**
     * Process a batch of tool calls: emit events, dispatch, append results.
     * When enforceParallel is true, parallel tools are skipped if any non-parallel tool is present.
     */
    public function processToolCalls(
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

            $toolResult = $this->dispatch($toolName, $arguments, $context, $onEvent);

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
     * 1. Tool guardrail input check
     * 2. Approval store bypass
     * 3. Permission policy check (deny/ask/allow)
     * 4. Human approval prompt (if ASK)
     * 5. Execute via middleware → agent handler → ToolRegistry
     */
    public function dispatch(string $name, array $arguments, AgentRunContext $context, ?callable $onEvent = null): string
    {
        // Step 0: Tool guardrail — check input (can block or sanitize arguments)
        $inputCheck = $context->toolGuardrails->checkToolInput($name, $arguments);
        if ($inputCheck !== null && $inputCheck->blocked) {
            $this->emitEvent($onEvent, AgentEventType::TOOL_BLOCKED, [
                'name' => $name,
                'reason' => $inputCheck->reason,
            ]);
            return ApprovalPrompts::bind(ApprovalPrompts::$toolBlocked, ['tool' => $name, 'reason' => $inputCheck->reason]);
        }

        // Step 0.5a: Check approval store — pre-approved tools bypass policy check
        if ($context->approvalStore !== null && $context->approvalStore->isApproved($name, $context->sessionId)) {
            $this->emitEvent($onEvent, AgentEventType::TOOL_AUTO_APPROVED, [
                'name' => $name,
                'source' => 'approval_store',
            ]);
            return $this->execute($name, $arguments, $context, $onEvent);
        }

        // Step 0.5b: Permission check — deny/ask based on risk level
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
            return ApprovalPrompts::bind(ApprovalPrompts::$permissionDenied, ['tool' => $name]);
        }
        if ($decision === ToolPermissionDecision::ASK) {
            if ($context->humanInputResolver === null || !$context->humanInputResolver->isBlocking()) {
                return ApprovalPrompts::bind(ApprovalPrompts::$confirmationRequired, ['tool' => $name]);
            }

            // Build choices for Claude Code-style approval prompt
            $choices = [
                ['label' => ApprovalPrompts::$choiceOnce, 'value' => ApprovalChoice::ONCE->value],
                ['label' => ApprovalPrompts::bind(ApprovalPrompts::$choiceTool, ['tool' => $name]), 'value' => ApprovalChoice::TOOL->value],
            ];
            if ($context->sessionId !== null) {
                $choices[] = ['label' => ApprovalPrompts::$choiceSession, 'value' => ApprovalChoice::SESSION->value];
            }

            $approval = $context->humanInputResolver->ask(
                ApprovalPrompts::bind(ApprovalPrompts::$approvalPrompt, [
                    'tool' => $name,
                    'risk' => $riskLevel->value,
                    'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                ]),
                $choices,
            );

            $choiceValue = $approval['values']['choice'] ?? $approval['choice'] ?? 'deny';
            $choice = ApprovalChoice::tryFrom($choiceValue) ?? ApprovalChoice::DENY;

            if ($choice === ApprovalChoice::DENY || !($approval['confirmed'] ?? false)) {
                return ApprovalPrompts::bind(ApprovalPrompts::$userDenied, ['tool' => $name]);
            }

            // Record approval based on choice
            if ($context->approvalStore !== null) {
                if ($choice === ApprovalChoice::TOOL) {
                    $context->approvalStore->approve($name, $context->sessionId);
                } elseif ($choice === ApprovalChoice::SESSION && $context->sessionId !== null) {
                    $context->approvalStore->approveAll($context->sessionId);
                }
            }
            // ONCE: don't record, just proceed
        }

        // Step 1+: Execute the tool
        return $this->execute($name, $arguments, $context, $onEvent);
    }

    /**
     * Execute a tool after all permission checks have passed.
     * Handles middleware interception, agent handlers, and ToolRegistry dispatch.
     */
    private function execute(string $name, array $arguments, AgentRunContext $context, ?callable $onEvent = null): string
    {
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
                $this->middleware->afterToolCall($name, $arguments, ApprovalPrompts::bind(ApprovalPrompts::$outputBlocked, ['tool' => $name, 'reason' => $outputCheck->reason]));
                return ApprovalPrompts::bind(ApprovalPrompts::$outputBlocked, ['tool' => $name, 'reason' => $outputCheck->reason]);
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
            $this->middleware->afterToolCall($name, $arguments, ApprovalPrompts::bind(ApprovalPrompts::$outputBlocked, ['tool' => $name, 'reason' => $outputCheck->reason]));
            return ApprovalPrompts::bind(ApprovalPrompts::$outputBlocked, ['tool' => $name, 'reason' => $outputCheck->reason]);
        }

        $this->middleware->afterToolCall($name, $arguments, $resultText);

        return $resultText;
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

    private function emitEvent(?callable $onEvent, AgentEventType $type, array $payload): void
    {
        $this->emit($type, $payload);
        if ($onEvent !== null) {
            $onEvent($type->value, $payload);
        }
    }
}
