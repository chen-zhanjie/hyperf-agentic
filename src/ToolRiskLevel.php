<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

enum ToolRiskLevel: string
{
    case LOW = 'low';           // Read-only, no side effects
    case MEDIUM = 'medium';     // Side effects but reversible
    case HIGH = 'high';         // Irreversible, external impact
    case CRITICAL = 'critical'; // System-level changes, must confirm
}
