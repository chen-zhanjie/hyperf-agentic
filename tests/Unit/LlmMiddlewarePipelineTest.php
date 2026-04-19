<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\LlmCallRequest;
use ChenZhanjie\Agentic\LlmMiddlewarePipeline;
use ChenZhanjie\Agentic\LlmResponse;
use ChenZhanjie\Agentic\Contract\LlmMiddlewareInterface;
use PHPUnit\Framework\TestCase;

class LlmMiddlewarePipelineTest extends TestCase
{
    // ── beforeCall ──

    public function testBeforeCallWithNoMiddlewaresReturnsRequestUnchanged(): void
    {
        $pipeline = new LlmMiddlewarePipeline();
        $request = new LlmCallRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            options: [],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $result = $pipeline->beforeCall($request);
        $this->assertSame($request, $result);
    }

    public function testBeforeCallChainsMiddlewares(): void
    {
        $mw1 = new class implements LlmMiddlewareInterface {
            public function beforeCall(LlmCallRequest $request): LlmCallRequest
            {
                return $request->with(['options' => array_merge($request->options, ['injected_1' => true])]);
            }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $mw2 = new class implements LlmMiddlewareInterface {
            public function beforeCall(LlmCallRequest $request): LlmCallRequest
            {
                return $request->with(['options' => array_merge($request->options, ['injected_2' => true])]);
            }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($mw1);
        $pipeline->add($mw2);

        $request = new LlmCallRequest([['role' => 'user', 'content' => 'hi']], [], 'openai', 'gpt-4o');
        $result = $pipeline->beforeCall($request);

        $this->assertTrue($result->options['injected_1']);
        $this->assertTrue($result->options['injected_2']);
    }

    // ── afterCall (fault-tolerant) ──

    public function testAfterCallCatchesExceptionsAndContinues(): void
    {
        $order = [];

        $failingMw = new class($order) implements LlmMiddlewareInterface {
            public function __construct(public array &$order) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                $this->order[] = 'failing';
                throw new \RuntimeException('afterCall explosion');
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $goodMw = new class($order) implements LlmMiddlewareInterface {
            public function __construct(public array &$order) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                $this->order[] = 'good';
                return null;
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($failingMw);
        $pipeline->add($goodMw);

        $request = new LlmCallRequest([], [], 'openai', 'gpt-4o');
        $response = new LlmResponse('ok', [], 'openai', 'gpt-4o');

        $pipeline->afterCall($request, $response);

        $this->assertContains('failing', $order);
        $this->assertContains('good', $order);
    }

    // ── onRetry ──

    public function testOnRetryNotifiesAllMiddlewares(): void
    {
        $notified = [];

        $mw = new class($notified) implements LlmMiddlewareInterface {
            public function __construct(public array &$notified) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void
            {
                $this->notified[] = ['provider' => $provider, 'attempt' => $attempt];
            }
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($mw);

        $pipeline->onRetry('openai', 2, new \RuntimeException('timeout'));

        $this->assertSame([['provider' => 'openai', 'attempt' => 2]], $mw->notified);
    }

    // ── onFailover ──

    public function testOnFailoverNotifiesAllMiddlewares(): void
    {
        $failovers = [];

        $mw = new class($failovers) implements LlmMiddlewareInterface {
            public function __construct(public array &$failovers) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void
            {
                $this->failovers[] = ['from' => $fromProvider, 'to' => $toProvider];
            }
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($mw);

        $pipeline->onFailover('primary', 'fallback');

        $this->assertSame([['from' => 'primary', 'to' => 'fallback']], $mw->failovers);
    }

    // ── onRetry fault tolerance ──

    public function testOnRetryCatchesExceptionsAndContinues(): void
    {
        $order = [];

        $failingMw = new class($order) implements LlmMiddlewareInterface {
            public function __construct(public array &$order) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void
            {
                $this->order[] = 'failing_onRetry';
                throw new \RuntimeException('onRetry explosion');
            }
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $goodMw = new class($order) implements LlmMiddlewareInterface {
            public function __construct(public array &$order) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void
            {
                $this->order[] = 'good_onRetry';
            }
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($failingMw);
        $pipeline->add($goodMw);

        $pipeline->onRetry('openai', 1, new \RuntimeException('timeout'));

        $this->assertContains('failing_onRetry', $order);
        $this->assertContains('good_onRetry', $order);
    }

    // ── onFailover fault tolerance ──

    public function testOnFailoverCatchesExceptionsAndContinues(): void
    {
        $order = [];

        $failingMw = new class($order) implements LlmMiddlewareInterface {
            public function __construct(public array &$order) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void
            {
                $this->order[] = 'failing_onFailover';
                throw new \RuntimeException('onFailover explosion');
            }
            public function onChunk(array $chunk): void {}
        };

        $goodMw = new class($order) implements LlmMiddlewareInterface {
            public function __construct(public array &$order) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void
            {
                $this->order[] = 'good_onFailover';
            }
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($failingMw);
        $pipeline->add($goodMw);

        $pipeline->onFailover('primary', 'fallback');

        $this->assertContains('failing_onFailover', $order);
        $this->assertContains('good_onFailover', $order);
    }

    // ── afterCall response replacement ──

    public function testAfterCallReturningModifiedResponseReplacesOriginal(): void
    {
        $mw = new class implements LlmMiddlewareInterface {
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                return new LlmResponse(
                    content: $response->content . ' [sanitized]',
                    usage: $response->usage,
                    provider: $response->provider,
                    model: $response->model,
                );
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($mw);

        $request = new LlmCallRequest([], [], 'openai', 'gpt-4o');
        $original = new LlmResponse('hello', [], 'openai', 'gpt-4o');

        $result = $pipeline->afterCall($request, $original);

        $this->assertNotSame($original, $result);
        $this->assertSame('hello [sanitized]', $result->content);
    }

    public function testAfterCallReturningNullPreservesOriginal(): void
    {
        $mw = new class implements LlmMiddlewareInterface {
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse
            {
                return null;
            }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void {}
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($mw);

        $request = new LlmCallRequest([], [], 'openai', 'gpt-4o');
        $original = new LlmResponse('hello', [], 'openai', 'gpt-4o');

        $result = $pipeline->afterCall($request, $original);

        $this->assertSame($original, $result);
    }

    // ── onChunk ──

    public function testOnChunkIsCalledForEachChunk(): void
    {
        $received = [];

        $mw = new class($received) implements LlmMiddlewareInterface {
            public function __construct(public array &$received) {}
            public function beforeCall(LlmCallRequest $request): LlmCallRequest { return $request; }
            public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse { return null; }
            public function onRetry(string $provider, int $attempt, \Throwable $error): void {}
            public function onFailover(string $fromProvider, string $toProvider): void {}
            public function onChunk(array $chunk): void
            {
                $this->received[] = $chunk;
            }
        };

        $pipeline = new LlmMiddlewarePipeline();
        $pipeline->add($mw);

        $pipeline->onChunk(['text' => 'Hello']);
        $pipeline->onChunk(['text' => ' world']);

        $this->assertSame([['text' => 'Hello'], ['text' => ' world']], $mw->received);
    }
}
