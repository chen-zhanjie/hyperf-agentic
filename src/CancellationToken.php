<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class CancellationToken
{
    private bool $cancelled = false;
    private ?string $reason = null;

    public function cancel(string $reason = ''): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        $this->reason = $reason;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public static function withTimeout(int $timeoutMs): self
    {
        $token = new self();
        if (class_exists(\Swoole\Timer::class)) {
            \Swoole\Timer::after($timeoutMs, function () use ($token): void {
                $token->cancel('timeout');
            });
        }
        return $token;
    }
}
