<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\Contract\MiddlewareInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\GuardrailResult;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\IterationBudget;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolRegistry;

class AgentRunnerTest extends TestCase
{
    // ── Simple text response (no tool calls) ──

    public function testRunReturnsCompleteOnTextResponse(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'Hello! How can I help?', 'usage' => $this->usage(50, 20)],
        ]);

        $runner = $this->createRunner($llm);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            $this->defaultConfig(),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Hello! How can I help?', $result->content);
        $this->assertSame(1, $result->iterations);
    }

    // ── String response from LLM ──

    public function testRunHandlesStringLlmResponse(): void
    {
        $llm = $this->createMockLlm(["Just a string response"]);

        $runner = $this->createRunner($llm);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            $this->defaultConfig(),
        );

        $this->assertSame('Just a string response', $result->content);
        $this->assertTrue($result->isComplete());
    }

    // ── Tool call + text response ──

    public function testRunWithSingleToolCall(): void
    {
        $tool = $this->createMockTool('search', 'Search tool', ['result' => 'found it']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $llm = $this->createMockLlm([
            // Turn 1: LLM calls tool
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => [
                        'name' => 'search',
                        'arguments' => '{"query":"test"}',
                    ]],
                ],
                'usage' => $this->usage(100, 30),
            ],
            // Turn 2: LLM returns text
            [
                'content' => 'Found: result from search',
                'usage' => $this->usage(150, 40),
            ],
        ]);

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search for test']],
            $this->defaultConfig(),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Found: result from search', $result->content);
        $this->assertSame(2, $result->iterations);
        $this->assertSame(1, $result->toolCalls);
    }

    // ── Multiple tool calls in one response ──

    public function testRunWithMultipleToolCalls(): void
    {
        $tool1 = $this->createMockTool('search_a', 'Tool A', ['a' => 1]);
        $tool2 = $this->createMockTool('search_b', 'Tool B', ['b' => 2]);
        $registry = new ToolRegistry();
        $registry->register($tool1);
        $registry->register($tool2);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => [
                        'name' => 'search_a',
                        'arguments' => '{}',
                    ]],
                    ['id' => 'call_2', 'type' => 'function', 'function' => [
                        'name' => 'search_b',
                        'arguments' => '{}',
                    ]],
                ],
                'usage' => $this->usage(100, 40),
            ],
            [
                'content' => 'Combined results from A and B',
                'usage' => $this->usage(200, 50),
            ],
        ]);

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search both']],
            $this->defaultConfig(),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame(2, $result->iterations);
        $this->assertSame(2, $result->toolCalls);
    }

    // ── Guardrail blocks input ──

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

    // ── Guardrail blocks output ──

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

    // ── Budget exhaustion (no grace — LLM keeps calling tools) ──

    public function testBudgetExhaustionWhenLlmKeepsCallingTools(): void
    {
        // Return tool_calls forever to exhaust budget
        $llm = $this->createInfiniteToolCallLlm('search', '{"q":"test"}');

        $tool = $this->createMockTool('search', 'Search', ['result' => 'data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'keep searching']],
            $this->defaultConfig(maxIterations: 2),
        );

        // max_iterations=2 + 1 grace turn = 3 iterations, but LLM keeps calling tools
        $this->assertSame('budget_exhausted', $result->stopReason);
        $this->assertSame(3, $result->iterations); // 2 normal + 1 grace
    }

    // ── Grace turn: LLM wraps up on final turn ──

    public function testGraceTurnAllowsCleanFinish(): void
    {
        $tool = $this->createMockTool('search', 'Search', ['result' => 'data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $callCount = 0;
        $llm = $this->createCallbackMockLlm(function () use (&$callCount) {
            ++$callCount;
            if ($callCount <= 2) {
                // First 2 turns: call a tool
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
            // Grace turn: return text
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

        // Grace turn: LLM returned text on the extra turn → complete
        $this->assertTrue($result->isComplete());
        $this->assertSame('Here is my final answer based on the searches.', $result->content);
        $this->assertSame(3, $result->iterations); // 2 normal + 1 grace
        $this->assertSame(2, $result->toolCalls);
    }

    public function testGraceTurnInjectsBudgetWarning(): void
    {
        $tool = $this->createMockTool('search', 'Search', ['result' => 'data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $graceTurnMessages = null;
        $callCount = 0;
        $llm = $this->createCallbackMockLlm(function (string $type, string $provider, array $config, array $messages, array $options) use (&$graceTurnMessages, &$callCount) {
            ++$callCount;
            $systemMsg = $messages[0]['content'] ?? '';

            // Only return text on the grace turn (identified by "最后一轮收尾" — unique to grace message)
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
        // Verify the system message on the grace turn contained the grace message
        $this->assertNotNull($graceTurnMessages);
        $systemContent = $graceTurnMessages[0]['content'] ?? '';
        $this->assertTrue(
            str_contains($systemContent, '最后一轮收尾'),
            'Grace turn system prompt should contain grace message'
        );
    }

    // ── Middleware beforeToolCall intercepts ──

    public function testMiddlewareInterceptsToolCall(): void
    {
        $tool = $this->createMockTool('dangerous', 'Dangerous tool', ['should not be called']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $middleware = new class implements MiddlewareInterface {
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeLlmCall(array $messages, array $options): array { return $options; }
            public function afterLlmCall(array $response, array $usage): void {}
            public function beforeToolCall(string $name, array $arguments): ?string
            {
                if ($name === 'dangerous') {
                    return 'Tool blocked by security policy';
                }
                return null;
            }
            public function afterToolCall(string $name, array $arguments, string $result): void {}
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($middleware);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => [
                        'name' => 'dangerous',
                        'arguments' => '{}',
                    ]],
                ],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Could not complete: tool was blocked', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry, null, $pipeline);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'do something dangerous']],
            $this->defaultConfig(),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Could not complete: tool was blocked', $result->content);
    }

    // ── Event emission ──

    public function testEmitsStartedAndCompleteEvents(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'done', 'usage' => $this->usage(50, 10)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner = $this->createRunner($llm);
        $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            $this->defaultConfig(),
            [],
            $onEvent,
        );

        $this->assertContains('started', $events);
        $this->assertContains('complete', $events);
    }

    public function testEmitsToolEventsOnToolCall(): void
    {
        $tool = $this->createMockTool('search', 'Search', ['found' => true]);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => [
                        'name' => 'search',
                        'arguments' => '{"q":"test"}',
                    ]],
                ],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'done', 'usage' => $this->usage(150, 10)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner = $this->createRunner($llm, $registry);
        $runner->run(
            [['role' => 'user', 'content' => 'search']],
            $this->defaultConfig(),
            [],
            $onEvent,
        );

        $this->assertContains('tool_call', $events);
        $this->assertContains('tool_result', $events);
    }

    // ── Guardrail blocked event ──

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

    // ── Token usage tracking ──

    public function testTracksTokenUsage(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'response', 'usage' => $this->usage(120, 45)],
        ]);

        $runner = $this->createRunner($llm);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            $this->defaultConfig(),
        );

        $this->assertSame(120, $result->promptTokens);
        $this->assertSame(45, $result->completionTokens);
    }

    // ── Agent-level tool handlers ──

    public function testAgentLevelToolHandlerIntercepts(): void
    {
        $registry = new ToolRegistry();

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => [
                        'name' => 'custom_agent_tool',
                        'arguments' => '{}',
                    ]],
                ],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'agent tool handled', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry);
        $runner->registerAgentTool('custom_agent_tool', function (array $args): string {
            return json_encode(['handled' => true, 'args' => $args]);
        });

        $result = $runner->run(
            [['role' => 'user', 'content' => 'use custom tool']],
            $this->defaultConfig(),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame(1, $result->toolCalls);
    }

    // ── Elapsed time tracking ──

    public function testElapsedTimeIsRecorded(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'done', 'usage' => $this->usage(50, 10)],
        ]);

        $runner = $this->createRunner($llm);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            $this->defaultConfig(),
        );

        $this->assertGreaterThanOrEqual(0, $result->elapsedMs);
    }

    // ── Resume mechanism ──

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
                'remaining_iterations' => 1, // very limited
                'agent_config' => $this->defaultConfig(),
                'agent_name' => 'Test',
            ],
            sessionId: 'sess_789',
        );

        // Should stop after 1 normal + 1 grace
        $this->assertSame('budget_exhausted', $result->stopReason);
    }

    // ── CostBudget integration ──

    public function testCostBudgetStopsWhenExceeded(): void
    {
        $tool = $this->createMockTool('search', 'Search', ['result' => 'data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        // LLM always returns tool calls (never wraps up on its own)
        $llm = $this->createInfiniteToolCallLlm('search', '{"q":"test"}');

        $runner = $this->createRunner($llm, $registry);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'expensive query']],
            $this->defaultConfig(maxIterations: 10, maxCostTokens: 150),
        );

        // maxCostTokens=150, each call uses 100+30=130 tokens
        // After 1st call: 130 tokens used, 130/150=86% → not exceeded yet
        // After 2nd call: 260 tokens used → exceeded, stops
        $this->assertSame('budget_exhausted', $result->stopReason);
        $this->assertLessThanOrEqual(3, $result->iterations); // should stop early
    }

    public function testCostBudgetWarningEmittedWhenNearLimit(): void
    {
        $tool = $this->createMockTool('search', 'Search', ['result' => 'data']);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $callCount = 0;
        $sawBudgetWarning = false;
        // maxCostTokens=160, usage per call = 100+30=130 tokens
        // After turn 1: 130/160 = 81% → near limit (80% threshold)
        // Turn 2 should see warning in ephemeral prompt
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

    private function createRunner(
        LlmClient $llmClient,
        ?ToolRegistry $toolRegistry = null,
        ?GuardrailRunner $guardrailRunner = null,
        ?MiddlewarePipeline $pipeline = null,
    ): AgentRunner {
        return new AgentRunner(
            llmClient: $llmClient,
            promptBuilder: new PromptBuilder(),
            toolRegistry: $toolRegistry ?? new ToolRegistry(),
            guardrailRunner: $guardrailRunner ?? new GuardrailRunner(),
            middleware: $pipeline ?? new MiddlewarePipeline(),
        );
    }

    private function createMockLlm(array $responses): LlmClient
    {
        $index = 0;
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options) use ($responses, &$index): string|array {
                $response = $responses[$index] ?? ['content' => 'no more responses'];
                $index++;
                return $response;
            },
        );
    }

    private function createCallbackMockLlm(callable $callback): LlmClient
    {
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: $callback,
        );
    }

    private function createInfiniteToolCallLlm(string $toolName, string $args): LlmClient
    {
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options) use ($toolName, $args): array {
                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'call_' . uniqid(), 'type' => 'function', 'function' => [
                            'name' => $toolName,
                            'arguments' => $args,
                        ]],
                    ],
                    'usage' => $this->usage(100, 30),
                ];
            },
        );
    }

    private function createMockTool(string $name, string $description, string|array $returnValue): ToolInterface
    {
        return new class($name, $description, $returnValue) implements ToolInterface {
            public function __construct(
                private readonly string $toolName,
                private readonly string $toolDesc,
                private readonly string|array $returnValue,
            ) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return $this->toolDesc; }
            public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string|array { return $this->returnValue; }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        };
    }

    private function defaultConfig(int $maxIterations = 15, ?int $maxCostTokens = null): array
    {
        $config = [
            'max_iterations' => $maxIterations,
            'persona' => new Persona(
                name: 'Test',
                content: 'You are a test assistant.',
            ),
            'system_prompt' => '',
            'tools' => [],
            'scene' => 'test',
        ];
        if ($maxCostTokens !== null) {
            $config['max_cost_tokens'] = $maxCostTokens;
        }
        return $config;
    }

    private function usage(int $prompt, int $completion): array
    {
        return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion];
    }
}
