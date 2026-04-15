<?php
declare(strict_types=1);

/**
 * Session store configuration.
 * Supported drivers: Redis, Memory (testing only).
 */
return [
    'driver' => \ChenZhanjie\Agentic\Session\RedisSessionStore::class,
    'ttl' => 3600,               // Default TTL: 1 hour
    'prefix' => 'agentic:session:',
];
