<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\ToolPermissionDecision;
use ChenZhanjie\Agentic\ToolRiskLevel;

interface ToolPermissionPolicyInterface
{
    public function decide(string $toolName, ToolRiskLevel $riskLevel, array $arguments): ToolPermissionDecision;
}
