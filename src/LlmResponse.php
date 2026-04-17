<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly array $usage,
        public readonly ?string $model = null,
        public readonly ?string $provider = null,
        public readonly ?string $reasoningContent = null,
        public readonly array $toolCalls = [],
    ) {}

    public function toArray(): array
    {
        $result = [
            'content' => $this->content,
            'usage' => $this->usage,
        ];
        if ($this->model !== null) {
            $result['model'] = $this->model;
        }
        if ($this->provider !== null) {
            $result['provider'] = $this->provider;
        }
        if ($this->reasoningContent !== null) {
            $result['reasoning_content'] = $this->reasoningContent;
        }
        if ($this->toolCalls !== []) {
            $result['tool_calls'] = $this->toolCalls;
        }
        return $result;
    }
}
