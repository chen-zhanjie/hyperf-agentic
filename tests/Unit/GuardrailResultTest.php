<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\GuardrailResult;

class GuardrailResultTest extends TestCase
{
    public function testOkReturnsPassingResult(): void
    {
        $result = GuardrailResult::ok();
        $this->assertFalse($result->tripwire);
        $this->assertSame('', $result->reason);
    }

    public function testBlockedReturnsTripwireResult(): void
    {
        $result = GuardrailResult::blocked('PII detected');
        $this->assertTrue($result->tripwire);
        $this->assertSame('PII detected', $result->reason);
    }
}
