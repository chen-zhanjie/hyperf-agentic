<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

enum GuardrailMode: string
{
    case SYNC = 'sync';
    case ASYNC = 'async';
}
