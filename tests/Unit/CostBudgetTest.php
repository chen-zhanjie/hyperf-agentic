<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\CostBudget;

class CostBudgetTest extends TestCase
{
    public function testInitialStateIsNotExceeded(): void
    {
        $budget = new CostBudget(maxTotalTokens: 1000);
        $this->assertFalse($budget->isExceeded());
        $this->assertFalse($budget->isNearLimit());
        $this->assertSame(1000, $budget->remaining());
    }

    public function testConsumeTracksTokens(): void
    {
        $budget = new CostBudget(maxTotalTokens: 1000);
        $budget->consume(promptTokens: 100, completionTokens: 50);
        $this->assertSame(150, $budget->getTotalUsed());
        $this->assertSame(850, $budget->remaining());
    }

    public function testAccumulateTokensAcrossCalls(): void
    {
        $budget = new CostBudget(maxTotalTokens: 1000);
        $budget->consume(promptTokens: 300, completionTokens: 200);
        $budget->consume(promptTokens: 300, completionTokens: 200);
        $this->assertSame(1000, $budget->getTotalUsed());
        $this->assertTrue($budget->isExceeded());
    }

    public function testIsNearLimitAtDefault80Percent(): void
    {
        $budget = new CostBudget(maxTotalTokens: 1000);
        $budget->consume(promptTokens: 400, completionTokens: 400);
        // 800/1000 = 80% → near limit
        $this->assertTrue($budget->isNearLimit());
        $this->assertFalse($budget->isExceeded());
    }

    public function testCustomWarnRatio(): void
    {
        $budget = new CostBudget(maxTotalTokens: 1000, warnAtRatio: 0.5);
        $budget->consume(promptTokens: 300, completionTokens: 200);
        // 500/1000 = 50% → at custom warn threshold
        $this->assertTrue($budget->isNearLimit());
    }

    public function testGetUsageReturnsCompleteSnapshot(): void
    {
        $budget = new CostBudget(maxTotalTokens: 500);
        $budget->consume(promptTokens: 100, completionTokens: 50);
        $usage = $budget->getUsage();
        $this->assertSame(100, $usage['prompt_tokens']);
        $this->assertSame(50, $usage['completion_tokens']);
        $this->assertSame(150, $usage['total_used']);
        $this->assertSame(500, $usage['max_total']);
        $this->assertSame(350, $usage['remaining']);
    }

    public function testRemainingClampsToZero(): void
    {
        $budget = new CostBudget(maxTotalTokens: 100);
        $budget->consume(promptTokens: 200, completionTokens: 200);
        $this->assertSame(0, $budget->remaining());
    }
}
