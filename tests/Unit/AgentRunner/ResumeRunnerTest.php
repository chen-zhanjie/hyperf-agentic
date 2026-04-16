<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\AgentRunner;

use ChenZhanjie\Agentic\Tests\Unit\Concerns\AgentRunnerTestHelpers;
use ChenZhanjie\Agentic\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ResumeRunnerTest extends TestCase
{
    use AgentRunnerTestHelpers;

    public function testResumeContinuesFromSuspendedState(): void
    {
        $tool = $this->createMockTool('search', 'Search', ['result' => 'data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $callCount = 0;
        $llm = $this->createCallbackMockLlm(function () use (&$callCount) {
            ++$callCount;
            return ['content' => 'Resumed and done.', 'usage' => $this->usage(100, 30)];
        });

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->resume(
            state: [
                'messages' => [
                    ['role' => 'user', 'content' => 'hello'],
                    ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                        ['id' => 'call_1', 'type' => 'function', 'function' => [
                            'name' => 'search', 'arguments' => '{}',
                        ]],
                    ]],
                    ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'result data'],
                ],
                'remaining_iterations' => 10,
                'agent_config' => $this->defaultConfig(),
                'agent_name' => 'Test',
            ],
            sessionId: 'sess_123',
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Resumed and done.', $result->content);
        $this->assertSame(1, $result->iterations);
    }

    public function testResumeWithToolCallsContinuesLoop(): void
    {
        $tool = $this->createMockTool('search', 'Search', ['result' => 'more data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $callCount = 0;
        $llm = $this->createCallbackMockLlm(function () use (&$callCount) {
            ++$callCount;
            if ($callCount === 1) {
                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'call_resumed', 'type' => 'function', 'function' => [
                            'name' => 'search', 'arguments' => '{"q":"more"}',
                        ]],
                    ],
                    'usage' => $this->usage(100, 30),
                ];
            }
            return ['content' => 'Final answer after resume.', 'usage' => $this->usage(100, 20)];
        });

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->resume(
            state: [
                'messages' => [
                    ['role' => 'user', 'content' => 'hello'],
                ],
                'remaining_iterations' => 10,
                'agent_config' => $this->defaultConfig(),
                'agent_name' => 'Test',
            ],
            sessionId: 'sess_456',
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Final answer after resume.', $result->content);
        $this->assertSame(2, $result->iterations);
        $this->assertSame(1, $result->toolCalls);
    }

    public function testResumeRespectsRemainingIterations(): void
    {
        $llm = $this->createInfiniteToolCallLlm('search', '{"q":"test"}');
        $tool = $this->createMockTool('search', 'Search', ['result' => 'data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->resume(
            state: [
                'messages' => [['role' => 'user', 'content' => 'hello']],
                'remaining_iterations' => 1,
                'agent_config' => $this->defaultConfig(),
                'agent_name' => 'Test',
            ],
            sessionId: 'sess_789',
        );

        $this->assertSame('budget_exhausted', $result->stopReason);
    }
}
