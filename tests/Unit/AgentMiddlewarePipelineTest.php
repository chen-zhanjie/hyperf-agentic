<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\AgentMiddlewarePipeline;
use ChenZhanjie\Agentic\Contract\AgentMiddlewareInterface;

class AgentMiddlewarePipelineTest extends TestCase
{
    // ── beforeLoop ──

    public function testBeforeLoopWithNoMiddlewaresReturnsMessagesUnchanged(): void
    {
        $pipeline = new AgentMiddlewarePipeline();
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
        $pipeline = new AgentMiddlewarePipeline();
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

        $pipeline = new AgentMiddlewarePipeline();
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
        $pipeline = new AgentMiddlewarePipeline();
        $result = AgentResult::complete('done');
        $returned = $pipeline->afterLoop($result);

        $this->assertSame($result, $returned);
    }

    public function testAfterLoopChainsMiddlewares(): void
    {
        $mw = new class implements AgentMiddlewareInterface {
            public bool $called = false;
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult
            {
                $this->called = true;
                return $result;
            }
            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void {}
        };

        $pipeline = new AgentMiddlewarePipeline();
        $pipeline->add($mw);

        $result = AgentResult::complete('done');
        $pipeline->afterLoop($result);

        $this->assertTrue($mw->called);
    }

    // ── beforeToolCall ──

    public function testBeforeToolCallReturnsNullWhenNoMiddlewareIntercepts(): void
    {
        $pipeline = new AgentMiddlewarePipeline();
        $result = $pipeline->beforeToolCall('search', ['query' => 'test']);

        $this->assertNull($result);
    }

    public function testBeforeToolCallReturnsInterceptorResult(): void
    {
        $mw = $this->createSpyMiddleware('beforeToolCall', function (): ?string {
            return 'blocked by policy';
        });

        $pipeline = new AgentMiddlewarePipeline();
        $pipeline->add($mw);

        $result = $pipeline->beforeToolCall('dangerous_tool', []);
        $this->assertSame('blocked by policy', $result);
    }

    public function testBeforeToolCallStopsAtFirstInterceptor(): void
    {
        $mw1 = $this->createSpyMiddleware('beforeToolCall', function (): ?string {
            return 'intercepted';
        });
        $mw2 = new class implements AgentMiddlewareInterface {
            public bool $called = false;
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string
            {
                $this->called = true;
                return null;
            }
            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void {}
        };

        $pipeline = new AgentMiddlewarePipeline();
        $pipeline->add($mw1);
        $pipeline->add($mw2);

        $result = $pipeline->beforeToolCall('test', []);
        $this->assertSame('intercepted', $result);
        $this->assertFalse($mw2->called);
    }

    // ── afterToolCall ──

    public function testAfterToolCallInvokesAllMiddlewares(): void
    {
        $mw = new class implements AgentMiddlewareInterface {
            public bool $called = false;
            public string $capturedName = '';
            public string $capturedResult = '';
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void
            {
                $this->called = true;
                $this->capturedName = $name;
                $this->capturedResult = $result;
            }
        };

        $pipeline = new AgentMiddlewarePipeline();
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

        $pipeline = new AgentMiddlewarePipeline();
        $pipeline->add($mw1);
        $pipeline->add($mw2);
        $pipeline->add($mw3);

        $pipeline->beforeLoop([], []);

        $this->assertSame(['mw1', 'mw2', 'mw3'], $order);
    }

    // ── helpers ──

    private function createSpyMiddleware(string $method, callable $behavior): AgentMiddlewareInterface
    {
        return new class($method, $behavior) implements AgentMiddlewareInterface {
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

            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string
            {
                if ($this->targetMethod === 'beforeToolCall') {
                    return ($this->behavior)();
                }
                return null;
            }

            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void
            {
                if ($this->targetMethod === 'afterToolCall') {
                    ($this->behavior)($name, $arguments, $result);
                }
            }
        };
    }

    private function createOrderTrackingMiddleware(array &$order, string $label): AgentMiddlewareInterface
    {
        return new class($order, $label) implements AgentMiddlewareInterface {
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
            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void {}
        };
    }

    // ── Fault tolerance ──

    public function testBeforeLoopPropagatesExceptions(): void
    {
        $mw = new class implements AgentMiddlewareInterface {
            public function beforeLoop(array $messages, array $agentConfig): array
            {
                throw new \RuntimeException('beforeLoop validation failed');
            }
            public function afterLoop(AgentResult $result): AgentResult { return $result; }
            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void {}
        };

        $pipeline = new AgentMiddlewarePipeline();
        $pipeline->add($mw);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('beforeLoop validation failed');

        $pipeline->beforeLoop([['role' => 'user', 'content' => 'hi']], []);
    }

    public function testNotificationMethodsCatchExceptionsAndContinue(): void
    {
        $order = [];

        $failingMw = new class($order) implements AgentMiddlewareInterface {
            public function __construct(private array &$order) {}
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult
            {
                $this->order[] = 'failing_afterLoop';
                throw new \RuntimeException('afterLoop explosion');
            }
            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void
            {
                $this->order[] = 'failing_afterToolCall';
                throw new \RuntimeException('afterToolCall explosion');
            }
        };

        $goodMw = new class($order) implements AgentMiddlewareInterface {
            public function __construct(private array &$order) {}
            public function beforeLoop(array $messages, array $agentConfig): array { return $messages; }
            public function afterLoop(AgentResult $result): AgentResult
            {
                $this->order[] = 'good_afterLoop';
                return $result;
            }
            public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string { return null; }
            public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void
            {
                $this->order[] = 'good_afterToolCall';
            }
        };

        $pipeline = new AgentMiddlewarePipeline();
        $pipeline->add($failingMw);
        $pipeline->add($goodMw);

        // afterLoop — failing middleware throws, but good middleware still runs
        $result = $pipeline->afterLoop(AgentResult::complete('ok'));
        $this->assertTrue($result->isComplete());

        // afterToolCall — both run despite exception
        $pipeline->afterToolCall('search', [], 'result');

        $this->assertContains('failing_afterLoop', $order);
        $this->assertContains('good_afterLoop', $order);
        $this->assertContains('failing_afterToolCall', $order);
        $this->assertContains('good_afterToolCall', $order);
    }
}
