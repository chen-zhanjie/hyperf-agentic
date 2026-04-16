<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Policy;

use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\ToolPermissionDecision;
use ChenZhanjie\Agentic\ToolRiskLevel;

/**
 * Config-driven tool permission policy.
 *
 * Rules format: ['allow' => [...], 'ask' => [...], 'deny' => [...]]
 * Patterns support * wildcard (e.g., 'delete_*', 'exec_*').
 *
 * Priority: deny > ask > allow > default threshold.
 */
class ConfigToolPermissionPolicy implements ToolPermissionPolicyInterface
{
    /**
     * @param array{allow?: string[], ask?: string[], deny?: string[]} $rules
     * @param ToolRiskLevel $defaultAskThreshold Risk level at which unlisted tools require approval
     */
    public function __construct(
        private readonly array $rules = [],
        private readonly ToolRiskLevel $defaultAskThreshold = ToolRiskLevel::HIGH,
    ) {}

    public function decide(string $toolName, ToolRiskLevel $riskLevel, array $arguments): ToolPermissionDecision
    {
        // Priority 1: deny rules
        $denyRules = $this->rules['deny'] ?? [];
        if ($this->matchesAny($toolName, $denyRules)) {
            return ToolPermissionDecision::DENY;
        }

        // Priority 2: ask rules
        $askRules = $this->rules['ask'] ?? [];
        if ($this->matchesAny($toolName, $askRules)) {
            return ToolPermissionDecision::ASK;
        }

        // Priority 3: allow rules
        $allowRules = $this->rules['allow'] ?? [];
        if ($this->matchesAny($toolName, $allowRules)) {
            return ToolPermissionDecision::ALLOW;
        }

        // Default: check risk level against threshold
        if ($this->riskLevelAtOrAbove($riskLevel, $this->defaultAskThreshold)) {
            return ToolPermissionDecision::ASK;
        }

        return ToolPermissionDecision::ALLOW;
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
