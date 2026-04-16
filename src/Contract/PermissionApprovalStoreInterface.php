<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

/**
 * Runtime approval store for tool permission decisions.
 *
 * Caches user "don't ask again" decisions with two scopes:
 * - Global: persists across all sessions (process lifetime)
 * - Session: scoped to a specific session/conversation_id
 *
 * Inspired by Claude Code's "Yes, and don't ask again for X" pattern.
 */
interface PermissionApprovalStoreInterface
{
    /**
     * Check if a tool is approved (supports wildcard pattern matching).
     *
     * Check order: session patterns → session allow-all → global patterns → global allow-all.
     */
    public function isApproved(string $toolName, ?string $sessionId = null): bool;

    /**
     * Approve a tool or pattern (e.g., 'delete_*') for a scope.
     * Null sessionId = global, otherwise session-scoped.
     */
    public function approve(string $toolOrPattern, ?string $sessionId = null): void;

    /**
     * Approve all tools for a scope.
     */
    public function approveAll(?string $sessionId = null): void;

    /**
     * Revoke a specific approval.
     */
    public function revoke(string $toolOrPattern, ?string $sessionId = null): void;

    /**
     * Revoke all approvals for a scope.
     * Null sessionId = revoke global, otherwise revoke session.
     */
    public function revokeAll(?string $sessionId = null): void;

    /**
     * Clear all session-scoped data to reclaim memory.
     * Call periodically in long-running workers (e.g., via Swoole timer).
     */
    public function gcSessions(): void;
}
