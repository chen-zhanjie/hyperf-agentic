<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\GuardrailMode;

class GuardrailModeTest extends TestCase
{
    public function testSyncMode(): void
    {
        $mode = GuardrailMode::SYNC;
        $this->assertSame('sync', $mode->value);
    }

    public function testAsyncMode(): void
    {
        $mode = GuardrailMode::ASYNC;
        $this->assertSame('async', $mode->value);
    }

    public function testFromSyncString(): void
    {
        $mode = GuardrailMode::from('sync');
        $this->assertSame(GuardrailMode::SYNC, $mode);
    }

    public function testFromAsyncString(): void
    {
        $mode = GuardrailMode::from('async');
        $this->assertSame(GuardrailMode::ASYNC, $mode);
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(GuardrailMode::tryFrom('invalid'));
    }
}
