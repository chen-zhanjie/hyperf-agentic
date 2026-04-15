<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Event;

trait EventEmitter
{
    /** @var array<string, array<callable>> */
    private array $listeners = [];

    public function on(string $event, callable $callback): void
    {
        $this->listeners[$event][] = $callback;
    }

    public function once(string $event, callable $callback): void
    {
        $wrapper = null;
        $wrapper = function (...$args) use ($event, $callback, &$wrapper) {
            $this->off($event, $wrapper);
            $callback(...$args);
        };
        $this->listeners[$event][] = $wrapper;
    }

    public function off(string $event, ?callable $callback = null): void
    {
        if ($callback === null) {
            unset($this->listeners[$event]);
        } else {
            $this->listeners[$event] = array_filter(
                $this->listeners[$event],
                fn($cb) => $cb !== $callback,
            );
        }
    }

    protected function emit(AgentEventType $type, array $payload = []): void
    {
        $eventName = $type->value;
        foreach ($this->listeners[$eventName] ?? [] as $callback) {
            $callback($type, $payload);
        }
    }
}
