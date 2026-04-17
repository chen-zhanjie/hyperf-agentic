<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class LlmCallMeta
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
    ) {}
}
