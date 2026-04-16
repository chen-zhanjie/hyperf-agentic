<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;

/**
 * In-memory approval store with fnmatch wildcard support and dual scope (global/session).
 */
class PermissionApprovalStore implements PermissionApprovalStoreInterface
{
    private bool $globalAllowAll = false;

    /** @var array<string, true> Pattern => true */
    private array $globalApproved = [];

    /** @var array<string, bool> SessionId => allowAll */
    private array $sessionAllowAll = [];

    /** @var array<string, array<string, true>> SessionId => [Pattern => true] */
    private array $sessionApproved = [];

    public function isApproved(string $toolName, ?string $sessionId = null): bool
    {
        // 1. Session-scoped checks (most specific first)
        if ($sessionId !== null) {
            // Session allow-all
            if ($this->sessionAllowAll[$sessionId] ?? false) {
                return true;
            }
            // Session pattern match
            foreach ($this->sessionApproved[$sessionId] ?? [] as $pattern => $_) {
                if (fnmatch($pattern, $toolName)) {
                    return true;
                }
            }
        }

        // 2. Global checks
        if ($this->globalAllowAll) {
            return true;
        }
        foreach ($this->globalApproved as $pattern => $_) {
            if (fnmatch($pattern, $toolName)) {
                return true;
            }
        }

        return false;
    }

    public function approve(string $toolOrPattern, ?string $sessionId = null): void
    {
        if ($sessionId !== null) {
            $this->sessionApproved[$sessionId][$toolOrPattern] = true;
        } else {
            $this->globalApproved[$toolOrPattern] = true;
        }
    }

    public function approveAll(?string $sessionId = null): void
    {
        if ($sessionId !== null) {
            $this->sessionAllowAll[$sessionId] = true;
        } else {
            $this->globalAllowAll = true;
        }
    }

    public function revoke(string $toolOrPattern, ?string $sessionId = null): void
    {
        if ($sessionId !== null) {
            unset($this->sessionApproved[$sessionId][$toolOrPattern]);
        } else {
            unset($this->globalApproved[$toolOrPattern]);
        }
    }

    public function revokeAll(?string $sessionId = null): void
    {
        if ($sessionId !== null) {
            unset($this->sessionAllowAll[$sessionId]);
            unset($this->sessionApproved[$sessionId]);
        } else {
            $this->globalAllowAll = false;
            $this->globalApproved = [];
        }
    }

    public function gcSessions(): void
    {
        $this->sessionAllowAll = [];
        $this->sessionApproved = [];
    }
}
