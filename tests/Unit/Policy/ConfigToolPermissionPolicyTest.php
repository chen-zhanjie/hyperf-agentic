<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\PermissionMode;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\ToolPermissionDecision;
use ChenZhanjie\Agentic\ToolRiskLevel;

class ConfigToolPermissionPolicyTest extends TestCase
{
    // ── default behavior (no rules) ──

    public function testDefaultAllowsLowRisk(): void
    {
        $policy = new ConfigToolPermissionPolicy();
        $decision = $policy->decide('search', ToolRiskLevel::LOW, []);

        $this->assertSame(ToolPermissionDecision::ALLOW, $decision);
    }

    public function testDefaultAllowsMediumRisk(): void
    {
        $policy = new ConfigToolPermissionPolicy();
        $decision = $policy->decide('update', ToolRiskLevel::MEDIUM, []);

        $this->assertSame(ToolPermissionDecision::ALLOW, $decision);
    }

    public function testDefaultAsksForHighRisk(): void
    {
        $policy = new ConfigToolPermissionPolicy();
        $decision = $policy->decide('delete', ToolRiskLevel::HIGH, []);

        $this->assertSame(ToolPermissionDecision::ASK, $decision);
    }

    public function testDefaultAsksForCriticalRisk(): void
    {
        $policy = new ConfigToolPermissionPolicy();
        $decision = $policy->decide('drop_database', ToolRiskLevel::CRITICAL, []);

        $this->assertSame(ToolPermissionDecision::ASK, $decision);
    }

    // ── custom threshold ──

    public function testCustomThresholdMedium(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            defaultAskThreshold: ToolRiskLevel::MEDIUM,
        );
        // MEDIUM >= MEDIUM → ask
        $decision = $policy->decide('update', ToolRiskLevel::MEDIUM, []);
        $this->assertSame(ToolPermissionDecision::ASK, $decision);

        // LOW < MEDIUM → allow
        $decision = $policy->decide('search', ToolRiskLevel::LOW, []);
        $this->assertSame(ToolPermissionDecision::ALLOW, $decision);
    }

    // ── explicit deny rules ──

    public function testDenyRuleOverridesDefault(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['exec_*']],
        );

        $decision = $policy->decide('exec_command', ToolRiskLevel::LOW, []);
        $this->assertSame(ToolPermissionDecision::DENY, $decision);
    }

    public function testDenyPatternMatching(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['delete_*']],
        );

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('delete_user', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('delete_all', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('search', ToolRiskLevel::LOW, []));
    }

    // ── explicit ask rules ──

    public function testAskRuleOverridesDefault(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['ask' => ['recall']],
        );

        // recall is LOW risk but explicitly set to ask
        $decision = $policy->decide('recall', ToolRiskLevel::LOW, []);
        $this->assertSame(ToolPermissionDecision::ASK, $decision);
    }

    // ── explicit allow rules ──

    public function testAllowRuleOverridesThreshold(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['allow' => ['delete_temp']],
        );

        // delete_temp is HIGH but explicitly allowed
        $decision = $policy->decide('delete_temp', ToolRiskLevel::HIGH, []);
        $this->assertSame(ToolPermissionDecision::ALLOW, $decision);
    }

    // ── priority: deny > allow > ask ──

    public function testDenyTakesPriorityOverAsk(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: [
                'deny' => ['delete_*'],
                'ask' => ['delete_*'],
            ],
        );

        $decision = $policy->decide('delete_user', ToolRiskLevel::HIGH, []);
        $this->assertSame(ToolPermissionDecision::DENY, $decision);
    }

    public function testDenyTakesPriorityOverAllow(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: [
                'deny' => ['delete_*'],
                'allow' => ['delete_*'],
            ],
        );

        $decision = $policy->decide('delete_user', ToolRiskLevel::LOW, []);
        $this->assertSame(ToolPermissionDecision::DENY, $decision);
    }

    public function testAllowTakesPriorityOverAsk(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: [
                'allow' => ['special_*'],
                'ask' => ['special_*'],
            ],
        );

        $decision = $policy->decide('special_action', ToolRiskLevel::LOW, []);
        $this->assertSame(ToolPermissionDecision::ALLOW, $decision);
    }

    // ── wildcard patterns ──

    public function testWildcardMatchesPrefix(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['allow' => ['search_*']],
        );

        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('search_users', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('search_docs', ToolRiskLevel::LOW, []));
    }

    // ── full config example ──

    public function testFullConfigExample(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: [
                'allow' => ['search_*', 'skill', 'ask'],
                'ask' => ['delete_*', 'recall'],
                'deny' => ['exec_*'],
            ],
            defaultAskThreshold: ToolRiskLevel::HIGH,
        );

        // Allow rules
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('search_users', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('skill', ToolRiskLevel::LOW, []));

        // Ask rules (even for LOW risk)
        $this->assertSame(ToolPermissionDecision::ASK, $policy->decide('recall', ToolRiskLevel::LOW, []));

        // Deny rules (highest priority)
        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('exec_command', ToolRiskLevel::LOW, []));

        // Default threshold for unlisted tools
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('create', ToolRiskLevel::MEDIUM, []));
        $this->assertSame(ToolPermissionDecision::ASK, $policy->decide('nuke', ToolRiskLevel::HIGH, []));
    }

    // ── M5: wildcard edge cases (fnmatch behavior) ──

    public function testWildcardMatchesSuffix(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['*_admin']],
        );

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('delete_admin', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('purge_admin', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('delete_user', ToolRiskLevel::LOW, []));
    }

    public function testWildcardInMiddle(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['db_*_force']],
        );

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('db_drop_force', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('db_drop', ToolRiskLevel::LOW, []));
    }

    public function testExactMatchWithoutWildcard(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['delete']],
        );

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('delete', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('delete_user', ToolRiskLevel::LOW, []));
    }

    // ── PermissionMode tests ──

    public function testAutoModeAllowsEverything(): void
    {
        $policy = new ConfigToolPermissionPolicy(mode: PermissionMode::AUTO);

        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('any_tool', ToolRiskLevel::CRITICAL, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('delete_all', ToolRiskLevel::HIGH, []));
    }

    public function testAutoModeStillRespectsDenyRules(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['exec_*']],
            mode: PermissionMode::AUTO,
        );

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('exec_shell', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('delete_all', ToolRiskLevel::CRITICAL, []));
    }

    public function testStrictModeAsksForEverything(): void
    {
        $policy = new ConfigToolPermissionPolicy(mode: PermissionMode::STRICT);

        $this->assertSame(ToolPermissionDecision::ASK, $policy->decide('search', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ASK, $policy->decide('delete', ToolRiskLevel::HIGH, []));
    }

    public function testStrictModeStillRespectsDenyRules(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['exec_*']],
            mode: PermissionMode::STRICT,
        );

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('exec_shell', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ASK, $policy->decide('search', ToolRiskLevel::LOW, []));
    }

    public function testStrictModeRespectsAllowRules(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['allow' => ['search']],
            mode: PermissionMode::STRICT,
        );

        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('search', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ASK, $policy->decide('other', ToolRiskLevel::LOW, []));
    }

    public function testReadonlyModeAllowsLowRisk(): void
    {
        $policy = new ConfigToolPermissionPolicy(mode: PermissionMode::READONLY);

        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('search', ToolRiskLevel::LOW, []));
    }

    public function testReadonlyModeDeniesHigherRisk(): void
    {
        $policy = new ConfigToolPermissionPolicy(mode: PermissionMode::READONLY);

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('update', ToolRiskLevel::MEDIUM, []));
        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('delete', ToolRiskLevel::HIGH, []));
        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('nuke', ToolRiskLevel::CRITICAL, []));
    }

    public function testReadonlyModeStillRespectsDenyRules(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['deny' => ['search_secret']],
            mode: PermissionMode::READONLY,
        );

        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('search_secret', ToolRiskLevel::LOW, []));
    }

    public function testReadonlyModeAllowOverridesDenyDefault(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: ['allow' => ['specific_update']],
            mode: PermissionMode::READONLY,
        );

        // Explicitly allowed even though it's MEDIUM risk
        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('specific_update', ToolRiskLevel::MEDIUM, []));
        // Other MEDIUM tools still denied
        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('other_update', ToolRiskLevel::MEDIUM, []));
    }

    public function testDefaultModeWithRules(): void
    {
        $policy = new ConfigToolPermissionPolicy(
            rules: [
                'allow' => ['search_*'],
                'ask' => ['delete_*'],
                'deny' => ['exec_*'],
            ],
            mode: PermissionMode::DEFAULT,
        );

        $this->assertSame(ToolPermissionDecision::ALLOW, $policy->decide('search_users', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::DENY, $policy->decide('exec_shell', ToolRiskLevel::LOW, []));
        $this->assertSame(ToolPermissionDecision::ASK, $policy->decide('delete_db', ToolRiskLevel::LOW, []));
    }
}
