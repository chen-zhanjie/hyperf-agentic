<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\AgentRunner;

use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\Contract\AgentMiddlewareInterface;
use ChenZhanjie\Agentic\Contract\SkillInterface;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\AgentMiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\Tests\Unit\Concerns\AgentRunnerTestHelpers;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\ToolCallContext;
use PHPUnit\Framework\TestCase;

class BasicRunnerTest extends TestCase
{
    use AgentRunnerTestHelpers;

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

    public function testRunHandlesMinimalArrayResponse(): void
    {
        $llm = $this->createMockLlm([['content' => 'Just a string response', 'usage' => $this->usage(10, 5)]]);

        $runner = $this->createRunner($llm);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            $this->defaultConfig(),
        );

        $this->assertSame('Just a string response', $result->content);
        $this->assertTrue($result->isComplete());
    }

    public function testRunWithSingleToolCall(): void
    {
        $tool = $this->createMockTool('search', 'Search tool', '{"result":"found it"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $llm = $this->createMockLlm([
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

    public function testRunWithMultipleToolCalls(): void
    {
        $tool1 = $this->createMockTool('search_a', 'Tool A', '{"a":1}');
        $tool2 = $this->createMockTool('search_b', 'Tool B', '{"b":2}');
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

    public function testMiddlewareInterceptsToolCall(): void
    {
        $tool = $this->createMockTool('dangerous', 'Dangerous tool', 'should not be called');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $middleware = new class implements AgentMiddlewareInterface {
            public function onAgentStart(array $agentConfig, array $options): void {}
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeToolCall(string $name, array $arguments, ToolCallContext $context): ?string
            {
                if ($name === 'dangerous') {
                    return 'Tool blocked by security policy';
                }
                return null;
            }
            public function afterToolCall(string $name, array $arguments, string $result, ToolCallContext $context): void {}
        };

        $pipeline = new AgentMiddlewarePipeline();
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
        $tool = $this->createMockTool('search', 'Search', '{"found":true}');
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

    public function testSkillsWhitelistPassedToPromptBuilder(): void
    {
        $skillRegistry = new SkillRegistry();
        $skillRegistry->register(new class implements SkillInterface {
            public function name(): string { return 'query-builder'; }
            public function description(): string { return 'Build SQL queries'; }
            public function toDescriptionLine(): string { return '- query-builder: Build SQL queries'; }
            public function toFullInstructions(): string { return 'Full query builder instructions'; }
            public function loadResource(string $relativePath): ?string { return null; }
            public function tools(): array { return []; }
            public function autoInvoke(): bool { return true; }
            public function userInvocable(): bool { return true; }
        });
        $skillRegistry->register(new class implements SkillInterface {
            public function name(): string { return 'report-gen'; }
            public function description(): string { return 'Generate reports'; }
            public function toDescriptionLine(): string { return '- report-gen: Generate reports'; }
            public function toFullInstructions(): string { return 'Full report instructions'; }
            public function loadResource(string $relativePath): ?string { return null; }
            public function tools(): array { return []; }
            public function autoInvoke(): bool { return true; }
            public function userInvocable(): bool { return true; }
        });

        $capturedSystemMsg = null;
        $llm = new LlmClient(
            providerConfigs: ['test' => ['model' => 'test']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options) use (&$capturedSystemMsg) {
                $capturedSystemMsg = $messages[0]['content'] ?? '';
                return ['content' => 'done', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]];
            },
        );

        $runner = $this->createRunner($llm, skillRegistry: $skillRegistry);

        $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            array_merge($this->defaultConfig(), [
                'skills' => ['query-builder'],
            ]),
        );

        $this->assertNotNull($capturedSystemMsg);
        $this->assertStringContainsString('query-builder', $capturedSystemMsg);
        $this->assertStringNotContainsString('report-gen', $capturedSystemMsg);
    }

    public function testSkillsConfigWithNoSkillRegistryDoesNotError(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'done', 'usage' => $this->usage(50, 10)],
        ]);

        $runner = $this->createRunner($llm);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            array_merge($this->defaultConfig(), [
                'skills' => ['some-skill'],
            ]),
        );

        $this->assertTrue($result->isComplete());
    }

    public function testPromptBuilderResetBetweenRunsPreventsCacheLeak(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'First response', 'usage' => $this->usage(50, 10)],
            ['content' => 'Second response', 'usage' => $this->usage(50, 10)],
        ]);

        $builder = new PromptBuilder();
        $runner = new AgentRunner($llm, $builder, new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            array_merge($this->defaultConfig(), [
                'persona' => new Persona(name: 'Alpha', content: 'You are Agent Alpha.'),
            ]),
        );

        $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            array_merge($this->defaultConfig(), [
                'persona' => new Persona(name: 'Beta', content: 'You are Agent Beta.'),
            ]),
        );

        $cached = $builder->getCachedPrompt();
        $this->assertNotNull($cached);
        $this->assertStringContainsString('Agent Beta', $cached);
        $this->assertStringNotContainsString('Agent Alpha', $cached);
    }
}
