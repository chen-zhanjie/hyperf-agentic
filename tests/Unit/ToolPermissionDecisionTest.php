<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\ToolPermissionDecision;

class ToolPermissionDecisionTest extends TestCase
{
    public function testDecisionValues(): void
    {
        $this->assertSame('allow', ToolPermissionDecision::ALLOW->value);
        $this->assertSame('deny', ToolPermissionDecision::DENY->value);
        $this->assertSame('ask', ToolPermissionDecision::ASK->value);
    }

    public function testDecisionFromValue(): void
    {
        $this->assertSame(ToolPermissionDecision::ALLOW, ToolPermissionDecision::from('allow'));
        $this->assertSame(ToolPermissionDecision::DENY, ToolPermissionDecision::from('deny'));
        $this->assertSame(ToolPermissionDecision::ASK, ToolPermissionDecision::from('ask'));
    }
}
