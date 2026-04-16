<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;

/**
 * Per-request context for AgentRunner.
 *
 * Replaces mutable instance properties on the singleton AgentRunner,
 * ensuring safe concurrent execution under Swoole coroutines.
 */
class AgentRunContext
{
    /**
     * @param GuardrailRunner $guardrails Per-agent filtered guardrails
     * @param ToolGuardrailRunner $toolGuardrails Tool-level guardrails
     * @param ToolPermissionPolicyInterface $permissionPolicy Tool permission policy (rebuilt per-request)
     * @param PermissionApprovalStoreInterface|null $approvalStore Runtime approval cache
     * @param HumanInputResolverInterface|null $humanInputResolver Injected into AskTool at dispatch time
     * @param array<string, callable> $agentToolHandlers Agent-level tool handlers
     * @param CancellationToken|null $cancellationToken Cooperative cancellation signal
     * @param string|null $sessionId Session/conversation ID for approval scoping
     */
    public function __construct(
        public readonly GuardrailRunner $guardrails,
        public readonly ToolGuardrailRunner $toolGuardrails,
        public readonly ToolPermissionPolicyInterface $permissionPolicy,
        public readonly ?PermissionApprovalStoreInterface $approvalStore = null,
        public readonly ?HumanInputResolverInterface $humanInputResolver = null,
        public readonly array $agentToolHandlers = [],
        public readonly ?CancellationToken $cancellationToken = null,
        public readonly ?string $sessionId = null,
    ) {}

    public function isCancelled(): bool
    {
        return $this->cancellationToken?->isCancelled() ?? false;
    }
}
