<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tracing;

use ChenZhanjie\Agentic\Contract\SpanInterface;

/**
 * Span — a single observable operation within an agent run.
 */
class Span implements SpanInterface
{
    private string $spanId;
    private int $startedAt;
    private ?int $endedAt = null;
    private SpanStatus $status = SpanStatus::PENDING;

    /** @var array<int, array{name: string, attributes: array, timestamp: int}> */
    private array $events = [];

    /**
     * @param string $name Span name (e.g. "llm.call", "tool.execute.query")
     * @param string|null $parentSpanId Parent span ID for call chain
     * @param string $spanId Auto-generated if empty
     * @param int $startedAt Auto-set if 0
     * @param array $attributes Key-value pairs
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $parentSpanId = null,
        string $spanId = '',
        int $startedAt = 0,
        private array $attributes = [],
    ) {
        $this->spanId = $spanId ?: bin2hex(random_bytes(8));
        $this->startedAt = $startedAt ?: (int) (hrtime(true) / 1_000_000);
    }

    public function spanId(): string
    {
        return $this->spanId;
    }

    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function startedAt(): int
    {
        return $this->startedAt;
    }

    public function endedAt(): ?int
    {
        return $this->endedAt;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function status(): string
    {
        return $this->status->value;
    }

    public function end(?int $timestamp = null): void
    {
        $this->endedAt = $timestamp ?? (int) (hrtime(true) / 1_000_000);
        if ($this->status === SpanStatus::PENDING) {
            $this->status = SpanStatus::OK;
        }
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function addEvent(string $name, array $attributes = []): void
    {
        $this->events[] = [
            'name' => $name,
            'attributes' => $attributes,
            'timestamp' => time(),
        ];
    }

    /**
     * @return array<int, array{name: string, attributes: array, timestamp: int}>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function setStatus(SpanStatus $status): void
    {
        $this->status = $status;
    }
}
