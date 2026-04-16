<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

enum ToolPermissionDecision: string
{
    case ALLOW = 'allow';
    case DENY = 'deny';
    case ASK = 'ask';
}
