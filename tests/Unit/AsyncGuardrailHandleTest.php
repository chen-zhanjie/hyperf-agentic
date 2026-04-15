<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AsyncGuardrailHandle;
use ChenZhanjie\Agentic\GuardrailResult;

class AsyncGuardrailHandleTest extends TestCase
{
    public function testStartsNotCompleted(): void
    {
        $handle = new AsyncGuardrailHandle();
        $this->assertFalse($handle->isCompleted());
        $this->assertNull($handle->getResult());
        $this->assertFalse($handle->isBlocked());
    }

    public function testCompleteWithPassingResult(): void
    {
        $handle = new AsyncGuardrailHandle();
        $handle->complete(null);

        $this->assertTrue($handle->isCompleted());
        $this->assertNull($handle->getResult());
        $this->assertFalse($handle->isBlocked());
    }

    public function testCompleteWithBlockedResult(): void
    {
        $handle = new AsyncGuardrailHandle();
        $blocked = GuardrailResult::blocked('toxic content');
        $handle->complete($blocked);

        $this->assertTrue($handle->isCompleted());
        $this->assertSame($blocked, $handle->getResult());
        $this->assertTrue($handle->isBlocked());
    }

    public function testCompleteIsIdempotent(): void
    {
        $handle = new AsyncGuardrailHandle();
        $first = GuardrailResult::blocked('first');
        $handle->complete($first);
        $handle->complete(GuardrailResult::blocked('second'));

        $this->assertSame('first', $handle->getResult()->reason);
    }
}
