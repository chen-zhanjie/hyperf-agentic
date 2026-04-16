<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\AgentRunner;

use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\Contract\ToolGuardrailInterface;
use ChenZhanjie\Agentic\GuardrailMode;
use ChenZhanjie\Agentic\GuardrailResult;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\Tests\Unit\Concerns\AgentRunnerTestHelpers;
use ChenZhanjie\Agentic\ToolGuardrailResult;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use PHPUnit\Framework\TestCase;

class GuardrailRunnerTest extends TestCase
{
    use AgentRunnerTestHelpers;

    public function testGuardrailBlocksInput(): void
    {
        $llm = $this->createMockLlm(['should not be called']);
        $guardrailRunner = new GuardrailRunner();
        $guardrailRunner->register(
            new class implements GuardrailInterface {
                public function name(): string { return 'input_block'; }
                public function checkInput(array $messages): GuardrailResult
                {
                    return GuardrailResult::blocked('Dangerous input');
                }
                public function checkOutput(string $content): GuardrailResult
                {
                    return GuardrailResult::ok();
                }
            },
        );

        $runner = $this->createRunner($llm, null, $guardrailRunner);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'dangerous']],
            $this->defaultConfig(),
        );

        $this->assertSame('guardrail', $result->stopReason);
        $this->assertSame(0, $result->iterations);
    }

    public function testGuardrailBlocksOutput(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'harmful output', 'usage' => $this->usage(50, 20)],
        ]);
        $guardrailRunner = new GuardrailRunner();
        $guardrailRunner->register(
            new class implements GuardrailInterface {
                public function name(): string { return 'output_block'; }
                public function checkInput(array $messages): GuardrailResult
                {
                    return GuardrailResult::ok();
                }
                public function checkOutput(string $content): GuardrailResult
                {
                    if (str_contains($content, 'harmful')) {
                        return GuardrailResult::blocked('Harmful output detected');
                    }
                    return GuardrailResult::ok();
                }
            },
        );

        $runner = $this->createRunner($llm, null, $guardrailRunner);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'test']],
            $this->defaultConfig(),
        );

        $this->assertSame('guardrail', $result->stopReason);
    }

    public function testEmitsGuardrailBlockedEvent(): void
    {
        $llm = $this->createMockLlm(['never called']);
        $guardrailRunner = new GuardrailRunner();
        $guardrailRunner->register(
            new class implements GuardrailInterface {
                public function name(): string { return 'block'; }
                public function checkInput(array $messages): GuardrailResult
                {
                    return GuardrailResult::blocked('blocked');
                }
                public function checkOutput(string $content): GuardrailResult
                {
                    return GuardrailResult::ok();
                }
            },
        );

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner = $this->createRunner($llm, null, $guardrailRunner);
        $runner->run(
            [['role' => 'user', 'content' => 'test']],
            $this->defaultConfig(),
            [],
            $onEvent,
        );

        $this->assertContains('guardrail_blocked', $events);
    }

    public function testAsyncOutputGuardrailBlocksAfterOutput(): void
    {
        $guardrail = new class implements GuardrailInterface {
            public bool $asyncBlocked = false;
            public function name(): string { return 'toxicity'; }
            public function checkInput(array $messages): GuardrailResult
            {
                return GuardrailResult::ok();
            }
            public function checkOutput(string $content): GuardrailResult
            {
                if (str_contains($content, 'TOXIC')) {
                    $this->asyncBlocked = true;
                    return GuardrailResult::blocked('toxic content');
                }
                return GuardrailResult::ok();
            }
        };

        $guardrailRunner = new GuardrailRunner();
        $guardrailRunner->register($guardrail, GuardrailMode::ASYNC);

        $llm = $this->createMockLlm([
            ['content' => 'TOXIC output', 'usage' => $this->usage(50, 20)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = ['type' => $type, 'payload' => $payload];
        };

        $runner = $this->createRunner($llm, guardrailRunner: $guardrailRunner);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'say toxic']],
            $this->defaultConfig(),
            [],
            $onEvent,
        );

        $this->assertTrue($result->isGuardrailBlocked());
        $this->assertSame('TOXIC output', $result->content);

        $eventTypes = array_column($events, 'type');
        $this->assertContains('thinking', $eventTypes);
        $this->assertContains('complete', $eventTypes);
        $this->assertContains('guardrail_recalled', $eventTypes);
    }

    public function testAsyncOutputGuardrailPassesReturnsNormalResult(): void
    {
        $guardrail = new class implements GuardrailInterface {
            public function name(): string { return 'safe_checker'; }
            public function checkInput(array $messages): GuardrailResult { return GuardrailResult::ok(); }
            public function checkOutput(string $content): GuardrailResult { return GuardrailResult::ok(); }
        };

        $guardrailRunner = new GuardrailRunner();
        $guardrailRunner->register($guardrail, GuardrailMode::ASYNC);

        $llm = $this->createMockLlm([
            ['content' => 'Safe output', 'usage' => $this->usage(50, 20)],
        ]);

        $runner = $this->createRunner($llm, guardrailRunner: $guardrailRunner);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'hello']],
            $this->defaultConfig(),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Safe output', $result->content);
        $this->assertFalse($result->isRecalled());
    }

    public function testGuardrailModesConfigAppliesAsyncModes(): void
    {
        $syncGuard = new class implements GuardrailInterface {
            public function name(): string { return 'sync_filter'; }
            public function checkInput(array $messages): GuardrailResult { return GuardrailResult::ok(); }
            public function checkOutput(string $content): GuardrailResult { return GuardrailResult::ok(); }
        };
        $asyncGuard = new class implements GuardrailInterface {
            public function name(): string { return 'async_checker'; }
            public function checkInput(array $messages): GuardrailResult { return GuardrailResult::ok(); }
            public function checkOutput(string $content): GuardrailResult { return GuardrailResult::ok(); }
        };

        $guardrailRunner = new GuardrailRunner();
        $guardrailRunner->register($syncGuard);
        $guardrailRunner->register($asyncGuard);

        $llm = $this->createMockLlm([
            ['content' => 'output', 'usage' => $this->usage(50, 20)],
        ]);

        $runner = $this->createRunner($llm, guardrailRunner: $guardrailRunner);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'test']],
            array_merge($this->defaultConfig(), [
                'guardrails' => ['sync_filter', 'async_checker'],
                'guardrail_modes' => ['async_checker' => 'async'],
            ]),
        );

        $this->assertTrue($result->isComplete());
    }

    public function testToolBlockedEventEmittedWhenToolGuardrailBlocks(): void
    {
        $toolGuardrail = new class implements ToolGuardrailInterface {
            public function name(): string { return 'input_validator'; }
            public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
            {
                return ToolGuardrailResult::blocked('Invalid arguments');
            }
            public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
            {
                return ToolGuardrailResult::ok();
            }
        };

        $toolGuardrailRunner = new ToolGuardrailRunner();
        $toolGuardrailRunner->register($toolGuardrail);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'search', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(50, 20),
            ],
            ['content' => 'Done', 'usage' => $this->usage(50, 20)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner = $this->createRunner($llm, toolGuardrailRunner: $toolGuardrailRunner);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search']],
            $this->defaultConfig(),
            [],
            $onEvent,
        );

        $this->assertContains('tool_blocked', $events, 'TOOL_BLOCKED event should be emitted when tool guardrail blocks');
    }
}
