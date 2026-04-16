<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Mutable accumulator for agent loop state.
 *
 * Replaces 4 by-reference parameters that were threaded through runLoop/executeTurn/runGraceTurn.
 * Per-request scoped — constructed once in run()/resume(), never shared across requests.
 */
class LoopState
{
    public int $iterations = 0;
    public int $totalPromptTokens = 0;
    public int $totalCompletionTokens = 0;
    public int $totalToolCalls = 0;

    public function __construct(
        public readonly int $startTime,
        public readonly IterationBudget $budget,
        public readonly CostBudget $costBudget,
        public readonly int $maxIterations,
        public readonly int $asyncGuardrailTimeout = 5000,
    ) {}

    public function recordUsage(int $promptTokens, int $completionTokens): void
    {
        $this->totalPromptTokens += $promptTokens;
        $this->totalCompletionTokens += $completionTokens;
        $this->costBudget->consume($promptTokens, $completionTokens);
    }

    public function recordToolCalls(int $count): void
    {
        $this->totalToolCalls += $count;
    }

    public function elapsedMs(): int
    {
        return (int) ((hrtime(true) - $this->startTime) / 1_000_000);
    }
}
