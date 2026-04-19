<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\AgentMiddlewarePipeline;
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
    // ── Anthropic Protocol: LLM Client ──

    public function testAnthropicSimpleChat(): void
    {
        IntegrationTestConfig::skipIfNoAnthropicKey();
        $client = IntegrationTestConfig::createAnthropicLlmClient();

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Say exactly "pong" and nothing else.'],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertNotEmpty($result['content']);
    }

    public function testAnthropicIncludesUsage(): void
    {
        IntegrationTestConfig::skipIfNoAnthropicKey();
        $client = IntegrationTestConfig::createAnthropicLlmClient();

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $this->assertArrayHasKey('usage', $result);
        $this->assertGreaterThan(0, $result['usage']['prompt_tokens']);
        $this->assertGreaterThan(0, $result['usage']['completion_tokens']);
    }

    public function testAnthropicWithSystemPrompt(): void
    {
        IntegrationTestConfig::skipIfNoAnthropicKey();
        $client = IntegrationTestConfig::createAnthropicLlmClient();

        $result = $client->chat([
            ['role' => 'system', 'content' => 'You must always respond in French.'],
            ['role' => 'user', 'content' => 'Say hello.'],
        ]);

        $this->assertNotEmpty($result['content']);
    }

    public function testAnthropicToolCalling(): void
    {
        IntegrationTestConfig::skipIfNoAnthropicKey();
        $client = IntegrationTestConfig::createAnthropicLlmClient();

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
        IntegrationTestConfig::skipIfNoAnthropicKey();

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

        $runner = new AgentRunner(
            llmClient: IntegrationTestConfig::createAnthropicLlmClient(),
            promptBuilder: new PromptBuilder(),
            toolRegistry: $registry,
            guardrailRunner: new GuardrailRunner(),
            agentMiddleware: new AgentMiddlewarePipeline(),
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
        IntegrationTestConfig::skipIfNoOpenAIKey();
        IntegrationTestConfig::skipIfNoAnthropicKey();

        $openaiResult = IntegrationTestConfig::createOpenAiLlmClient()->chat([
            ['role' => 'user', 'content' => 'Say exactly "test" and nothing else.'],
        ]);

        $anthropicResult = IntegrationTestConfig::createAnthropicLlmClient()->chat([
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
