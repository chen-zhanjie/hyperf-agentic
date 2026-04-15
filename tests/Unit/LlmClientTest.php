<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\LlmClient;

class LlmClientTest extends TestCase
{
    private function makeClient(
        array $providers = [],
        string $default = 'openai',
        array $retry = [],
        ?callable $adapter = null,
    ): LlmClient {
        return new LlmClient(
            providerConfigs: $providers,
            defaultProvider: $default,
            retryConfig: $retry,
            adapterFactory: $adapter,
        );
    }

    // --- Provider management ---

    public function testGetAvailableProviders(): void
    {
        $client = $this->makeClient([
            'openai' => ['model' => 'gpt-4o'],
            'deepseek' => ['model' => 'deepseek-chat'],
        ]);

        $providers = $client->getAvailableProviders();
        $this->assertContains('openai', $providers);
        $this->assertContains('deepseek', $providers);
    }

    public function testHasProvider(): void
    {
        $client = $this->makeClient(['openai' => ['model' => 'gpt-4o']]);
        $this->assertTrue($client->hasProvider('openai'));
        $this->assertFalse($client->hasProvider('anthropic'));
    }

    public function testGetDefaultProvider(): void
    {
        $client = $this->makeClient([], 'deepseek');
        $this->assertSame('deepseek', $client->getDefaultProvider());
    }

    // --- Chat via adapter ---

    public function testChatCallsAdapterFactory(): void
    {
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            adapter: fn(string $op, string $provider, array $config, array $messages, array $options) => 'Hello!',
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('Hello!', $result);
    }

    public function testChatPassesCorrectProvider(): void
    {
        $capturedProvider = null;
        $client = $this->makeClient(
            providers: [
                'openai' => ['model' => 'gpt-4o'],
                'deepseek' => ['model' => 'deepseek-chat'],
            ],
            adapter: function (string $op, string $provider, ...$args) use (&$capturedProvider) {
                $capturedProvider = $provider;
                return 'ok';
            },
        );

        $client->chat([['role' => 'user', 'content' => 'hi']], ['provider' => 'deepseek']);
        $this->assertSame('deepseek', $capturedProvider);
    }

    public function testChatThrowsWithoutAdapter(): void
    {
        $client = $this->makeClient(providers: ['openai' => ['model' => 'gpt-4o']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('adapter not configured');
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testChatFailoversWhenProviderUnknown(): void
    {
        // When requesting unknown provider, it should failover to 'openai' which is configured
        $calledProvider = null;
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            retry: ['max_attempts' => 1, 'base_delay_ms' => 1, 'max_delay_ms' => 1],
            adapter: function (string $op, string $provider, ...$args) use (&$calledProvider) {
                $calledProvider = $provider;
                return 'failover response';
            },
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']], ['provider' => 'unknown']);
        $this->assertSame('failover response', $result);
        // Should have failover'd to 'openai'
        $this->assertSame('openai', $calledProvider);
    }

    // --- Retry ---

    public function testChatRetriesOnFailure(): void
    {
        $attempts = 0;
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            retry: ['max_attempts' => 3, 'base_delay_ms' => 1, 'max_delay_ms' => 10],
            adapter: function () use (&$attempts) {
                ++$attempts;
                if ($attempts < 3) {
                    throw new \RuntimeException('temporary failure');
                }
                return 'success on attempt 3';
            },
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('success on attempt 3', $result);
        $this->assertSame(3, $attempts);
    }

    public function testChatFailsAfterMaxRetries(): void
    {
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            retry: ['max_attempts' => 2, 'base_delay_ms' => 1, 'max_delay_ms' => 10],
            adapter: function () { throw new \RuntimeException('always fails'); },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LLM call failed');
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    // --- Failover ---

    public function testChatFailoversToSecondProvider(): void
    {
        $calledProviders = [];
        $client = $this->makeClient(
            providers: [
                'primary' => ['model' => 'gpt-4o'],
                'fallback' => ['model' => 'fallback-model'],
            ],
            retry: ['max_attempts' => 1, 'base_delay_ms' => 1, 'max_delay_ms' => 10],
            adapter: function (string $op, string $provider) use (&$calledProviders) {
                $calledProviders[] = $provider;
                if ($provider === 'primary') {
                    throw new \RuntimeException('primary down');
                }
                return 'fallback response';
            },
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('fallback response', $result);
        $this->assertContains('primary', $calledProviders);
        $this->assertContains('fallback', $calledProviders);
    }

    // --- Options ---

    public function testChatMergesModelFromProviderConfig(): void
    {
        $capturedOptions = null;
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o-mini']],
            adapter: function (string $op, string $p, array $c, array $m, array $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return 'ok';
            },
        );

        $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('gpt-4o-mini', $capturedOptions['model']);
    }

    public function testChatOptionsOverrideModel(): void
    {
        $capturedOptions = null;
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            adapter: function (string $op, string $p, array $c, array $m, array $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return 'ok';
            },
        );

        $client->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'gpt-4o-mini']);
        $this->assertSame('gpt-4o-mini', $capturedOptions['model']);
    }

    // --- Retry config ---

    public function testGetRetryConfig(): void
    {
        $client = $this->makeClient([], retry: ['max_attempts' => 5]);
        $config = $client->getRetryConfig();
        $this->assertSame(5, $config['max_attempts']);
        $this->assertSame(1000, $config['base_delay_ms']);
        $this->assertSame(30000, $config['max_delay_ms']);
    }
}
