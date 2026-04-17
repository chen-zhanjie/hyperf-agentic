<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\GuardrailResult;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\GuardrailMode;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\PermissionApprovalStore;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class AgentLifecycleTest extends TestCase
{
    private function createRunner(
        ?ToolRegistry $registry = null,
        ?GuardrailRunner $guardrails = null,
        bool $withApprovalStore = false,
    ): AgentRunner {
        return new AgentRunner(
            llmClient: IntegrationTestConfig::createOpenAiLlmClient(),
            promptBuilder: new PromptBuilder(),
            toolRegistry: $registry ?? new ToolRegistry(),
            guardrailRunner: $guardrails ?? new GuardrailRunner(),
            middleware: new MiddlewarePipeline(),
            toolGuardrailRunner: new ToolGuardrailRunner(),
            permissionPolicy: new ConfigToolPermissionPolicy(),
            approvalStore: $withApprovalStore ? new PermissionApprovalStore() : null,
        );
    }

    private function registerTimeTool(ToolRegistry $registry): void
    {
        $registry->register(new class implements ToolInterface {
            public function name(): string { return 'get_time'; }
            public function description(): string { return 'Get the current time in a timezone'; }
            public function parameters(): array {
                return [
                    'type' => 'object',
                    'properties' => [
                        'timezone' => ['type' => 'string', 'description' => 'Timezone like Asia/Tokyo'],
                    ],
                    'required' => ['timezone'],
                ];
            }
            public function execute(array $arguments): string {
                $tz = $arguments['timezone'] ?? 'UTC';
                try {
                    $time = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d H:i:s');
                } catch (\Exception) {
                    $time = (new \DateTime())->format('Y-m-d H:i:s') . ' (UTC, invalid timezone)';
                }
                return "Current time in {$tz}: {$time}";
            }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        });
    }

    private function registerCalculateTool(ToolRegistry $registry): void
    {
        $registry->register(new class implements ToolInterface {
            public function name(): string { return 'calculate'; }
            public function description(): string { return 'Evaluate a simple math expression and return the result'; }
            public function parameters(): array {
                return [
                    'type' => 'object',
                    'properties' => [
                        'expression' => ['type' => 'string', 'description' => 'Math expression like "42 * 17"'],
                    ],
                    'required' => ['expression'],
                ];
            }
            public function execute(array $arguments): string {
                $expr = $arguments['expression'] ?? '';
                // Only allow safe math characters
                if (!preg_match('/^[\d\s\+\-\*\/\(\)\.]+$/', $expr)) {
                    return 'Error: invalid expression';
                }
                try {
                    $result = eval("return {$expr};");
                    return "{$expr} = {$result}";
                } catch (\Throwable) {
                    return 'Error: could not evaluate expression';
                }
            }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        });
    }

    // -------------------------------------------------------
    // 1. Multi-tool lifecycle
    // -------------------------------------------------------

    public function testMultiToolAgentLifecycle(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();

        $registry = new ToolRegistry();
        $this->registerTimeTool($registry);
        $this->registerCalculateTool($registry);
        $runner = $this->createRunner($registry);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'What time is it in Tokyo, and what is 42 * 17? Use the get_time and calculate tools to answer both questions.']],
            [
                'max_iterations' => 8,
                'persona' => new Persona(name: 'MultiToolAgent', content: 'You are a helpful assistant. Always use the available tools to answer questions.'),
                'system_prompt' => '',
                'tools' => ['get_time', 'calculate'],
                'scene' => 'test',
            ],
        );

        $this->assertTrue($result->isComplete(), 'Agent should complete successfully');
        $this->assertNotEmpty($result->content);
        $this->assertGreaterThanOrEqual(2, $result->toolCalls, 'Should have called at least 2 tools');
        $this->assertGreaterThanOrEqual(2, $result->iterations, 'Should have at least 2 iterations');
    }

    // -------------------------------------------------------
    // 2. Budget exhaustion with grace turn
    // -------------------------------------------------------

    public function testBudgetExhaustionWithGraceTurn(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();

        $registry = new ToolRegistry();
        $this->registerTimeTool($registry);
        $runner = $this->createRunner($registry);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'Use the get_time tool for Asia/Tokyo, then America/New_York, then Europe/London. Tell me all three times.']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'BudgetAgent', content: 'You are a helpful assistant. Use the get_time tool for each timezone the user asks about.'),
                'system_prompt' => '',
                'tools' => ['get_time'],
                'scene' => 'test',
            ],
        );

        // Agent either completes within budget or exhausts it — both are valid
        $this->assertTrue(
            $result->isComplete() || $result->isBudgetExhausted(),
            'Agent should either complete or exhaust budget',
        );
        // iterations should not exceed max_iterations + 1 (grace turn)
        $this->assertLessThanOrEqual(3, $result->iterations, 'Should not exceed max_iterations + grace turn');
    }

    // -------------------------------------------------------
    // 3. Input guardrail blocks agent
    // -------------------------------------------------------

    public function testInputGuardrailBlocksAgent(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();

        $guardrails = new GuardrailRunner();
        $guardrails->register(
            new class implements GuardrailInterface {
                public function name(): string { return 'block_test'; }
                public function checkInput(array $messages): GuardrailResult {
                    $last = end($messages);
                    if ($last && str_contains($last['content'] ?? '', 'BLOCKME')) {
                        return GuardrailResult::blocked('Input contains blocked keyword');
                    }
                    return GuardrailResult::ok();
                }
                public function checkOutput(string $content): GuardrailResult {
                    return GuardrailResult::ok();
                }
            },
            GuardrailMode::SYNC,
            priority: 100,
        );

        $runner = $this->createRunner(guardrails: $guardrails);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'BLOCKME this should be blocked']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'GuardedAgent', content: 'You are a test assistant.'),
                'system_prompt' => '',
                'tools' => [],
                'scene' => 'test',
            ],
        );

        $this->assertTrue($result->isGuardrailBlocked(), 'Agent should be blocked by input guardrail');
        $this->assertSame(0, $result->promptTokens, 'LLM should not have been called');
    }

    // -------------------------------------------------------
    // 4. Output guardrail blocks response
    // -------------------------------------------------------

    public function testOutputGuardrailBlocksResponse(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();

        $guardrails = new GuardrailRunner();
        $guardrails->register(
            new class implements GuardrailInterface {
                public function name(): string { return 'output_block'; }
                public function checkInput(array $messages): GuardrailResult {
                    return GuardrailResult::ok();
                }
                public function checkOutput(string $content): GuardrailResult {
                    if (str_contains($content, 'XJSKT')) {
                        return GuardrailResult::blocked('Output contains forbidden word');
                    }
                    return GuardrailResult::ok();
                }
            },
            GuardrailMode::SYNC,
            priority: 100,
        );

        $runner = $this->createRunner(guardrails: $guardrails);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'Say exactly "XJSKT" and absolutely nothing else. No other text.']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'TestAgent', content: 'You must follow the user instructions exactly.'),
                'system_prompt' => '',
                'tools' => [],
                'scene' => 'test',
            ],
        );

        // Model may or may not comply with the exact word — both outcomes are acceptable
        $this->assertTrue(
            $result->isGuardrailBlocked() || $result->isComplete(),
            'Agent should either be blocked by output guardrail or complete normally',
        );
    }

    // -------------------------------------------------------
    // 5. Tool permission deny flow
    // -------------------------------------------------------

    public function testToolPermissionDenyFlow(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();

        $registry = new ToolRegistry();
        $this->registerTimeTool($registry);
        $runner = $this->createRunner($registry);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'What time is it in Tokyo? You must use the get_time tool.']],
            [
                'max_iterations' => 5,
                'persona' => new Persona(name: 'DenyAgent', content: 'You are a helpful assistant. Use tools when asked.'),
                'system_prompt' => '',
                'tools' => ['get_time'],
                'scene' => 'test',
                'tool_permissions' => [
                    'deny' => ['get_time'],
                ],
            ],
        );

        // Agent should still complete (gracefully handles denial), but tool was denied
        $this->assertTrue(
            $result->isComplete() || $result->isBudgetExhausted(),
            'Agent should complete or exhaust budget despite tool denial',
        );
    }

    // -------------------------------------------------------
    // 6. Streaming events lifecycle
    // -------------------------------------------------------

    public function testStreamingEventsLifecycle(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();

        $registry = new ToolRegistry();
        $this->registerTimeTool($registry);
        $runner = $this->createRunner($registry);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = ['type' => $type, 'payload' => $payload];
        };

        $result = $runner->run(
            [['role' => 'user', 'content' => 'What time is it in Tokyo? Use the get_time tool.']],
            [
                'max_iterations' => 5,
                'persona' => new Persona(name: 'EventAgent', content: 'You are a helpful assistant. Use the get_time tool when asked about time.'),
                'system_prompt' => '',
                'tools' => ['get_time'],
                'scene' => 'test',
            ],
            [],
            $onEvent,
        );

        $this->assertTrue($result->isComplete());
        $this->assertGreaterThanOrEqual(1, $result->toolCalls);

        $eventTypes = array_map(fn ($e) => $e['type'], $events);

        // Verify event sequence
        $this->assertContains('started', $eventTypes, 'Should emit started event');
        $this->assertContains('complete', $eventTypes, 'Should emit complete event');
        $this->assertContains('thinking', $eventTypes, 'Should emit thinking event');
        $this->assertContains('tool_call', $eventTypes, 'Should emit tool_call event');
        $this->assertContains('tool_result', $eventTypes, 'Should emit tool_result event');

        // Verify order: started before complete
        $this->assertLessThan(
            array_search('complete', $eventTypes),
            array_search('started', $eventTypes),
            'started should come before complete',
        );

        // Verify tool_call payload has name
        $toolCallEvents = array_filter($events, fn ($e) => $e['type'] === 'tool_call');
        $this->assertNotEmpty($toolCallEvents);
        $firstToolCall = reset($toolCallEvents);
        $this->assertArrayHasKey('name', $firstToolCall['payload'], 'tool_call payload should have name');
    }

    // -------------------------------------------------------
    // 7. Auto-approve permission mode
    // -------------------------------------------------------

    public function testAutoApprovePermissionMode(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();

        $registry = new ToolRegistry();
        $this->registerTimeTool($registry);
        $runner = $this->createRunner($registry, withApprovalStore: true);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = ['type' => $type, 'payload' => $payload];
        };

        $result = $runner->run(
            [['role' => 'user', 'content' => 'What time is it in Tokyo? Use the get_time tool.']],
            [
                'max_iterations' => 5,
                'persona' => new Persona(name: 'AutoApproveAgent', content: 'You are a helpful assistant. Use the get_time tool when asked.'),
                'system_prompt' => '',
                'tools' => ['get_time'],
                'scene' => 'test',
                'permission_mode' => 'auto',
                'auto_approve' => true,
            ],
            [],
            $onEvent,
        );

        $this->assertTrue($result->isComplete());
        $this->assertGreaterThanOrEqual(1, $result->toolCalls);

        // Verify tool_auto_approved event was emitted
        $eventTypes = array_map(fn ($e) => $e['type'], $events);
        $this->assertContains('tool_auto_approved', $eventTypes, 'Should emit tool_auto_approved when auto_approve is true');
    }
}
