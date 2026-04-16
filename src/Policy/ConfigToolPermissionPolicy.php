<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Policy;

use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\PermissionMode;
use ChenZhanjie\Agentic\ToolPermissionDecision;
use ChenZhanjie\Agentic\ToolRiskLevel;

/**
 * Config-driven tool permission policy with permission mode support.
 *
 * Rules format: ['allow' => [...], 'ask' => [...], 'deny' => [...]]
 * Patterns support * wildcard (e.g., 'delete_*', 'exec_*').
 *
 * Priority: deny > allow > ask > mode default.
 * The mode determines default behavior when no explicit rules match.
 */
class ConfigToolPermissionPolicy implements ToolPermissionPolicyInterface
{
    /**
     * @param array{allow?: string[], ask?: string[], deny?: string[]} $rules
     * @param ToolRiskLevel $defaultAskThreshold Risk level at which unlisted tools require approval
     * @param PermissionMode $mode Permission mode for default behavior
     */
    public function __construct(
        private readonly array $rules = [],
        private readonly ToolRiskLevel $defaultAskThreshold = ToolRiskLevel::HIGH,
        private readonly PermissionMode $mode = PermissionMode::DEFAULT,
    ) {}

    public function decide(string $toolName, ToolRiskLevel $riskLevel, array $arguments): ToolPermissionDecision
    {
        // Step 1: Explicit deny — always enforced regardless of mode
        if ($this->matchesAny($toolName, $this->rules['deny'] ?? [])) {
            return ToolPermissionDecision::DENY;
        }

        // Step 2: Explicit allow
        if ($this->matchesAny($toolName, $this->rules['allow'] ?? [])) {
            return ToolPermissionDecision::ALLOW;
        }

        // Step 3: Explicit ask
        if ($this->matchesAny($toolName, $this->rules['ask'] ?? [])) {
            return ToolPermissionDecision::ASK;
        }

        // Step 4: No rules matched — mode determines default behavior
        return match ($this->mode) {
            PermissionMode::AUTO => ToolPermissionDecision::ALLOW,
            PermissionMode::STRICT => ToolPermissionDecision::ASK,
            PermissionMode::READONLY => $riskLevel === ToolRiskLevel::LOW
                ? ToolPermissionDecision::ALLOW
                : ToolPermissionDecision::DENY,
            PermissionMode::DEFAULT => $this->riskLevelAtOrAbove($riskLevel, $this->defaultAskThreshold)
                ? ToolPermissionDecision::ASK
                : ToolPermissionDecision::ALLOW,
        };
    }

    /**
     * Check if a tool name matches any pattern in the list.
     * Supports * wildcard for prefix matching.
     */
    private function matchesAny(string $toolName, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($toolName, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function matches(string $toolName, string $pattern): bool
    {
        return fnmatch($pattern, $toolName);
    }

    /**
     * Check if risk level is at or above the threshold.
     */
    private function riskLevelAtOrAbove(ToolRiskLevel $level, ToolRiskLevel $threshold): bool
    {
        return $this->riskScore($level) >= $this->riskScore($threshold);
    }

    private function riskScore(ToolRiskLevel $level): int
    {
        return match ($level) {
            ToolRiskLevel::LOW => 0,
            ToolRiskLevel::MEDIUM => 1,
            ToolRiskLevel::HIGH => 2,
            ToolRiskLevel::CRITICAL => 3,
        };
    }
}
