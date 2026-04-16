<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\ToolGuardrailResult;

/**
 * Tool-level guardrail — runs before and after tool execution.
 *
 * PreTool (checkToolInput): can block, sanitize arguments, or pass.
 * PostTool (checkToolOutput): can block, transform output, or pass.
 */
interface ToolGuardrailInterface
{
    public function name(): string;

    public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult;

    public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult;
}
