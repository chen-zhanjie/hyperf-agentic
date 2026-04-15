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

    // ── recall ──

    public function testRecallMarksMessageAsRecalled(): void
    {
        $this->store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello'],
            ['id' => 'msg-2', 'role' => 'assistant', 'content' => 'Bad output'],
        ]);

        $this->store->recall('conv-1', 'msg-2', 'unsafe content');

        $messages = $this->store->load('conv-1');
        $this->assertTrue($messages[1]['recalled']);
        $this->assertSame('unsafe content', $messages[1]['recall_reason']);
        $this->assertSame('[消息已撤回]', $messages[1]['content']);
    }

    public function testRecallDoesNotAffectOtherMessages(): void
    {
        $this->store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello'],
            ['id' => 'msg-2', 'role' => 'assistant', 'content' => 'Bad'],
        ]);

        $this->store->recall('conv-1', 'msg-2', 'test');

        $messages = $this->store->load('conv-1');
        $this->assertFalse(isset($messages[0]['recalled']));
        $this->assertSame('Hello', $messages[0]['content']);
    }

    public function testRecallUnknownMessageDoesNothing(): void
    {
        $this->store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello'],
        ]);

        // Should not throw
        $this->store->recall('conv-1', 'msg-nonexistent', 'test');

        $messages = $this->store->load('conv-1');
        $this->assertCount(1, $messages);
        $this->assertFalse(isset($messages[0]['recalled']));
    }

    public function testRecallUnknownConversationDoesNotError(): void
    {
        // Should not throw
        $this->store->recall('conv-nonexistent', 'msg-1', 'test');
        $this->assertSame([], $this->store->load('conv-nonexistent'));
    }

    // ── implements interface ──

    public function testImplementsMessageStoreInterface(): void
    {
        $this->assertInstanceOf(MessageStoreInterface::class, $this->store);
    }
}
