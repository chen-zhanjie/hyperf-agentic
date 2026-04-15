<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\MemoryProviderInterface;

class NullMemoryProvider implements MemoryProviderInterface
{
    public function isAvailable(): bool { return false; }
    public function initialize(string $sessionId): void {}
    public function prefetch(string $userMessage): ?string { return null; }
    public function syncTurn(string $userContent, string $assistantContent): void {}
    public function onSessionEnd(): void {}
}
