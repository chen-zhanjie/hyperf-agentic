<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\NullMemoryProvider;

class NullMemoryProviderTest extends TestCase
{
    public function testIsAvailableReturnsFalse(): void
    {
        $provider = new NullMemoryProvider();
        $this->assertFalse($provider->isAvailable());
    }

    public function testPrefetchReturnsNull(): void
    {
        $provider = new NullMemoryProvider();
        $this->assertNull($provider->prefetch('any message'));
    }

    public function testAllMethodsRunWithoutError(): void
    {
        $provider = new NullMemoryProvider();
        $provider->initialize('session-1');
        $provider->syncTurn('user msg', 'assistant msg');
        $provider->onSessionEnd();
        $this->assertTrue(true);
    }
}
