<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

interface MemoryProviderInterface
{
    public function isAvailable(): bool;
    public function initialize(string $sessionId): void;
    public function prefetch(string $userMessage): ?string;
    public function syncTurn(string $userContent, string $assistantContent): void;
    public function onSessionEnd(): void;
}
