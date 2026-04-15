<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Session\RedisSessionStore;

class RedisSessionStoreTest extends TestCase
{
    private \Redis $redis;
    private RedisSessionStore $store;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not loaded');
        }

        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RedisSessionStore($this->redis, 'agentic:session:', 3600);
    }

    public function testSetAndGetValue(): void
    {
        $this->redis->expects($this->once())
            ->method('setex')
            ->with('agentic:session:sess_1:key_a', 3600, $this->anything())
            ->willReturn(true);

        $this->redis->method('get')->with('agentic:session:sess_1:key_a')->willReturn('s:5:"hello";');

        $this->store->set('sess_1', 'key_a', 'hello');
        $result = $this->store->get('sess_1', 'key_a');
        $this->assertSame('hello', $result);
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $this->redis->method('get')->with('agentic:session:sess_1:missing')->willReturn(false);

        $result = $this->store->get('sess_1', 'missing', 'default_val');
        $this->assertSame('default_val', $result);
    }

    public function testDeleteRemovesKey(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with('agentic:session:sess_1:key_a')
            ->willReturn(1);

        $this->store->delete('sess_1', 'key_a');
    }

    public function testHasReturnsTrueWhenKeyExists(): void
    {
        $this->redis->method('exists')->with('agentic:session:sess_1:key_a')->willReturn(1);

        $this->assertTrue($this->store->has('sess_1', 'key_a'));
    }

    public function testHasReturnsFalseWhenKeyMissing(): void
    {
        $this->redis->method('exists')->with('agentic:session:sess_1:missing')->willReturn(0);

        $this->assertFalse($this->store->has('sess_1', 'missing'));
    }

    public function testSetTtlUpdatesExpiration(): void
    {
        $this->redis->expects($this->once())
            ->method('expire')
            ->with('agentic:session:sess_1:key_a', 7200)
            ->willReturn(true);

        // SCAN returns batch on first call, false on second (end iteration)
        $callCount = 0;
        $this->redis->method('scan')
            ->willReturnCallback(function (&$iterator, $pattern, $count = 100) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $iterator = 1; // still iterating
                    return ['agentic:session:sess_1:key_a'];
                }
                $iterator = 0; // done
                return false;
            });

        $this->store->setTtl('sess_1', 7200);
    }

    public function testGetAndDeleteIsAtomic(): void
    {
        // getAndDelete should use GETDEL for atomicity
        $this->redis->expects($this->once())
            ->method('rawCommand')
            ->with('GETDEL', 'agentic:session:sess_1:key_a')
            ->willReturn('s:5:"value";');

        $result = $this->store->getAndDelete('sess_1', 'key_a');
        $this->assertSame('value', $result);
    }

    public function testGetAndDeleteReturnsNullWhenMissing(): void
    {
        $this->redis->method('rawCommand')
            ->with('GETDEL', 'agentic:session:sess_1:missing')
            ->willReturn(false);

        $result = $this->store->getAndDelete('sess_1', 'missing');
        $this->assertNull($result);
    }

    public function testSetWithCustomTtl(): void
    {
        $store = new RedisSessionStore($this->redis, 'agentic:session:', 1800);

        $this->redis->expects($this->once())
            ->method('setex')
            ->with('agentic:session:sess_1:key_a', 1800, $this->anything())
            ->willReturn(true);

        $store->set('sess_1', 'key_a', 'data');
    }
}
