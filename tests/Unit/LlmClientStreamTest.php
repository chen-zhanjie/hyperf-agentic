<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\LlmClient;
use PHPUnit\Framework\TestCase;

class LlmClientStreamTest extends TestCase
{
    public function testChatStreamRoutesToOpenAiAdapter(): void
    {
        $client = new LlmClient(
            providerConfigs: [
                'openai' => [
                    'protocol' => 'openai',
                    'base_url' => 'https://api.openai.com/v1',
                    'api_key' => 'test-key',
                    'model' => 'gpt-4o',
                ],
            ],
            defaultProvider: 'openai',
        );

        $called = false;
        $onChunk = function (array $chunk) use (&$called): void {
            $called = true;
        };

        try {
            $client->chatStream([['role' => 'user', 'content' => 'hi']], [], $onChunk);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('LLM call failed', $e->getMessage());
        }
    }

    public function testChatStreamRoutesToAnthropicAdapter(): void
    {
        $client = new LlmClient(
            providerConfigs: [
                'anthropic' => [
                    'protocol' => 'anthropic',
                    'base_url' => 'https://api.anthropic.com/v1',
                    'api_key' => 'test-key',
                    'model' => 'claude-sonnet-4-20250514',
                ],
            ],
            defaultProvider: 'anthropic',
        );

        $onChunk = function (array $chunk): void {};

        try {
            $client->chatStream([['role' => 'user', 'content' => 'hi']], [], $onChunk);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('LLM call failed', $e->getMessage());
        }
    }

    public function testChatStreamUsesAdapterFactoryWhenProvided(): void
    {
        $captured = [];
        $factory = function (string $method, string $provider, array $config, array $messages, array $options, callable $onChunk) use (&$captured): array {
            $captured = compact('method', 'provider', 'config');
            return ['content' => 'factory result'];
        };

        $client = new LlmClient(
            providerConfigs: [
                'custom' => [
                    'protocol' => 'openai',
                    'base_url' => 'https://example.com',
                    'api_key' => 'key',
                ],
            ],
            defaultProvider: 'custom',
            adapterFactory: $factory,
        );

        $result = $client->chatStream([['role' => 'user', 'content' => 'hi']], [], function (array $chunk) {});

        $this->assertSame('chatStream', $captured['method']);
        $this->assertSame('custom', $captured['provider']);
        $this->assertSame('factory result', $result->content);
    }

    public function testChatStreamFailsOverToNextProvider(): void
    {
        $callLog = [];
        $factory = function (string $method, string $provider, array $config, array $messages, array $options, callable $onChunk) use (&$callLog): array {
            $callLog[] = $provider;
            if ($provider === 'primary') {
                throw new \RuntimeException('Primary down');
            }
            return ['content' => 'fallback result'];
        };

        $client = new LlmClient(
            providerConfigs: [
                'primary' => ['base_url' => 'https://primary.com', 'api_key' => 'pk'],
                'fallback' => ['base_url' => 'https://fallback.com', 'api_key' => 'fk'],
            ],
            defaultProvider: 'primary',
            retryConfig: ['max_attempts' => 1],
            adapterFactory: $factory,
        );

        $result = $client->chatStream([['role' => 'user', 'content' => 'hi']], [], function (array $chunk) {});

        $this->assertSame(['primary', 'fallback'], $callLog);
        $this->assertSame('fallback result', $result->content);
    }
}
