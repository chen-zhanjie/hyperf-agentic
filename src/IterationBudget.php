<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class IterationBudget
{
    private int $used = 0;
    private bool $graceUsed = false;

    public function __construct(
        public readonly int $maxTotal,
    ) {}

    public function consume(): bool
    {
        if ($this->used >= $this->maxTotal) {
            return false;
        }
        ++$this->used;
        return true;
    }

    public function refund(): void
    {
        if ($this->used > 0) {
            --$this->used;
        }
    }

    public function remaining(): int
    {
        return max(0, $this->maxTotal - $this->used);
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->maxTotal;
    }

    public function isGraceTurn(): bool
    {
        return $this->graceUsed;
    }

    public function consumeGrace(): bool
    {
        if ($this->graceUsed) {
            return false;
        }
        $this->graceUsed = true;
        return true;
    }

    public function used(): int
    {
        return $this->used;
    }
}
