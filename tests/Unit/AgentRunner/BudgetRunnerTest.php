<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\AgentRunner;

use ChenZhanjie\Agentic\Tests\Unit\Concerns\AgentRunnerTestHelpers;
use ChenZhanjie\Agentic\ToolRegistry;
use PHPUnit\Framework\TestCase;

class BudgetRunnerTest extends TestCase
{
    use AgentRunnerTestHelpers;

    public function testBudgetExhaustionWhenLlmKeepsCallingTools(): void
    {
        $llm = $this->createInfiniteToolCallLlm('search', '{"q":"test"}');

        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'keep searching']],
            $this->defaultConfig(maxIterations: 2),
        );

        $this->assertSame('budget_exhausted', $result->stopReason);
        $this->assertSame(3, $result->iterations); // 2 normal + 1 grace
    }

    public function testGraceTurnAllowsCleanFinish(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $callCount = 0;
        $llm = $this->createCallbackMockLlm(function () use (&$callCount) {
            ++$callCount;
            if ($callCount <= 2) {
                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'call_' . $callCount, 'type' => 'function', 'function' => [
                            'name' => 'search',
                            'arguments' => '{"q":"test"}',
                        ]],
                    ],
                    'usage' => $this->usage(100, 30),
                ];
            }
            return [
                'content' => 'Here is my final answer based on the searches.',
                'usage' => $this->usage(150, 40),
            ];
        });

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search repeatedly']],
            $this->defaultConfig(maxIterations: 2),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Here is my final answer based on the searches.', $result->content);
        $this->assertSame(3, $result->iterations); // 2 normal + 1 grace
        $this->assertSame(2, $result->toolCalls);
    }

    public function testGraceTurnInjectsBudgetWarning(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $graceTurnMessages = null;
        $callCount = 0;
        $llm = $this->createCallbackMockLlm(function (string $type, string $provider, array $config, array $messages, array $options) use (&$graceTurnMessages, &$callCount) {
            ++$callCount;
            $systemMsg = $messages[0]['content'] ?? '';

            if (str_contains($systemMsg, '最后一轮收尾')) {
                $graceTurnMessages = $messages;
                return [
                    'content' => 'Wrapping up now.',
                    'usage' => $this->usage(100, 20),
                ];
            }
            return [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_' . $callCount, 'type' => 'function', 'function' => [
                        'name' => 'search',
                        'arguments' => '{"q":"test"}',
                    ]],
                ],
                'usage' => $this->usage(100, 30),
            ];
        });

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search']],
            $this->defaultConfig(maxIterations: 1),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Wrapping up now.', $result->content);
        $this->assertSame(2, $result->iterations); // 1 normal + 1 grace
        $this->assertNotNull($graceTurnMessages);
        $systemContent = $graceTurnMessages[0]['content'] ?? '';
        $this->assertTrue(
            str_contains($systemContent, '最后一轮收尾'),
            'Grace turn system prompt should contain grace message'
        );
    }

    public function testCostBudgetStopsWhenExceeded(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $llm = $this->createInfiniteToolCallLlm('search', '{"q":"test"}');

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'expensive query']],
            $this->defaultConfig(maxIterations: 10, maxCostTokens: 150),
        );

        $this->assertSame('budget_exhausted', $result->stopReason);
        $this->assertLessThanOrEqual(3, $result->iterations);
    }

    public function testCostBudgetWarningEmittedWhenNearLimit(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $callCount = 0;
        $sawBudgetWarning = false;
        $llm = $this->createCallbackMockLlm(function (string $type, string $provider, array $config, array $messages, array $options) use (&$callCount, &$sawBudgetWarning) {
            ++$callCount;
            $systemMsg = $messages[0]['content'] ?? '';
            if (str_contains($systemMsg, 'Token') || str_contains($systemMsg, 'token')) {
                $sawBudgetWarning = true;
            }
            if ($callCount >= 2) {
                return ['content' => 'done', 'usage' => $this->usage(50, 20)];
            }
            return [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_' . $callCount, 'type' => 'function', 'function' => [
                        'name' => 'search',
                        'arguments' => '{"q":"test"}',
                    ]],
                ],
                'usage' => $this->usage(100, 30),
            ];
        });

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search']],
            $this->defaultConfig(maxIterations: 10, maxCostTokens: 160),
        );

        $this->assertTrue($result->isComplete());
        $this->assertTrue($sawBudgetWarning, 'A budget warning should have been injected into the ephemeral prompt');
    }
}
