<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Event;

enum AgentEventType: string
{
    case STARTED = 'started';
    case COMPLETE = 'complete';
    case ERROR = 'error';
    case BUDGET_EXCEEDED = 'budget_exceeded';

    case TEXT_DELTA = 'text_delta';
    case THINKING = 'thinking';
    case TOOL_CALL = 'tool_call';
    case TOOL_RESULT = 'tool_result';
    case TOOL_ERROR = 'tool_error';
    case PROVIDER_SWITCH = 'provider_switch';

    case ASK_USER = 'ask_user';
    case FRONTEND_TOOL = 'frontend_tool';
    case SUSPENDED = 'suspended';
    case RESUMED = 'resumed';

    case GUARDRAIL_BLOCKED = 'guardrail_blocked';
    case GUARDRAIL_RECALLED = 'guardrail_recalled';
    case MESSAGE_RECALLED = 'message_recalled';
}
