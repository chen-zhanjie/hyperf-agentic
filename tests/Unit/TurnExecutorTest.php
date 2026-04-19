<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\AgentRunContext;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\CostBudget;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\IterationBudget;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\LoopState;
use ChenZhanjie\Agentic\AgentMiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolDispatcher;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\TurnExecutor;
use PHPUnit\Framework\TestCase;

class TurnExecutorTest extends TestCase
{
    private function createExecutor(LlmClient $llmClient, ?ToolRegistry $registry = null): TurnExecutor
    {
        $toolRegistry = $registry ?? new ToolRegistry();
        $pipeline = new AgentMiddlewarePipeline();
        $policy = new ConfigToolPermissionPolicy();

        return new TurnExecutor(
            llmClient: $llmClient,
            promptBuilder: new PromptBuilder(),
            toolDispatcher: new ToolDispatcher($toolRegistry, $pipeline, $policy),
        );
    }

    private function createMockLlm(array $responses): LlmClient
    {
        $index = 0;
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options) use ($responses, &$index): array {
                $response = $responses[$index] ?? ['content' => 'no more responses'];
                $index++;
                return $response;
            },
        );
    }

    private function createContext(): AgentRunContext
    {
        return new AgentRunContext(
            guardrails: new GuardrailRunner(),
            toolGuardrails: new ToolGuardrailRunner(),
            permissionPolicy: new ConfigToolPermissionPolicy(),
        );
    }

    private function createLoop(int $maxIterations = 15): LoopState
    {
        return new LoopState(
            startTime: hrtime(true),
            budget: new IterationBudget(maxTotal: $maxIterations),
            costBudget: new CostBudget(maxTotalTokens: PHP_INT_MAX),
            maxIterations: $maxIterations,
            asyncGuardrailTimeout: 5000,
        );
    }

    // ── Sync turn tests ──

    public function testSyncTurnReturnsTextResult(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'Hello!', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
        ]);

        $executor = $this->createExecutor($llm);
        $loop = $this->createLoop();
        $context = $this->createContext();

        $messages = [['role' => 'system', 'content' => 'You are a test assistant.']];
        $events = [];

        $result = $executor->execute(
            fullMessages: $messages,
            systemMessage: 'You are a test assistant.',
            toolSchemas: [],
            options: [],
            onEvent: function (string $event, array $data) use (&$events): void {
                $events[] = $event;
            },
            loop: $loop,
            context: $context,
            stream: false,
        );

        $this->assertNotNull($result);
        $this->assertSame('Hello!', $result->content);
        $this->assertTrue($result->isComplete());
        $this->assertContains('thinking', $events);
        $this->assertContains('complete', $events);
    }

    public function testSyncTurnWithToolCallsReturnsNull(): void
    {
        $tool = new class implements ToolInterface {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Search'; }
            public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return 'found: result'; }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        };

        $registry = new ToolRegistry();
        $registry->register($tool);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']],
                ],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
            ],
        ]);

        $executor = $this->createExecutor($llm, $registry);
        $loop = $this->createLoop();
        $context = $this->createContext();

        $messages = [['role' => 'system', 'content' => 'You are a test assistant.']];

        $result = $executor->execute(
            fullMessages: $messages,
            systemMessage: 'You are a test assistant.',
            toolSchemas: [],
            options: [],
            onEvent: null,
            loop: $loop,
            context: $context,
            stream: false,
        );

        $this->assertNull($result); // Tool calls processed — continue loop
        $this->assertSame(1, $loop->totalToolCalls);
    }

    // ── Stream turn tests ──

    public function testStreamTurnReturnsAccumulatedText(): void
    {
        // Simulate streaming: the final response has empty content,
        // but the onChunk callback accumulates text
        $chunks = [];
        $llm = new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options, ?callable $onChunk = null) use (&$chunks): array {
                if ($type === 'chatStream' && $onChunk !== null) {
                    $onChunk(['content' => 'Hel']);
                    $onChunk(['content' => 'lo!']);
                }
                return ['content' => '', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]];
            },
        );

        $executor = $this->createExecutor($llm);
        $loop = $this->createLoop();
        $context = $this->createContext();

        $messages = [['role' => 'system', 'content' => 'You are a test assistant.']];
        $textDeltas = [];

        $result = $executor->execute(
            fullMessages: $messages,
            systemMessage: 'You are a test assistant.',
            toolSchemas: [],
            options: [],
            onEvent: function (string $event, array $data) use (&$textDeltas): void {
                if ($event === 'text_delta') {
                    $textDeltas[] = $data['content'];
                }
            },
            loop: $loop,
            context: $context,
            stream: true,
        );

        $this->assertNotNull($result);
        $this->assertSame('Hello!', $result->content);
        $this->assertSame(['Hel', 'lo!'], $textDeltas);
    }

    // ── Grace turn test ──

    public function testGraceTurnIncrementsIterations(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'Final answer', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3]],
        ]);

        $executor = $this->createExecutor($llm);
        $loop = $this->createLoop();
        $context = $this->createContext();

        $messages = [['role' => 'system', 'content' => 'Test']];

        $result = $executor->execute(
            fullMessages: $messages,
            systemMessage: 'Test',
            toolSchemas: [],
            options: [],
            onEvent: null,
            loop: $loop,
            context: $context,
            stream: false,
            grace: true,
        );

        $this->assertNotNull($result);
        $this->assertSame('Final answer', $result->content);
        $this->assertSame(1, $loop->iterations); // Grace increments
    }

    // ── Token tracking ──

    public function testTurnRecordsTokenUsage(): void
    {
        $llm = $this->createMockLlm([
            ['content' => 'Response', 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]],
        ]);

        $executor = $this->createExecutor($llm);
        $loop = $this->createLoop();
        $context = $this->createContext();

        $messages = [['role' => 'system', 'content' => 'Test']];

        $result = $executor->execute(
            fullMessages: $messages,
            systemMessage: 'Test',
            toolSchemas: [],
            options: [],
            onEvent: null,
            loop: $loop,
            context: $context,
        );

        $this->assertSame(100, $loop->totalPromptTokens);
        $this->assertSame(50, $loop->totalCompletionTokens);
    }
}
