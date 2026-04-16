<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * User's approval choice when the framework prompts for tool permission.
 *
 * Inspired by Claude Code's "Yes / Yes, don't ask again for X / No" pattern.
 * This is a framework-level concern — the AI does not participate in this decision.
 */
enum ApprovalChoice: string
{
    /** Approve this invocation only */
    case ONCE = 'once';

    /** Approve all future calls to this tool (recorded in approval store) */
    case TOOL = 'tool';

    /** Approve all tools for the current session (recorded in approval store) */
    case SESSION = 'session';

    /** Deny the tool execution */
    case DENY = 'deny';
}
