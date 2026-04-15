<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AsyncGuardrailContext;
use ChenZhanjie\Agentic\AsyncGuardrailHandle;
use ChenZhanjie\Agentic\GuardrailResult;

class AsyncGuardrailContextTest extends TestCase
{
    public function testEmptyContextIsNotBlocked(): void
    {
        $ctx = new AsyncGuardrailContext('input');
        $this->assertFalse($ctx->isBlocked());
        $this->assertNull($ctx->getBlockResult());
        $this->assertNull($ctx->getBlockName());
        $this->assertTrue($ctx->allCompleted());
        $this->assertFalse($ctx->hasAsyncGuardrails());
    }

    public function testSyncBlockResult(): void
    {
        $ctx = new AsyncGuardrailContext('input');
        $blocked = GuardrailResult::blocked('unsafe');
        $ctx->setSyncResult($blocked);

        $this->assertTrue($ctx->isBlocked());
        $this->assertSame($blocked, $ctx->getBlockResult());
    }

    public function testSyncPassResultDoesNotBlock(): void
    {
        $ctx = new AsyncGuardrailContext('output');
        $ctx->setSyncResult(GuardrailResult::ok());

        // ok() result with tripwire=false doesn't block
        // setSyncResult with non-tripwire result should not block
        $this->assertFalse($ctx->isBlocked());
    }

    public function testAsyncHandleBlocksWhenCompleted(): void
    {
        $ctx = new AsyncGuardrailContext('output');
        $handle = new AsyncGuardrailHandle();
        $ctx->addHandle($handle, 'toxicity_detector');

        $this->assertTrue($ctx->hasAsyncGuardrails());
        $this->assertFalse($ctx->allCompleted());
        $this->assertFalse($ctx->isBlocked());

        $handle->complete(GuardrailResult::blocked('toxic'));

        $this->assertTrue($ctx->allCompleted());
        $this->assertTrue($ctx->isBlocked());
        $this->assertSame('toxicity_detector', $ctx->getBlockName());
    }

    public function testAsyncHandlePassesWhenCompletedWithNull(): void
    {
        $ctx = new AsyncGuardrailContext('output');
        $handle = new AsyncGuardrailHandle();
        $ctx->addHandle($handle, 'pii_filter');

        $handle->complete(null);

        $this->assertTrue($ctx->allCompleted());
        $this->assertFalse($ctx->isBlocked());
    }

    public function testMultipleHandlesFirstBlockedWins(): void
    {
        $ctx = new AsyncGuardrailContext('output');
        $handle1 = new AsyncGuardrailHandle();
        $handle2 = new AsyncGuardrailHandle();
        $ctx->addHandle($handle1, 'first');
        $ctx->addHandle($handle2, 'second');

        $handle1->complete(GuardrailResult::blocked('blocked first'));
        $handle2->complete(null);

        $this->assertTrue($ctx->isBlocked());
        $this->assertSame('first', $ctx->getBlockName());
        $this->assertSame('blocked first', $ctx->getBlockResult()->reason);
    }

    public function testSyncBlockTakesPrecedenceOverAsync(): void
    {
        $ctx = new AsyncGuardrailContext('input');
        $ctx->setSyncResult(GuardrailResult::blocked('sync blocked'));

        $handle = new AsyncGuardrailHandle();
        $ctx->addHandle($handle, 'async_guard');

        $this->assertTrue($ctx->isBlocked());
        $this->assertSame('sync blocked', $ctx->getBlockResult()->reason);
    }

    public function testPhaseIsStored(): void
    {
        $ctx = new AsyncGuardrailContext('input');
        $this->assertSame('input', $ctx->phase);
    }
}
