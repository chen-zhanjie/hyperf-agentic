<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Session;

use ChenZhanjie\Agentic\Contract\SessionStoreInterface;

/**
 * In-memory session store — for testing and non-persistent use cases.
 */
class MemorySessionStore implements SessionStoreInterface
{
    /** @var array<string, array<string, array{value: mixed, expires_at: ?int}>> */
    private array $data = [];

    public function set(string $sessionId, string $key, mixed $value): void
    {
        $this->cleanup();
        $this->data[$sessionId][$key] = [
            'value' => $value,
            'expires_at' => null,
        ];
    }

    public function get(string $sessionId, string $key, mixed $default = null): mixed
    {
        $this->cleanup();
        $entry = $this->data[$sessionId][$key] ?? null;
        if ($entry === null) {
            return $default;
        }
        if ($entry['expires_at'] !== null && $entry['expires_at'] < time()) {
            unset($this->data[$sessionId][$key]);
            return $default;
        }
        return $entry['value'];
    }

    public function delete(string $sessionId, string $key): void
    {
        unset($this->data[$sessionId][$key]);
        if (empty($this->data[$sessionId])) {
            unset($this->data[$sessionId]);
        }
    }

    public function has(string $sessionId, string $key): bool
    {
        return $this->get($sessionId, $key) !== null;
    }

    public function setTtl(string $sessionId, int $seconds): void
    {
        $expiresAt = time() + $seconds;
        if (!isset($this->data[$sessionId])) {
            return;
        }
        foreach ($this->data[$sessionId] as $key => $entry) {
            $this->data[$sessionId][$key]['expires_at'] = $expiresAt;
        }
    }

    /**
     * Read and delete — for testing only.
     * Not atomic under concurrency; use RedisSessionStore for production.
     */
    public function getAndDelete(string $sessionId, string $key): mixed
    {
        $value = $this->get($sessionId, $key);
        $this->delete($sessionId, $key);
        return $value;
    }

    private function cleanup(): void
    {
        $now = time();
        foreach ($this->data as $sessionId => $keys) {
            foreach ($keys as $key => $entry) {
                if ($entry['expires_at'] !== null && $entry['expires_at'] < $now) {
                    unset($this->data[$sessionId][$key]);
                }
            }
            if (empty($this->data[$sessionId])) {
                unset($this->data[$sessionId]);
            }
        }
    }
}
