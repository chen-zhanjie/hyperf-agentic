<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Permission mode determines default behavior when no explicit rules match a tool.
 *
 * Inspired by Claude Code's permission modes, adapted for SDK context.
 * The mode only affects the fallback decision — explicit deny rules always apply.
 */
enum PermissionMode: string
{
    /** Config-driven: deny > allow > ask rules, then risk threshold decides */
    case DEFAULT = 'default';

    /** Trust all tools — no approval needed (unattended, CI/CD, auto agents) */
    case AUTO = 'auto';

    /** Every tool call requires user confirmation */
    case STRICT = 'strict';

    /** Only LOW risk tools allowed, everything else denied */
    case READONLY = 'readonly';
}
