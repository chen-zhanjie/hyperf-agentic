<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class CostBudget
{
    private int $usedPromptTokens = 0;
    private int $usedCompletionTokens = 0;

    public function __construct(
        public readonly int $maxTotalTokens = 200000,
        public readonly float $warnAtRatio = 0.8,
    ) {}

    public function consume(int $promptTokens, int $completionTokens): void
    {
        $this->usedPromptTokens += $promptTokens;
        $this->usedCompletionTokens += $completionTokens;
    }

    public function isExceeded(): bool
    {
        return $this->getTotalUsed() >= $this->maxTotalTokens;
    }

    public function isNearLimit(): bool
    {
        return $this->getTotalUsed() >= ($this->maxTotalTokens * $this->warnAtRatio);
    }

    public function getTotalUsed(): int
    {
        return $this->usedPromptTokens + $this->usedCompletionTokens;
    }

    public function remaining(): int
    {
        return max(0, $this->maxTotalTokens - $this->getTotalUsed());
    }

    public function getUsage(): array
    {
        return [
            'prompt_tokens' => $this->usedPromptTokens,
            'completion_tokens' => $this->usedCompletionTokens,
            'total_used' => $this->getTotalUsed(),
            'max_total' => $this->maxTotalTokens,
            'remaining' => $this->remaining(),
        ];
    }
}
