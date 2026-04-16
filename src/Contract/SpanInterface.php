<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\Tracing\SpanStatus;

interface SpanInterface
{
    public function spanId(): string;
    public function parentSpanId(): ?string;
    public function name(): string;
    public function startedAt(): int;
    public function endedAt(): ?int;
    public function attributes(): array;
    public function status(): SpanStatus;
    public function events(): array;
    public function end(?int $timestamp = null): void;
    public function setAttribute(string $key, mixed $value): void;
    public function addEvent(string $name, array $attributes = []): void;
}
