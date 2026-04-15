<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

interface SessionStoreInterface
{
    public function set(string $sessionId, string $key, mixed $value): void;
    public function get(string $sessionId, string $key, mixed $default = null): mixed;
    public function delete(string $sessionId, string $key): void;
    public function has(string $sessionId, string $key): bool;
    public function setTtl(string $sessionId, int $seconds): void;

    /** Atomic read and delete — for resume safety */
    public function getAndDelete(string $sessionId, string $key): mixed;
}
