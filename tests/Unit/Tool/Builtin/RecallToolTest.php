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

    public function testExecuteReturnsRecalledConfirmation(): void
    {
        $tool = new RecallTool();
        $result = $tool->execute([
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
        $tool = new RecallTool();
        $result = $tool->execute([
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

    public function testExecuteWithoutMessageStoreStillReturnsConfirmation(): void
    {
        $tool = new RecallTool();
        $result = $tool->execute([
            'message_id' => 'msg-789',
            'reason' => 'test',
        ]);

        $decoded = json_decode($result, true);
        $this->assertTrue($decoded['recalled']);
    }

    public function testParametersHasRequiredFields(): void
    {
        $tool = new RecallTool();
        $params = $tool->parameters();

        $this->assertSame('object', $params['type']);
        $this->assertContains('message_id', $params['required']);
        $this->assertContains('reason', $params['required']);
    }
}
