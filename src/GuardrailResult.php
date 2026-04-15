<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class GuardrailResult
{
    public function __construct(
        public readonly bool $tripwire,
        public readonly string $reason = '',
    ) {}

    public static function ok(): self
    {
        return new self(tripwire: false);
    }

    public static function blocked(string $reason): self
    {
        return new self(tripwire: true, reason: $reason);
    }
}
