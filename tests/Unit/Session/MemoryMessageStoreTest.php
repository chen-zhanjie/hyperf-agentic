<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Session\MemoryMessageStore;

class MemoryMessageStoreTest extends TestCase
{
    private MemoryMessageStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryMessageStore();
    }

    // ── load / append ──

    public function testLoadReturnsEmptyForUnknownConversation(): void
    {
        $this->assertSame([], $this->store->load('conv-unknown'));
    }

    public function testAppendAndLoad(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $this->store->append('conv-1', $messages);

        $loaded = $this->store->load('conv-1');
        $this->assertCount(1, $loaded);
        $this->assertSame('Hello', $loaded[0]['content']);
    }

    public function testAppendAddsToExistingMessages(): void
    {
        $this->store->append('conv-1', [
            ['role' => 'user', 'content' => 'First'],
        ]);
        $this->store->append('conv-1', [
            ['role' => 'assistant', 'content' => 'Response'],
        ]);

        $loaded = $this->store->load('conv-1');
        $this->assertCount(2, $loaded);
        $this->assertSame('First', $loaded[0]['content']);
        $this->assertSame('Response', $loaded[1]['content']);
    }

    public function testMultipleConversationsAreIsolated(): void
    {
        $this->store->append('conv-a', [['role' => 'user', 'content' => 'A']]);
        $this->store->append('conv-b', [['role' => 'user', 'content' => 'B']]);

        $this->assertCount(1, $this->store->load('conv-a'));
        $this->assertSame('A', $this->store->load('conv-a')[0]['content']);
        $this->assertSame('B', $this->store->load('conv-b')[0]['content']);
    }

    // ── delete ──

    public function testDeleteRemovesConversation(): void
    {
        $this->store->append('conv-1', [['role' => 'user', 'content' => 'Hi']]);
        $this->store->delete('conv-1');

        $this->assertSame([], $this->store->load('conv-1'));
    }

    public function testDeleteNonexistentDoesNotError(): void
    {
        $this->store->delete('conv-nonexistent');
        $this->assertFalse($this->store->exists('conv-nonexistent'));
    }

    // ── exists ──

    public function testExistsReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->store->exists('conv-unknown'));
    }

    public function testExistsReturnsTrueAfterAppend(): void
    {
        $this->store->append('conv-1', [['role' => 'user', 'content' => 'Hi']]);
        $this->assertTrue($this->store->exists('conv-1'));
    }

    public function testExistsReturnsFalseAfterDelete(): void
    {
        $this->store->append('conv-1', [['role' => 'user', 'content' => 'Hi']]);
        $this->store->delete('conv-1');
        $this->assertFalse($this->store->exists('conv-1'));
    }

    // ── implements interface ──

    public function testImplementsMessageStoreInterface(): void
    {
        $this->assertInstanceOf(MessageStoreInterface::class, $this->store);
    }
}
