<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tracing;

use ChenZhanjie\Agentic\Contract\SpanInterface;
use ChenZhanjie\Agentic\Contract\TraceExporterInterface;

/**
 * Tracer — span lifecycle manager.
 * Creates spans, manages active span stack, delegates export to TraceExporter.
 */
class Tracer
{
    /** @var array<string, SpanInterface> */
    private array $activeSpans = [];

    public function __construct(
        private readonly TraceExporterInterface $exporter,
    ) {}

    public function startSpan(
        string $name,
        ?SpanInterface $parent = null,
        array $attributes = [],
    ): SpanInterface {
        $span = new Span(
            name: $name,
            parentSpanId: $parent?->spanId(),
            attributes: $attributes,
        );
        $this->activeSpans[$span->spanId()] = $span;
        return $span;
    }

    public function endSpan(SpanInterface $span): void
    {
        $span->end();
        $this->exporter->export($span);
        unset($this->activeSpans[$span->spanId()]);
    }

    public function flush(): void
    {
        foreach ($this->activeSpans as $span) {
            $span->end();
            $this->exporter->export($span);
        }
        $this->activeSpans = [];
        $this->exporter->flush();
    }
}
