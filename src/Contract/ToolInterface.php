<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

interface ToolInterface
{
    /** Tool unique name */
    public function name(): string;

    /** Tool description (seen by LLM) */
    public function description(): string;

    /** Parameter schema (OpenAI function calling format) */
    public function parameters(): array;

    /** Execute the tool */
    public function execute(array $arguments): string|array;

    /** Availability gate — return false to hide from LLM schema */
    public function isEnabled(): bool;

    /** Whether parallel execution is allowed */
    public function isParallelAllowed(): bool;
}
