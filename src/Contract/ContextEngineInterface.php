<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

interface ContextEngineInterface
{
    public function name(): string;
    public function updateFromResponse(int $promptTokens, int $completionTokens): void;
    public function shouldCompress(array $messages): bool;
    public function compress(array $messages): array;
    public function onSessionStart(): void;
    public function onSessionEnd(): void;
}
