<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Session\MemorySessionStore;

class MemorySessionStoreTest extends TestCase
{
    private MemorySessionStore $store;

    protected function setUp(): void
    {
        $this->store = new MemorySessionStore();
    }

    public function testSetAndGet(): void
    {
        $this->store->set('sess1', 'key1', 'value1');
        $this->assertSame('value1', $this->store->get('sess1', 'key1'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $result = $this->store->get('sess1', 'missing', 'default');
        $this->assertSame('default', $result);
    }

    public function testDeleteRemovesKey(): void
    {
        $this->store->set('sess1', 'key1', 'value1');
        $this->store->delete('sess1', 'key1');
        $this->assertNull($this->store->get('sess1', 'key1'));
    }

    public function testHasChecksExistence(): void
    {
        $this->assertFalse($this->store->has('sess1', 'key1'));
        $this->store->set('sess1', 'key1', 'value1');
        $this->assertTrue($this->store->has('sess1', 'key1'));
    }

    public function testSessionsAreIsolated(): void
    {
        $this->store->set('sess1', 'key1', 'value1');
        $this->store->set('sess2', 'key1', 'value2');
        $this->assertSame('value1', $this->store->get('sess1', 'key1'));
        $this->assertSame('value2', $this->store->get('sess2', 'key1'));
    }

    public function testGetAndDeleteIsAtomic(): void
    {
        $this->store->set('sess1', 'key1', 'value1');
        $result = $this->store->getAndDelete('sess1', 'key1');
        $this->assertSame('value1', $result);
        $this->assertNull($this->store->get('sess1', 'key1'));
    }

    public function testSetTtlExpiresEntries(): void
    {
        $this->store->set('sess1', 'key1', 'value1');
        $this->store->setTtl('sess1', -1); // expired immediately
        $this->assertNull($this->store->get('sess1', 'key1'));
    }

    public function testComplexValueStorage(): void
    {
        $data = ['messages' => [['role' => 'user', 'content' => 'hi']], 'iterations' => 5];
        $this->store->set('sess1', 'state', $data);
        $this->assertSame($data, $this->store->get('sess1', 'state'));
    }
}
