<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\IterationBudget;

class IterationBudgetTest extends TestCase
{
    public function testConsumeWithinBudget(): void
    {
        $budget = new IterationBudget(maxTotal: 5);
        $this->assertTrue($budget->consume());
        $this->assertSame(4, $budget->remaining());
        $this->assertFalse($budget->isExhausted());
    }

    public function testConsumeAtLimitReturnsFalse(): void
    {
        $budget = new IterationBudget(maxTotal: 2);
        $this->assertTrue($budget->consume());
        $this->assertTrue($budget->consume());
        $this->assertFalse($budget->consume()); // third call fails
        $this->assertTrue($budget->isExhausted());
        $this->assertSame(0, $budget->remaining());
    }

    public function testRefundRestoresOneSlot(): void
    {
        $budget = new IterationBudget(maxTotal: 2);
        $budget->consume();
        $budget->consume();
        $this->assertTrue($budget->isExhausted());

        $budget->refund();
        $this->assertFalse($budget->isExhausted());
        $this->assertSame(1, $budget->remaining());
    }

    public function testRefundDoesNotGoBelowZero(): void
    {
        $budget = new IterationBudget(maxTotal: 3);
        $budget->refund(); // refund with zero used
        $this->assertSame(3, $budget->remaining());
    }

    public function testRemainingNeverGoesNegative(): void
    {
        $budget = new IterationBudget(maxTotal: 1);
        $budget->consume();
        $budget->consume(); // already exhausted
        $this->assertSame(0, $budget->remaining());
    }

    public function testConsumeGraceOnlyOnce(): void
    {
        $budget = new IterationBudget(maxTotal: 3);
        $this->assertTrue($budget->consumeGrace());
        $this->assertTrue($budget->isGraceTurn());
        $this->assertFalse($budget->consumeGrace()); // second call fails
    }

    public function testUsedTracksConsumedIterations(): void
    {
        $budget = new IterationBudget(maxTotal: 10);
        $budget->consume();
        $budget->consume();
        $budget->consume();
        $this->assertSame(3, $budget->used());
    }

    public function testZeroBudgetIsImmediatelyExhausted(): void
    {
        $budget = new IterationBudget(maxTotal: 0);
        $this->assertTrue($budget->isExhausted());
        $this->assertFalse($budget->consume());
    }
}
