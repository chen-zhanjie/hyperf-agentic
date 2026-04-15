<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\CancellationToken;

class CancellationTokenTest extends TestCase
{
    public function testStartsNotCancelled(): void
    {
        $token = new CancellationToken();
        $this->assertFalse($token->isCancelled());
        $this->assertNull($token->getReason());
    }

    public function testCancelSetsCancelled(): void
    {
        $token = new CancellationToken();
        $token->cancel('guardrail blocked');

        $this->assertTrue($token->isCancelled());
        $this->assertSame('guardrail blocked', $token->getReason());
    }

    public function testCancelWithoutReason(): void
    {
        $token = new CancellationToken();
        $token->cancel();

        $this->assertTrue($token->isCancelled());
        $this->assertSame('', $token->getReason());
    }

    public function testWithTimeoutReturnsToken(): void
    {
        $token = CancellationToken::withTimeout(60000);

        $this->assertInstanceOf(CancellationToken::class, $token);
        $this->assertFalse($token->isCancelled());
    }

    public function testCancelIsIdempotent(): void
    {
        $token = new CancellationToken();
        $token->cancel('first');
        $token->cancel('second');

        $this->assertTrue($token->isCancelled());
        $this->assertSame('first', $token->getReason());
    }
}
