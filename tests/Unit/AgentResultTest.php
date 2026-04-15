<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentResult;

class AgentResultTest extends TestCase
{
    public function testCompleteFactoryCreatesResult(): void
    {
        $result = AgentResult::complete(
            content: 'Hello world',
            iterations: 3,
            elapsedMs: 1500,
            promptTokens: 100,
            completionTokens: 50,
            toolCalls: 2,
        );
        $this->assertTrue($result->isComplete());
        $this->assertFalse($result->isSuspended());
        $this->assertFalse($result->isBudgetExhausted());
        $this->assertSame('Hello world', $result->content);
        $this->assertSame(3, $result->iterations);
        $this->assertSame('complete', $result->stopReason);
    }

    public function testCompleteWithReasoningContent(): void
    {
        $result = AgentResult::complete(
            content: 'done',
            reasoningContent: 'thinking...',
        );
        $this->assertSame('thinking...', $result->reasoningContent);
    }

    public function testSuspendedFactory(): void
    {
        $result = AgentResult:: suspended(
            reason: 'awaiting_human_input',
            data: ['question' => 'Confirm?'],
        );
        $this->assertTrue($result->isSuspended());
        $this->assertFalse($result->isComplete());
        $this->assertSame('awaiting_human_input', $result->getSuspendedReason());
        $this->assertSame(['question' => 'Confirm?'], $result->getSuspendedData());
        $this->assertSame('suspended', $result->stopReason);
    }

    public function testBudgetExhaustedFactory(): void
    {
        $result = AgentResult::budgetExhausted(iterations: 10, max: 10);
        $this->assertTrue($result->isBudgetExhausted());
        $this->assertTrue($result->isComplete()); // has stopReason, no suspendedReason
        $this->assertSame(10, $result->iterations);
        $this->assertSame('budget_exhausted', $result->stopReason);
    }

    public function testToArrayContainsAllFields(): void
    {
        $result = AgentResult::complete(content: 'test', iterations: 5);
        $arr = $result->toArray();
        $this->assertArrayHasKey('content', $arr);
        $this->assertArrayHasKey('iterations', $arr);
        $this->assertArrayHasKey('elapsed_ms', $arr);
        $this->assertArrayHasKey('prompt_tokens', $arr);
        $this->assertArrayHasKey('completion_tokens', $arr);
        $this->assertArrayHasKey('tool_calls', $arr);
        $this->assertArrayHasKey('stop_reason', $arr);
        $this->assertArrayHasKey('suspended', $arr);
        $this->assertFalse($arr['suspended']);
    }

    public function testSuspendedToArrayMarksSuspendedTrue(): void
    {
        $result = AgentResult::suspended(reason: 'test');
        $arr = $result->toArray();
        $this->assertTrue($arr['suspended']);
    }

    public function testGuardrailBlockedFactory(): void
    {
        $result = AgentResult::guardrailBlocked('input', 'toxic content', 500);
        $this->assertTrue($result->isGuardrailBlocked());
        $this->assertFalse($result->isRecalled());
        $this->assertSame('guardrail', $result->stopReason);
        $this->assertSame('input', $result->getSuspendedReason());
        $this->assertSame(['reason' => 'toxic content'], $result->getSuspendedData());
    }

    public function testRecalledFactory(): void
    {
        $result = AgentResult::recalled(
            content: 'Bad output',
            reason: 'PII detected',
            messageId: 'msg-123',
            elapsedMs: 300,
        );
        $this->assertTrue($result->isRecalled());
        $this->assertTrue($result->isGuardrailBlocked());
        $this->assertSame('Bad output', $result->content);
        $this->assertSame('msg-123', $result->messageId);
        $this->assertSame('PII detected', $result->recallReason);
        $this->assertSame(300, $result->elapsedMs);
    }

    public function testRecalledToArrayIncludesRecallFields(): void
    {
        $result = AgentResult::recalled(
            content: 'test',
            reason: 'unsafe',
            messageId: 'msg-1',
        );
        $arr = $result->toArray();
        $this->assertTrue($arr['recalled']);
        $this->assertSame('unsafe', $arr['recall_reason']);
        $this->assertSame('msg-1', $arr['message_id']);
    }

    public function testNonRecalledResultHasNoRecallFields(): void
    {
        $result = AgentResult::complete(content: 'hello');
        $arr = $result->toArray();
        $this->assertFalse($arr['recalled']);
        $this->assertNull($arr['recall_reason']);
        $this->assertNull($arr['message_id']);
    }
}
