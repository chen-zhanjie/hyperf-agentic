<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class AgentIntegrationTest extends TestCase
{
    private static function skipIfNoKey(): void
    {
        $key = getenv('AGENTIC_TEST_API_KEY');
        if ($key === false || $key === 'sk-your-api-key-here') {
            static::markTestSkipped('AGENTIC_TEST_API_KEY not configured — set it in .env.test');
        }
    }

    private function createLlmClient(): LlmClient
    {
        return new LlmClient(
            providerConfigs: [
                'test' => [
                    'protocol' => 'openai',
                    'base_url' => getenv('AGENTIC_TEST_API_BASE') ?: 'https://api.xiaomimimo.com/v1',
                    'api_key' => getenv('AGENTIC_TEST_API_KEY'),
                    'model' => getenv('AGENTIC_TEST_MODEL') ?: 'mimo-v2-pro',
                ],
            ],
            defaultProvider: 'test',
            retryConfig: ['max_attempts' => 2, 'base_delay_ms' => 1000, 'max_delay_ms' => 5000],
        );
    }

    private function createRunner(?ToolRegistry $registry = null): AgentRunner
    {
        return new AgentRunner(
            llmClient: $this->createLlmClient(),
            promptBuilder: new PromptBuilder(),
            toolRegistry: $registry ?? new ToolRegistry(),
            guardrailRunner: new GuardrailRunner(),
            middleware: new MiddlewarePipeline(),
            toolGuardrailRunner: new ToolGuardrailRunner(),
            permissionPolicy: new ConfigToolPermissionPolicy(),
        );
    }

    public function testAgentReturnsCompleteResponse(): void
    {
        self::skipIfNoKey();
        $runner = $this->createRunner();

        $result = $runner->run(
            [['role' => 'user', 'content' => 'Say exactly "Agent is working" and nothing else.']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'TestAgent', content: 'You are a test assistant.'),
                'system_prompt' => '',
                'tools' => [],
                'scene' => 'test',
            ],
        );

        $this->assertTrue($result->isComplete());
        $this->assertNotEmpty($result->content);
        $this->assertSame(1, $result->iterations);
    }

    public function testAgentCallsToolAndReturnsFinalAnswer(): void
    {
        self::skipIfNoKey();

        // Register a real tool
        $registry = new ToolRegistry();
        $registry->register(new class implements ToolInterface {
            public function name(): string { return 'get_time'; }
            public function description(): string { return 'Get the current time in a timezone'; }
            public function parameters(): array {
                return [
                    'type' => 'object',
                    'properties' => [
                        'timezone' => ['type' => 'string', 'description' => 'Timezone like Asia/Shanghai'],
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

        $runner = $this->createRunner($registry);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'What time is it in Tokyo? Use the get_time tool.']],
            [
                'max_iterations' => 5,
                'persona' => new Persona(name: 'TimeAgent', content: 'You help with time queries.'),
                'system_prompt' => '',
                'tools' => ['get_time'],
                'scene' => 'test',
            ],
        );

        $this->assertTrue($result->isComplete());
        $this->assertNotEmpty($result->content);
        // Should have used at least 1 tool call (2 iterations: tool call + final response)
        $this->assertGreaterThanOrEqual(1, $result->toolCalls);
    }

    public function testAgentRecordsTokenUsage(): void
    {
        self::skipIfNoKey();
        $runner = $this->createRunner();

        $result = $runner->run(
            [['role' => 'user', 'content' => 'Hello']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'Test', content: 'You are a test assistant.'),
                'system_prompt' => '',
                'tools' => [],
                'scene' => 'test',
            ],
        );

        $this->assertTrue($result->isComplete());
        $this->assertGreaterThan(0, $result->promptTokens);
        $this->assertGreaterThan(0, $result->completionTokens);
        $this->assertGreaterThan(0, $result->elapsedMs);
    }

    public function testAgentEmitsEvents(): void
    {
        self::skipIfNoKey();
        $runner = $this->createRunner();

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner->run(
            [['role' => 'user', 'content' => 'Hi']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'Test', content: 'You are a test assistant.'),
                'system_prompt' => '',
                'tools' => [],
                'scene' => 'test',
            ],
            [],
            $onEvent,
        );

        $this->assertContains('started', $events);
        $this->assertContains('thinking', $events);
        $this->assertContains('complete', $events);
    }
}
