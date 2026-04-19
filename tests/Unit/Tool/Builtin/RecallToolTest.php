<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Tool\Builtin;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Session\MemoryMessageStore;
use ChenZhanjie\Agentic\Tool\Builtin\RecallTool;

class RecallToolTest extends TestCase
{
    public function testNameIsRecall(): void
    {
        $tool = new RecallTool();
        $this->assertSame('recall', $tool->name());
    }

    public function testIsAlwaysEnabled(): void
    {
        $tool = new RecallTool();
        $this->assertTrue($tool->isEnabled());
    }

    public function testIsNotParallelAllowed(): void
    {
        $tool = new RecallTool();
        $this->assertFalse($tool->isParallelAllowed());
    }

    public function testExecuteReturnsRecalledConfirmationWithStore(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-123', 'role' => 'assistant', 'content' => 'Bad'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-123',
            'reason' => 'toxic content',
        ]);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['recalled']);
        $this->assertSame('msg-123', $decoded['message_id']);
        $this->assertSame('toxic content', $decoded['reason']);
    }

    public function testExecuteWithOptionalReplacement(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-456', 'role' => 'assistant', 'content' => 'Bad'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-456',
            'reason' => 'PII detected',
            'replacement' => '[内容已撤回]',
        ]);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['recalled']);
        $this->assertSame('[内容已撤回]', $decoded['replacement']);
    }

    public function testExecuteWithMessageStore(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello'],
            ['id' => 'msg-2', 'role' => 'assistant', 'content' => 'Bad response'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-2',
            'reason' => 'unsafe',
        ]);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['recalled']);

        // Verify the message was marked as recalled in the store
        $messages = $store->load('conv-1');
        $this->assertTrue($messages[1]['recalled']);
        $this->assertSame('unsafe', $messages[1]['recall_reason']);
        $this->assertSame('[消息已撤回]', $messages[1]['content']);
    }

    public function testExecuteWithMessageStoreUnrelatedMessageUntouched(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello'],
            ['id' => 'msg-2', 'role' => 'assistant', 'content' => 'Response'],
        ]);

        $tool = new RecallTool($store);
        $tool->execute([
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-2',
            'reason' => 'test',
        ]);

        // msg-1 should be untouched
        $messages = $store->load('conv-1');
        $this->assertFalse(isset($messages[0]['recalled']));
        $this->assertSame('Hello', $messages[0]['content']);
    }

    public function testExecuteWithoutMessageStoreReturnsError(): void
    {
        $tool = new RecallTool();
        $result = $tool->execute([
            'message_id' => 'msg-789',
            'reason' => 'test',
        ]);

        $decoded = json_decode($result, true);
        $this->assertFalse($decoded['recalled']);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testExecuteWithEmptyMessageIdReturnsError(): void
    {
        $store = new MemoryMessageStore();
        $tool = new RecallTool($store);
        $result = $tool->execute([
            'message_id' => '',
            'reason' => 'test',
        ]);

        $decoded = json_decode($result, true);
        $this->assertFalse($decoded['recalled']);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testExecuteWithEmptyConversationIdReturnsError(): void
    {
        $store = new MemoryMessageStore();
        $tool = new RecallTool($store);
        $result = $tool->execute([
            'message_id' => 'msg-1',
            'reason' => 'test',
        ]);

        $decoded = json_decode($result, true);
        $this->assertFalse($decoded['recalled']);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testExecuteWithInvalidMessageIdReturnsNotFound(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'assistant', 'content' => 'Hello'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'message_id' => 'nonexistent',
            'reason' => 'test',
        ]);

        $decoded = json_decode($result, true);
        $this->assertFalse($decoded['recalled']);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testExecuteRecallLastWithoutMessageId(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello'],
            ['id' => 'msg-2', 'role' => 'assistant', 'content' => 'Bad response'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'reason' => 'self-correction',
        ]);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['recalled']);
        $this->assertSame('msg-2', $decoded['message_id']);

        // Verify store state
        $messages = $store->load('conv-1');
        $this->assertSame('[消息已撤回]', $messages[1]['content']);
        $this->assertFalse(isset($messages[0]['recalled']));
    }

    public function testExecuteRecallLastWithCurrentKeyword(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'assistant', 'content' => 'Bad'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'message_id' => 'current',
            'reason' => 'error',
        ]);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['recalled']);
        $this->assertSame('msg-1', $decoded['message_id']);
    }

    public function testExecuteRecallLastSkipsAlreadyRecalled(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'assistant', 'content' => 'Old bad', 'recalled' => true],
            ['id' => 'msg-2', 'role' => 'assistant', 'content' => 'New bad'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'reason' => 'test',
        ]);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['recalled']);
        $this->assertSame('msg-2', $decoded['message_id']);
    }

    public function testExecuteRecallLastNoAssistantMessage(): void
    {
        $store = new MemoryMessageStore();
        $store->append('conv-1', [
            ['id' => 'msg-1', 'role' => 'user', 'content' => 'Hello'],
        ]);

        $tool = new RecallTool($store);
        $result = $tool->execute([
            'conversation_id' => 'conv-1',
            'reason' => 'test',
        ]);

        $decoded = json_decode($result, true);
        $this->assertFalse($decoded['recalled']);
    }

    public function testParametersHasRequiredFields(): void
    {
        $tool = new RecallTool();
        $params = $tool->parameters();

        $this->assertSame('object', $params['type']);
        $this->assertContains('reason', $params['required']);
        $this->assertNotContains('message_id', $params['required']);
    }
}
