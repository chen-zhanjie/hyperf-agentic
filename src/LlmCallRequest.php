<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class LlmCallRequest
{
    public function __construct(
        public readonly array $messages,
        public readonly array $options,
        public readonly string $provider,
        public readonly string $model,
    ) {}

    public function with(array $overrides): self
    {
        return new self(
            messages: $overrides['messages'] ?? $this->messages,
            options: $overrides['options'] ?? $this->options,
            provider: $overrides['provider'] ?? $this->provider,
            model: $overrides['model'] ?? $this->model,
        );
    }
}
