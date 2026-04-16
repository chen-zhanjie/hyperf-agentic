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
 * Integration tests for the Anthropic Messages API protocol.
 *
 * @group integration
 */
class AnthropicIntegrationTest extends TestCase
{
    private static function skipIfNoKey(): void
    {
        $key = getenv('AGENTIC_TEST_API_KEY');
        if ($key === false || $key === 'sk-your-api-key-here') {
            static::markTestSkipped('AGENTIC_TEST_API_KEY not configured — set it in .env.test');
        }
    }

    private function createAnthropicClient(): LlmClient
    {
        return new LlmClient(
            providerConfigs: [
                'anthropic' => [
                    'protocol' => 'anthropic',
                    'base_url' => 'https://api.xiaomimimo.com/anthropic/v1',
                    'api_key' => getenv('AGENTIC_TEST_API_KEY'),
                    'model' => 'mimo-v2-pro',
                ],
            ],
            defaultProvider: 'anthropic',
            retryConfig: ['max_attempts' => 2, 'base_delay_ms' => 1000, 'max_delay_ms' => 5000],
        );
    }

    private function createOpenAiClient(): LlmClient
    {
        return new LlmClient(
            providerConfigs: [
                'openai' => [
                    'protocol' => 'openai',
                    'base_url' => 'https://api.xiaomimimo.com/v1',
                    'api_key' => getenv('AGENTIC_TEST_API_KEY'),
                    'model' => 'mimo-v2-pro',
                ],
            ],
            defaultProvider: 'openai',
            retryConfig: ['max_attempts' => 2, 'base_delay_ms' => 1000, 'max_delay_ms' => 5000],
        );
    }

    // ── Anthropic Protocol: LLM Client ──

    public function testAnthropicSimpleChat(): void
    {
        self::skipIfNoKey();
        $client = $this->createAnthropicClient();

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Say exactly "pong" and nothing else.'],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertNotEmpty($result['content']);
    }

    public function testAnthropicIncludesUsage(): void
    {
        self::skipIfNoKey();
        $client = $this->createAnthropicClient();

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $this->assertArrayHasKey('usage', $result);
        $this->assertGreaterThan(0, $result['usage']['prompt_tokens']);
        $this->assertGreaterThan(0, $result['usage']['completion_tokens']);
    }

    public function testAnthropicWithSystemPrompt(): void
    {
        self::skipIfNoKey();
        $client = $this->createAnthropicClient();

        $result = $client->chat([
            ['role' => 'system', 'content' => 'You must always respond in French.'],
            ['role' => 'user', 'content' => 'Say hello.'],
        ]);

        $this->assertNotEmpty($result['content']);
    }

    public function testAnthropicToolCalling(): void
    {
        self::skipIfNoKey();
        $client = $this->createAnthropicClient();

        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get the current weather for a city',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string', 'description' => 'City name'],
                        ],
                        'required' => ['city'],
                    ],
                ],
            ],
        ];

        $result = $client->chat(
            [['role' => 'user', 'content' => 'What is the weather in Beijing?']],
            ['tools' => $tools],
        );

        $this->assertIsArray($result);
        // Model should either call the tool or answer directly
        if (isset($result['tool_calls'])) {
            $this->assertNotEmpty($result['tool_calls']);
            $this->assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
            // Verify the tool call arguments are valid JSON
            $args = json_decode($result['tool_calls'][0]['function']['arguments'], true);
            $this->assertNotNull($args);
            $this->assertArrayHasKey('city', $args);
        } else {
            $this->assertNotEmpty($result['content']);
        }
    }

    // ── Anthropic Protocol: Agent Loop ──

    public function testAnthropicAgentWithToolCall(): void
    {
        self::skipIfNoKey();

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
                    $time = (new \DateTime())->format('Y-m-d H:i:s') . ' (UTC)';
                }
                return "Current time in {$tz}: {$time}";
            }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        });

        $client = $this->createAnthropicClient();
        $runner = new AgentRunner(
            llmClient: $client,
            promptBuilder: new PromptBuilder(),
            toolRegistry: $registry,
            guardrailRunner: new GuardrailRunner(),
            middleware: new MiddlewarePipeline(),
            toolGuardrailRunner: new ToolGuardrailRunner(),
            permissionPolicy: new ConfigToolPermissionPolicy(),
        );

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
        $this->assertGreaterThanOrEqual(1, $result->toolCalls);
    }

    // ── Both Protocols: Same SDK, Different Backends ──

    public function testBothProtocolsReturnSameShape(): void
    {
        self::skipIfNoKey();

        $openaiResult = $this->createOpenAiClient()->chat([
            ['role' => 'user', 'content' => 'Say exactly "test" and nothing else.'],
        ]);

        $anthropicResult = $this->createAnthropicClient()->chat([
            ['role' => 'user', 'content' => 'Say exactly "test" and nothing else.'],
        ]);

        // Both must return the same normalized shape
        foreach ([$openaiResult, $anthropicResult] as $result) {
            $this->assertArrayHasKey('content', $result);
            $this->assertArrayHasKey('usage', $result);
            $this->assertArrayHasKey('prompt_tokens', $result['usage']);
            $this->assertArrayHasKey('completion_tokens', $result['usage']);
        }
    }
}
