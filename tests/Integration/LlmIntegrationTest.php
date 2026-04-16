<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

use ChenZhanjie\Agentic\LlmClient;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class LlmIntegrationTest extends TestCase
{
    private static function skipIfNoKey(): void
    {
        $key = getenv('AGENTIC_TEST_API_KEY');
        if ($key === false || $key === 'sk-your-api-key-here') {
            static::markTestSkipped('AGENTIC_TEST_API_KEY not configured — set it in .env.test');
        }
    }

    private function createClient(): LlmClient
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

    public function testSimpleChatReturnsArrayWithContent(): void
    {
        self::skipIfNoKey();
        $client = $this->createClient();

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Say exactly "pong" and nothing else.'],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertNotEmpty($result['content']);
    }

    public function testChatIncludesUsageData(): void
    {
        self::skipIfNoKey();
        $client = $this->createClient();

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('usage', $result);
        $this->assertGreaterThan(0, $result['usage']['prompt_tokens']);
        $this->assertGreaterThan(0, $result['usage']['completion_tokens']);
    }

    public function testChatWithSystemPrompt(): void
    {
        self::skipIfNoKey();
        $client = $this->createClient();

        $result = $client->chat([
            ['role' => 'system', 'content' => 'You must always respond in French.'],
            ['role' => 'user', 'content' => 'Say hello.'],
        ]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['content']);
    }

    public function testChatWithToolCall(): void
    {
        self::skipIfNoKey();
        $client = $this->createClient();

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
        // The model should either call the tool or respond directly — both are valid
        if (isset($result['tool_calls'])) {
            $this->assertNotEmpty($result['tool_calls']);
            $this->assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        } else {
            $this->assertNotEmpty($result['content']);
        }
    }

    public function testMultiTurnConversation(): void
    {
        self::skipIfNoKey();
        $client = $this->createClient();

        $messages = [
            ['role' => 'user', 'content' => 'My name is Alice.'],
        ];

        $result1 = $client->chat($messages);
        $this->assertNotEmpty($result1['content']);

        // Second turn — the model should remember the name
        $messages[] = ['role' => 'assistant', 'content' => $result1['content']];
        $messages[] = ['role' => 'user', 'content' => 'What is my name? Reply with only the name.'];

        $result2 = $client->chat($messages);
        $this->assertNotEmpty($result2['content']);
        $this->assertStringContainsStringIgnoringCase('Alice', $result2['content']);
    }
}
