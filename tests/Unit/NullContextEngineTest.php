<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\NullContextEngine;

class NullContextEngineTest extends TestCase
{
    public function testNameReturnsNull(): void
    {
        $engine = new NullContextEngine();
        $this->assertSame('null', $engine->name());
    }

    public function testShouldCompressReturnsFalse(): void
    {
        $engine = new NullContextEngine();
        $this->assertFalse($engine->shouldCompress(['any', 'messages']));
    }

    public function testCompressReturnsMessagesUnchanged(): void
    {
        $engine = new NullContextEngine();
        $messages = [['role' => 'user', 'content' => 'hello']];
        $this->assertSame($messages, $engine->compress($messages));
    }

    public function testSessionHooksDoNotError(): void
    {
        $engine = new NullContextEngine();
        $engine->onSessionStart();
        $engine->onSessionEnd();
        $this->assertTrue(true);
    }

    public function testUpdateFromResponseDoesNotError(): void
    {
        $engine = new NullContextEngine();
        $engine->updateFromResponse(100, 50);
        $this->assertTrue(true);
    }
}
