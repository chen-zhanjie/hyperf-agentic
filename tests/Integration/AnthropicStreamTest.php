<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

use ChenZhanjie\Agentic\AgentRunner;
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
 * Streaming integration tests for the Anthropic Messages API protocol.
 *
 * @group integration
 */
class AnthropicStreamTest extends TestCase
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

    private function createRunner(): AgentRunner
    {
        return new AgentRunner(
            llmClient: $this->createAnthropicClient(),
            promptBuilder: new PromptBuilder(),
            toolRegistry: new ToolRegistry(),
            guardrailRunner: new GuardrailRunner(),
            middleware: new MiddlewarePipeline(),
            toolGuardrailRunner: new ToolGuardrailRunner(),
            permissionPolicy: new ConfigToolPermissionPolicy(),
        );
    }

    public function testAnthropicChatStreamReturnsNormalizedArray(): void
    {
        self::skipIfNoKey();
        $client = $this->createAnthropicClient();

        $chunks = [];
        $onChunk = function (array $chunk) use (&$chunks): void {
            if (isset($chunk['content'])) {
                $chunks[] = $chunk['content'];
            }
        };

        $result = $client->chatStream(
            [['role' => 'user', 'content' => 'Say exactly "anthropic stream" and nothing else.']],
            [],
            $onChunk,
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertNotEmpty($result['content']);
        $this->assertNotEmpty($chunks);
        $this->assertSame($result['content'], implode('', $chunks));
    }

    public function testAnthropicRunStreamEmitsTextDeltaAndComplete(): void
    {
        self::skipIfNoKey();
        $runner = $this->createRunner();

        $events = [];
        $textChunks = [];
        $onEvent = function (string $type, array $payload) use (&$events, &$textChunks): void {
            $events[] = $type;
            if ($type === 'text_delta' && isset($payload['content'])) {
                $textChunks[] = $payload['content'];
            }
        };

        $result = $runner->runStream(
            [['role' => 'user', 'content' => 'Say exactly "streaming ok" and nothing else.']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'TestAgent', content: 'You are a test assistant.'),
                'system_prompt' => '',
                'tools' => [],
                'scene' => 'test',
            ],
            [],
            $onEvent,
        );

        $this->assertTrue($result->isComplete());
        $this->assertNotEmpty($result->content);
        $this->assertContains('started', $events);
        $this->assertContains('thinking', $events);
        $this->assertContains('text_delta', $events);
        $this->assertContains('complete', $events);
        $this->assertNotEmpty($textChunks);
        $this->assertSame($result->content, implode('', $textChunks));
    }
}
