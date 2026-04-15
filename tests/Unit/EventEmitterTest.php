<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Event\AgentEventType;
use ChenZhanjie\Agentic\Event\EventEmitter;

class EventEmitterTest extends TestCase
{
    private function createEmitter(): object
    {
        return new class {
            use EventEmitter;
            public function fire(AgentEventType $type, array $payload = []): void
            {
                $this->emit($type, $payload);
            }
        };
    }

    public function testOnFiresCallback(): void
    {
        $emitter = $this->createEmitter();
        $received = null;
        $emitter->on('started', function (AgentEventType $type, array $payload) use (&$received) {
            $received = $payload;
        });
        $emitter->fire(AgentEventType::STARTED, ['agent' => 'test']);
        $this->assertSame(['agent' => 'test'], $received);
    }

    public function testOnceFiresOnlyOnce(): void
    {
        $emitter = $this->createEmitter();
        $count = 0;
        $emitter->once('started', function () use (&$count) {
            ++$count;
        });
        $emitter->fire(AgentEventType::STARTED);
        $emitter->fire(AgentEventType::STARTED);
        $this->assertSame(1, $count);
    }

    public function testOffRemovesSpecificCallback(): void
    {
        $emitter = $this->createEmitter();
        $count = 0;
        $callback = function () use (&$count) { ++$count; };
        $emitter->on('started', $callback);
        $emitter->off('started', $callback);
        $emitter->fire(AgentEventType::STARTED);
        $this->assertSame(0, $count);
    }

    public function testOffRemovesAllCallbacksForEvent(): void
    {
        $emitter = $this->createEmitter();
        $count = 0;
        $emitter->on('started', function () use (&$count) { ++$count; });
        $emitter->on('started', function () use (&$count) { ++$count; });
        $emitter->off('started');
        $emitter->fire(AgentEventType::STARTED);
        $this->assertSame(0, $count);
    }

    public function testEmitWithNoListenersDoesNotError(): void
    {
        $emitter = $this->createEmitter();
        $emitter->fire(AgentEventType::COMPLETE); // no exception
        $this->assertTrue(true);
    }

    public function testMultipleListenersOnSameEvent(): void
    {
        $emitter = $this->createEmitter();
        $results = [];
        $emitter->on('tool_call', function (AgentEventType $type, array $p) use (&$results) {
            $results[] = 'a';
        });
        $emitter->on('tool_call', function (AgentEventType $type, array $p) use (&$results) {
            $results[] = 'b';
        });
        $emitter->fire(AgentEventType::TOOL_CALL);
        $this->assertSame(['a', 'b'], $results);
    }
}
