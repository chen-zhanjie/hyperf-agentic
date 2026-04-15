<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Session;

use ChenZhanjie\Agentic\Contract\SessionStoreInterface;

/**
 * Redis-backed session store — production-grade with atomic getAndDelete.
 * Uses SCAN (not KEYS) for TTL updates to avoid blocking Redis.
 */
class RedisSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'agentic:session:',
        private readonly int $defaultTtl = 3600,
    ) {}

    public function set(string $sessionId, string $key, mixed $value): void
    {
        $redisKey = $this->buildKey($sessionId, $key);
        $this->redis->setex($redisKey, $this->defaultTtl, serialize($value));
    }

    public function get(string $sessionId, string $key, mixed $default = null): mixed
    {
        $redisKey = $this->buildKey($sessionId, $key);
        $raw = $this->redis->get($redisKey);

        if ($raw === false) {
            return $default;
        }

        return $this->safeUnserialize($raw, $default);
    }

    public function delete(string $sessionId, string $key): void
    {
        $this->redis->del($this->buildKey($sessionId, $key));
    }

    public function has(string $sessionId, string $key): bool
    {
        return (bool) $this->redis->exists($this->buildKey($sessionId, $key));
    }

    public function setTtl(string $sessionId, int $seconds): void
    {
        // Use SCAN instead of KEYS to avoid blocking Redis
        $pattern = $this->prefix . $sessionId . ':*';
        $iterator = null;

        while (($keys = $this->redis->scan($iterator, $pattern, 100)) !== false) {
            foreach ($keys as $redisKey) {
                $this->redis->expire($redisKey, $seconds);
            }
        }
    }

    public function getAndDelete(string $sessionId, string $key): mixed
    {
        $redisKey = $this->buildKey($sessionId, $key);

        // Use GETDEL for atomic read-and-delete (Redis 6.2+)
        $raw = $this->redis->rawCommand('GETDEL', $redisKey);

        if ($raw === false) {
            return null;
        }

        return $this->safeUnserialize($raw, null);
    }

    private function buildKey(string $sessionId, string $key): string
    {
        return $this->prefix . $sessionId . ':' . $key;
    }

    private function safeUnserialize(string $raw, mixed $fallback): mixed
    {
        try {
            $value = @unserialize($raw);
            return $value === false && $raw !== serialize(false) ? $fallback : $value;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
