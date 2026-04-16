<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\ToolRiskLevel;

/**
 * Tools that declare a risk level and description.
 * Implementing this interface enables per-tool permission checks.
 */
interface RiskyToolInterface extends ToolInterface
{
    public function riskLevel(): ToolRiskLevel;

    public function riskDescription(): string;
}
