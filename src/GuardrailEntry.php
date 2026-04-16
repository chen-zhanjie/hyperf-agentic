<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\GuardrailInterface;

class GuardrailEntry
{
    public function __construct(
        public readonly GuardrailInterface $guardrail,
        public readonly GuardrailMode $mode = GuardrailMode::SYNC,
        public readonly int $priority = 0,
    ) {}
}
