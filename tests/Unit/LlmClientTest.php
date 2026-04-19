<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\LlmMiddlewarePipeline;
use ChenZhanjie\Agentic\LlmResponse;

class LlmClientTest extends TestCase
{
    private function makeClient(
        array $providers = [],
        string $default = 'openai',
        array $retry = [],
        ?callable $adapter = null,
        ?LlmMiddlewarePipeline $middleware = null,
    ): LlmClient {
        return new LlmClient(
            providerConfigs: $providers,
            defaultProvider: $default,
            retryConfig: $retry,
            adapterFactory: $adapter,
            middleware: $middleware,
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
            adapter: fn(string $op, string $provider, array $config, array $messages, array $options) => ['content' => 'Hello!', 'usage' => []],
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertInstanceOf(LlmResponse::class, $result);
        $this->assertSame('Hello!', $result->content);
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
                return ['content' => 'ok', 'usage' => []];
            },
        );

        $client->chat([['role' => 'user', 'content' => 'hi']], ['provider' => 'deepseek']);
        $this->assertSame('deepseek', $capturedProvider);
    }

    public function testChatThrowsWithoutAdapter(): void
    {
        $client = $this->makeClient(providers: ['openai' => ['model' => 'gpt-4o']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('base_url');
        $this->expectExceptionMessage('api_key');
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testChatFailoversWhenProviderUnknown(): void
    {
        $calledProvider = null;
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            retry: ['max_attempts' => 1, 'base_delay_ms' => 1, 'max_delay_ms' => 1],
            adapter: function (string $op, string $provider, ...$args) use (&$calledProvider) {
                $calledProvider = $provider;
                return ['content' => 'failover response', 'usage' => []];
            },
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']], ['provider' => 'unknown']);
        $this->assertSame('failover response', $result->content);
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
                return ['content' => 'success on attempt 3', 'usage' => []];
            },
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('success on attempt 3', $result->content);
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
                return ['content' => 'fallback response', 'usage' => []];
            },
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('fallback response', $result->content);
        $this->assertSame('fallback', $result->provider);
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
                return ['content' => 'ok', 'usage' => []];
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
                return ['content' => 'ok', 'usage' => []];
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

    // --- LlmMiddlewarePipeline integration ---

    public function testChatInvokesMiddlewareBeforeCallAndAfterCall(): void
    {
        $calls = [];
        $middleware = new class($calls) implements \ChenZhanjie\Agentic\Contract\LlmMiddlewareInterface {
            public function __construct(public array &$calls) {}
            public function beforeCall(\ChenZhanjie\Agentic\LlmCallRequest $request): \ChenZhanjie\Agentic\LlmCallRequest
            {
                $this->calls[] = 'beforeCall';
                return $request;
            }
            public function afterCall(\ChenZhanjie\Agentic\LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                $this->calls[] = 'afterCall';
                return null;
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void
            {
                $this->calls[] = "onRetry:{$provider}:{$attempt}";
            }
            public function onFailover(string $fromProvider, string $toProvider): void
            {
                $this->calls[] = "onFailover:{$fromProvider}→{$toProvider}";
            }
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($middleware);

        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            adapter: fn() => ['content' => 'ok', 'usage' => []],
            middleware: $pipeline,
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('ok', $result->content);
        $this->assertContains('beforeCall', $middleware->calls);
        $this->assertContains('afterCall', $middleware->calls);
        $this->assertStringNotContainsString('onRetry', implode(',', $middleware->calls));
    }

    public function testChatInvokesOnRetryOnFailure(): void
    {
        $calls = [];
        $middleware = new class($calls) implements \ChenZhanjie\Agentic\Contract\LlmMiddlewareInterface {
            public function __construct(public array &$calls) {}
            public function beforeCall(\ChenZhanjie\Agentic\LlmCallRequest $request): \ChenZhanjie\Agentic\LlmCallRequest
            {
                $this->calls[] = 'beforeCall';
                return $request;
            }
            public function afterCall(\ChenZhanjie\Agentic\LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                $this->calls[] = 'afterCall';
                return null;
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void
            {
                $this->calls[] = "onRetry:{$attempt}";
            }
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($middleware);

        $attempts = 0;
        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            retry: ['max_attempts' => 2, 'base_delay_ms' => 1, 'max_delay_ms' => 1],
            adapter: function () use (&$attempts) {
                ++$attempts;
                if ($attempts < 2) {
                    throw new \RuntimeException('temporary failure');
                }
                return ['content' => 'recovered', 'usage' => []];
            },
            middleware: $pipeline,
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('recovered', $result->content);
        $this->assertContains('onRetry:1', $middleware->calls);
    }

    public function testChatInvokesOnFailoverWhenProviderFails(): void
    {
        $calls = [];
        $middleware = new class($calls) implements \ChenZhanjie\Agentic\Contract\LlmMiddlewareInterface {
            public function __construct(public array &$calls) {}
            public function beforeCall(\ChenZhanjie\Agentic\LlmCallRequest $request): \ChenZhanjie\Agentic\LlmCallRequest
            {
                $this->calls[] = 'beforeCall';
                return $request;
            }
            public function afterCall(\ChenZhanjie\Agentic\LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                $this->calls[] = 'afterCall';
                return null;
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void
            {
                $this->calls[] = "onFailover:{$fromProvider}→{$toProvider}";
            }
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($middleware);

        $client = $this->makeClient(
            providers: [
                'primary' => ['model' => 'gpt-4o'],
                'fallback' => ['model' => 'fallback-model'],
            ],
            retry: ['max_attempts' => 1, 'base_delay_ms' => 1, 'max_delay_ms' => 1],
            adapter: function (string $op, string $provider) {
                if ($provider === 'primary') {
                    throw new \RuntimeException('primary down');
                }
                return ['content' => 'fallback response', 'usage' => []];
            },
            middleware: $pipeline,
        );

        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('fallback response', $result->content);
        $this->assertContains('onFailover:primary→fallback', $middleware->calls);
    }

    public function testMiddlewareBeforeCallCanModifyMessages(): void
    {
        $captured = null;
        $middleware = new class implements \ChenZhanjie\Agentic\Contract\LlmMiddlewareInterface {
            public function beforeCall(\ChenZhanjie\Agentic\LlmCallRequest $request): \ChenZhanjie\Agentic\LlmCallRequest
            {
                return $request->with([
                    'messages' => array_merge($request->messages, [['role' => 'system', 'content' => 'injected']]),
                ]);
            }
            public function afterCall(\ChenZhanjie\Agentic\LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                return null;
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($middleware);

        $client = $this->makeClient(
            providers: ['openai' => ['model' => 'gpt-4o']],
            adapter: function (string $op, string $p, array $c, array $messages) use (&$captured) {
                $captured = $messages;
                return ['content' => 'ok', 'usage' => []];
            },
            middleware: $pipeline,
        );

        $client->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertCount(2, $captured);
        $this->assertSame('injected', $captured[1]['content']);
    }
}
