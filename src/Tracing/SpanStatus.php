<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tracing;

enum SpanStatus: string
{
    case OK = 'ok';
    case ERROR = 'error';
    case PENDING = 'pending';
}
