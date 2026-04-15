<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Contract\MiddlewareInterface;

class MiddlewarePipelineTest extends TestCase
{
    // ── beforeLoop ──

    public function testBeforeLoopWithNoMiddlewaresReturnsMessagesUnchanged(): void
    {
        $pipeline = new MiddlewarePipeline();
        $messages = [['role' => 'user', 'content' => 'hello']];
        $result = $pipeline->beforeLoop($messages, []);

        $this->assertSame($messages, $result);
    }

    public function testBeforeLoopPassesMessagesThroughSingleMiddleware(): void
    {
        $middleware = $this->createSpyMiddleware('beforeLoop', function (array $messages): array {
            $messages[] = ['role' => 'system', 'content' => 'injected'];
            return $messages;
        });
        $pipeline = new MiddlewarePipeline();
        $pipeline->add($middleware);

        $messages = [['role' => 'user', 'content' => 'hello']];
        $result = $pipeline->beforeLoop($messages, ['agent' => 'test']);

        $this->assertCount(2, $result);
        $this->assertSame('injected', $result[1]['content']);
    }

    public function testBeforeLoopChainsMultipleMiddlewares(): void
    {
        $mw1 = $this->createSpyMiddleware('beforeLoop', function (array $messages): array {
            $messages[] = ['role' => 'system', 'content' => 'first'];
            return $messages;
        });
        $mw2 = $this->createSpyMiddleware('beforeLoop', function (array $messages): array {
            $messages[] = ['role' => 'system', 'content' => 'second'];
            return $messages;
        });

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw1);
        $pipeline->add($mw2);

        $result = $pipeline->beforeLoop([['role' => 'user', 'content' => 'hi']], []);

        $this->assertCount(3, $result);
        $this->assertSame('first', $result[1]['content']);
        $this->assertSame('second', $result[2]['content']);
    }

    // ── afterLoop ──

    public function testAfterLoopWithNoMiddlewaresReturnsResultUnchanged(): void
    {
        $pipeline = new MiddlewarePipeline();
        $result = AgentResult::complete('done');
        $returned = $pipeline->afterLoop($result);

        $this->assertSame($result, $returned);
    }

    public function testAfterLoopChainsMiddlewares(): void
    {
        $mw = new class implements MiddlewareInterface {
            public bool $called = false;
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult
            {
                $this->called = true;
                return $result;
            }
            public function beforeLlmCall(array $messages, array $options): array { return $options; }
            public function afterLlmCall(array $response, array $usage): void {}
            public function beforeToolCall(string $name, array $arguments): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result): void {}
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw);

        $result = AgentResult::complete('done');
        $pipeline->afterLoop($result);

        $this->assertTrue($mw->called);
    }

    // ── beforeLlmCall ──

    public function testBeforeLlmCallReturnsOptionsUnchangedWhenEmpty(): void
    {
        $pipeline = new MiddlewarePipeline();
        $options = ['model' => 'gpt-4'];
        $result = $pipeline->beforeLlmCall([], $options);

        $this->assertSame($options, $result);
    }

    public function testBeforeLlmCallChainsMiddlewares(): void
    {
        $mw = $this->createSpyMiddleware('beforeLlmCall', function (array $options): array {
            $options['temperature'] = 0.5;
            return $options;
        });

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw);

        $result = $pipeline->beforeLlmCall([], ['model' => 'gpt-4']);
        $this->assertSame(0.5, $result['temperature']);
    }

    // ── afterLlmCall ──

    public function testAfterLlmCallInvokesAllMiddlewares(): void
    {
        $mw = new class implements MiddlewareInterface {
            public bool $called = false;
            public array $capturedUsage = [];
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeLlmCall(array $messages, array $options): array { return $options; }
            public function afterLlmCall(array $response, array $usage): void
            {
                $this->called = true;
                $this->capturedUsage = $usage;
            }
            public function beforeToolCall(string $name, array $arguments): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result): void {}
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw);

        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50];
        $pipeline->afterLlmCall(['content' => 'test'], $usage);

        $this->assertTrue($mw->called);
        $this->assertSame($usage, $mw->capturedUsage);
    }

    // ── beforeToolCall ──

    public function testBeforeToolCallReturnsNullWhenNoMiddlewareIntercepts(): void
    {
        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->beforeToolCall('search', ['query' => 'test']);

        $this->assertNull($result);
    }

    public function testBeforeToolCallReturnsInterceptorResult(): void
    {
        $mw = $this->createSpyMiddleware('beforeToolCall', function (): ?string {
            return 'blocked by policy';
        });

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw);

        $result = $pipeline->beforeToolCall('dangerous_tool', []);
        $this->assertSame('blocked by policy', $result);
    }

    public function testBeforeToolCallStopsAtFirstInterceptor(): void
    {
        $mw1 = $this->createSpyMiddleware('beforeToolCall', function (): ?string {
            return 'intercepted';
        });
        $mw2 = new class implements MiddlewareInterface {
            public bool $called = false;
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeLlmCall(array $messages, array $options): array { return $options; }
            public function afterLlmCall(array $response, array $usage): void {}
            public function beforeToolCall(string $name, array $arguments): ?string
            {
                $this->called = true;
                return null;
            }
            public function afterToolCall(string $name, array $arguments, string $result): void {}
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw1);
        $pipeline->add($mw2);

        $result = $pipeline->beforeToolCall('test', []);
        $this->assertSame('intercepted', $result);
        $this->assertFalse($mw2->called);
    }

    // ── afterToolCall ──

    public function testAfterToolCallInvokesAllMiddlewares(): void
    {
        $mw = new class implements MiddlewareInterface {
            public bool $called = false;
            public string $capturedName = '';
            public string $capturedResult = '';
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeLlmCall(array $messages, array $options): array { return $options; }
            public function afterLlmCall(array $response, array $usage): void {}
            public function beforeToolCall(string $name, array $arguments): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result): void
            {
                $this->called = true;
                $this->capturedName = $name;
                $this->capturedResult = $result;
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw);

        $pipeline->afterToolCall('search', ['q' => 'test'], 'result text');

        $this->assertTrue($mw->called);
        $this->assertSame('search', $mw->capturedName);
        $this->assertSame('result text', $mw->capturedResult);
    }

    // ── Execution order ──

    public function testMiddlewaresExecuteInRegistrationOrder(): void
    {
        $order = [];

        $mw1 = $this->createOrderTrackingMiddleware($order, 'mw1');
        $mw2 = $this->createOrderTrackingMiddleware($order, 'mw2');
        $mw3 = $this->createOrderTrackingMiddleware($order, 'mw3');

        $pipeline = new MiddlewarePipeline();
        $pipeline->add($mw1);
        $pipeline->add($mw2);
        $pipeline->add($mw3);

        $pipeline->beforeLoop([], []);

        $this->assertSame(['mw1', 'mw2', 'mw3'], $order);
    }

    // ── helpers ──

    private function createSpyMiddleware(string $method, callable $behavior): MiddlewareInterface
    {
        return new class($method, $behavior) implements MiddlewareInterface {
            public function __construct(
                private readonly string $targetMethod,
                private readonly \Closure $behavior,
            ) {}

            public function beforeLoop(array $messages, array $agentConfig): array
            {
                if ($this->targetMethod === 'beforeLoop') {
                    return ($this->behavior)($messages);
                }
                return $messages;
            }

            public function afterLoop(AgentResult $result): AgentResult
            {
                if ($this->targetMethod === 'afterLoop') {
                    return ($this->behavior)($result);
                }
                return $result;
            }

            public function beforeLlmCall(array $messages, array $options): array
            {
                if ($this->targetMethod === 'beforeLlmCall') {
                    return ($this->behavior)($options);
                }
                return $options;
            }

            public function afterLlmCall(array $response, array $usage): void
            {
                if ($this->targetMethod === 'afterLlmCall') {
                    ($this->behavior)($response, $usage);
                }
            }

            public function beforeToolCall(string $name, array $arguments): ?string
            {
                if ($this->targetMethod === 'beforeToolCall') {
                    return ($this->behavior)();
                }
                return null;
            }

            public function afterToolCall(string $name, array $arguments, string $result): void
            {
                if ($this->targetMethod === 'afterToolCall') {
                    ($this->behavior)($name, $arguments, $result);
                }
            }
        };
    }

    private function createOrderTrackingMiddleware(array &$order, string $label): MiddlewareInterface
    {
        return new class($order, $label) implements MiddlewareInterface {
            public function __construct(
                private array &$order,
                private readonly string $label,
            ) {}

            public function beforeLoop(array $messages, array $agentConfig): array
            {
                $this->order[] = $this->label;
                return $messages;
            }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeLlmCall(array $messages, array $options): array { return $options; }
            public function afterLlmCall(array $response, array $usage): void {}
            public function beforeToolCall(string $name, array $arguments): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result): void {}
        };
    }
}
