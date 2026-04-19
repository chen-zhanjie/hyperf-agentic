<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly array $usage,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $reasoningContent = null,
        public readonly array $toolCalls = [],
        public readonly int $latencyMs = 0,
    ) {}

    public function promptTokens(): int
    {
        return (int) ($this->usage['prompt_tokens'] ?? 0);
    }

    public function completionTokens(): int
    {
        return (int) ($this->usage['completion_tokens'] ?? 0);
    }

    public function totalTokens(): int
    {
        return $this->promptTokens() + $this->completionTokens();
    }

    public function toCallMeta(): LlmCallMeta
    {
        return new LlmCallMeta(
            provider: $this->provider,
            model: $this->model,
            promptTokens: $this->promptTokens(),
            completionTokens: $this->completionTokens(),
            totalTokens: $this->totalTokens(),
        );
    }

    public function toArray(): array
    {
        $result = [
            'content' => $this->content,
            'usage' => $this->usage,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
        if ($this->reasoningContent !== null) {
            $result['reasoning_content'] = $this->reasoningContent;
        }
        if ($this->toolCalls !== []) {
            $result['tool_calls'] = $this->toolCalls;
        }
        if ($this->latencyMs > 0) {
            $result['latency_ms'] = $this->latencyMs;
        }
        return $result;
    }
}
