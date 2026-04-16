<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\PermissionApprovalStore;
use PHPUnit\Framework\TestCase;

class PermissionApprovalStoreTest extends TestCase
{
    private PermissionApprovalStore $store;

    protected function setUp(): void
    {
        $this->store = new PermissionApprovalStore();
    }

    // --- Global scope ---

    public function test_isApproved_returns_false_by_default(): void
    {
        $this->assertFalse($this->store->isApproved('any_tool'));
    }

    public function test_approve_single_tool_globally(): void
    {
        $this->store->approve('search');
        $this->assertTrue($this->store->isApproved('search'));
        $this->assertFalse($this->store->isApproved('delete'));
    }

    public function test_approve_pattern_globally(): void
    {
        $this->store->approve('delete_*');
        $this->assertTrue($this->store->isApproved('delete_database'));
        $this->assertTrue($this->store->isApproved('delete_logs'));
        $this->assertFalse($this->store->isApproved('search'));
    }

    public function test_approve_all_globally(): void
    {
        $this->store->approveAll();
        $this->assertTrue($this->store->isApproved('any_tool'));
        $this->assertTrue($this->store->isApproved('delete_database'));
    }

    public function test_revoke_specific_tool_globally(): void
    {
        $this->store->approve('search');
        $this->assertTrue($this->store->isApproved('search'));

        $this->store->revoke('search');
        $this->assertFalse($this->store->isApproved('search'));
    }

    public function test_revoke_all_globally(): void
    {
        $this->store->approve('search');
        $this->store->approve('delete');
        $this->store->approveAll();

        $this->store->revokeAll();

        $this->assertFalse($this->store->isApproved('search'));
        $this->assertFalse($this->store->isApproved('delete'));
        $this->assertFalse($this->store->isApproved('any_tool'));
    }

    // --- Session scope ---

    public function test_approve_tool_per_session(): void
    {
        $this->store->approve('search', 'session-1');
        $this->assertTrue($this->store->isApproved('search', 'session-1'));
        $this->assertFalse($this->store->isApproved('search'));          // not global
        $this->assertFalse($this->store->isApproved('search', 'session-2')); // different session
    }

    public function test_approve_pattern_per_session(): void
    {
        $this->store->approve('delete_*', 'session-1');
        $this->assertTrue($this->store->isApproved('delete_database', 'session-1'));
        $this->assertFalse($this->store->isApproved('delete_database'));
        $this->assertFalse($this->store->isApproved('delete_database', 'session-2'));
    }

    public function test_approve_all_per_session(): void
    {
        $this->store->approveAll('session-1');
        $this->assertTrue($this->store->isApproved('any_tool', 'session-1'));
        $this->assertFalse($this->store->isApproved('any_tool'));          // not global
        $this->assertFalse($this->store->isApproved('any_tool', 'session-2'));
    }

    public function test_revoke_tool_per_session(): void
    {
        $this->store->approve('search', 'session-1');
        $this->store->revoke('search', 'session-1');
        $this->assertFalse($this->store->isApproved('search', 'session-1'));
    }

    public function test_revoke_all_per_session(): void
    {
        $this->store->approve('search', 'session-1');
        $this->store->approveAll('session-1');
        $this->store->approve('global_tool'); // global approval

        $this->store->revokeAll('session-1');

        // Session approvals cleared
        $this->assertFalse($this->store->isApproved('search', 'session-1'));
        // Global approvals still intact
        $this->assertTrue($this->store->isApproved('global_tool'));
    }

    // --- Priority: session > global ---

    public function test_global_approval_applies_to_all_sessions(): void
    {
        $this->store->approve('search'); // global approval
        $this->assertTrue($this->store->isApproved('search', 'session-1')); // session inherits global
        $this->assertTrue($this->store->isApproved('search')); // global works
        $this->assertTrue($this->store->isApproved('search', 'session-2')); // other sessions too
    }

    public function test_session_revoke_does_not_block_global(): void
    {
        $this->store->approve('search'); // global approval
        $this->store->approve('search', 'session-1'); // session approval too

        $this->store->revoke('search', 'session-1');
        // Session approval removed, but global still applies
        $this->assertTrue($this->store->isApproved('search', 'session-1'));
        $this->assertTrue($this->store->isApproved('search')); // global still works
    }

    public function test_session_allow_all_does_not_affect_global(): void
    {
        $this->store->approveAll('session-1');
        $this->assertFalse($this->store->isApproved('any_tool')); // global not affected
        $this->assertTrue($this->store->isApproved('any_tool', 'session-1'));
    }

    // --- Edge cases ---

    public function test_revoke_nonexistent_does_nothing(): void
    {
        $this->store->revoke('nonexistent');
        $this->assertFalse($this->store->isApproved('nonexistent'));
    }

    public function test_revoke_nonexistent_session_does_nothing(): void
    {
        $this->store->revoke('search', 'nonexistent-session');
        $this->assertFalse($this->store->isApproved('search', 'nonexistent-session'));
    }

    public function test_wildcard_asterisk_matches_everything(): void
    {
        $this->store->approve('*');
        $this->assertTrue($this->store->isApproved('anything'));
        $this->assertTrue($this->store->isApproved('delete_database'));
    }

    public function test_multiple_patterns(): void
    {
        $this->store->approve('delete_*');
        $this->store->approve('exec_*');
        $this->store->approve('search');

        $this->assertTrue($this->store->isApproved('delete_database'));
        $this->assertTrue($this->store->isApproved('exec_shell'));
        $this->assertTrue($this->store->isApproved('search'));
        $this->assertFalse($this->store->isApproved('update'));
    }

    // --- gcSessions ---

    public function test_gc_sessions_clears_all_session_data_but_keeps_global(): void
    {
        $this->store->approve('global_tool');
        $this->store->approve('search', 'session-1');
        $this->store->approveAll('session-2');

        $this->store->gcSessions();

        // Session data cleared
        $this->assertFalse($this->store->isApproved('search', 'session-1'));
        $this->assertFalse($this->store->isApproved('any_tool', 'session-2'));

        // Global data preserved
        $this->assertTrue($this->store->isApproved('global_tool'));
    }

    public function test_gc_sessions_with_no_session_data_is_noop(): void
    {
        $this->store->approve('global_tool');
        $this->store->approveAll();

        $this->store->gcSessions();

        // Global data still intact
        $this->assertTrue($this->store->isApproved('global_tool'));
        $this->assertTrue($this->store->isApproved('any_tool'));
    }
}
