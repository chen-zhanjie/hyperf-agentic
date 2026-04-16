<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Result of a tool guardrail check.
 *
 * - ok(): pass through
 * - blocked(): reject the tool call
 * - sanitize(): pass with modified arguments
 * - transformOutput(): pass with modified output
 */
class ToolGuardrailResult
{
    private function __construct(
        public readonly bool $blocked,
        public readonly string $reason = '',
        public readonly ?array $modifiedArguments = null,
        public readonly ?string $modifiedOutput = null,
    ) {}

    public static function ok(): self
    {
        return new self(blocked: false);
    }

    public static function blocked(string $reason): self
    {
        return new self(blocked: true, reason: $reason);
    }

    public static function sanitize(array $modifiedArguments, string $reason = ''): self
    {
        return new self(blocked: false, reason: $reason, modifiedArguments: $modifiedArguments);
    }

    public static function transformOutput(string $modifiedOutput, string $reason = ''): self
    {
        return new self(blocked: false, reason: $reason, modifiedOutput: $modifiedOutput);
    }
}
