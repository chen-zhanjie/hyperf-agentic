<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\ContextEngineInterface;

class NullContextEngine implements ContextEngineInterface
{
    public function name(): string { return 'null'; }
    public function updateFromResponse(int $promptTokens, int $completionTokens): void {}
    public function shouldCompress(array $messages): bool { return false; }
    public function compress(array $messages): array { return $messages; }
    public function onSessionStart(): void {}
    public function onSessionEnd(): void {}
}
