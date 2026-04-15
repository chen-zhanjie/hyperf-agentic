<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class AsyncGuardrailContext
{
    /** @var array<string, AsyncGuardrailHandle> */
    private array $handles = [];

    private ?GuardrailResult $syncResult = null;

    public function __construct(
        public readonly string $phase,
    ) {}

    public function addHandle(AsyncGuardrailHandle $handle, string $name): void
    {
        $this->handles[$name] = $handle;
    }

    public function setSyncResult(?GuardrailResult $result): void
    {
        $this->syncResult = $result;
    }

    public function isBlocked(): bool
    {
        if ($this->syncResult !== null && $this->syncResult->tripwire) {
            return true;
        }
        foreach ($this->handles as $handle) {
            if ($handle->isBlocked()) {
                return true;
            }
        }
        return false;
    }

    public function getBlockResult(): ?GuardrailResult
    {
        if ($this->syncResult !== null && $this->syncResult->tripwire) {
            return $this->syncResult;
        }
        foreach ($this->handles as $handle) {
            if ($handle->isBlocked()) {
                return $handle->getResult();
            }
        }
        return null;
    }

    public function getBlockName(): ?string
    {
        if ($this->syncResult !== null && $this->syncResult->tripwire) {
            return 'sync';
        }
        foreach ($this->handles as $name => $handle) {
            if ($handle->isBlocked()) {
                return $name;
            }
        }
        return null;
    }

    public function allCompleted(): bool
    {
        foreach ($this->handles as $handle) {
            if (!$handle->isCompleted()) {
                return false;
            }
        }
        return true;
    }

    public function hasAsyncGuardrails(): bool
    {
        return !empty($this->handles);
    }

    public function await(int $timeoutMs = 5000): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || empty($this->handles)) {
            return;
        }

        $channel = new \Swoole\Coroutine\Channel(count($this->handles));
        $remaining = count($this->handles);

        foreach ($this->handles as $handle) {
            \Swoole\Coroutine::create(function () use ($handle, $channel): void {
                while (!$handle->isCompleted()) {
                    \Swoole\Coroutine\System::sleep(0.01);
                }
                $channel->push(true);
            });
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);
        $collected = 0;
        while ($collected < $remaining && microtime(true) < $deadline) {
            $result = $channel->pop(timeout: max(0.01, $deadline - microtime(true)));
            if ($result !== false) {
                ++$collected;
            }
        }
    }
}
