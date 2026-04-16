<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tracing;

use ChenZhanjie\Agentic\Contract\SpanInterface;
use ChenZhanjie\Agentic\Contract\TraceExporterInterface;
use Psr\Log\LoggerInterface;

/**
 * MVP trace exporter — writes span data to PSR-3 logger.
 * Future: swap for Jaeger/Zipkin/OpenTelemetry exporter.
 */
class LogTraceExporter implements TraceExporterInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function export(SpanInterface $span): void
    {
        $elapsed = $span->endedAt() !== null
            ? ($span->endedAt() - $span->startedAt()) . 'ms'
            : 'N/A';

        $this->logger->info("[Trace] {$span->name()}", [
            'span_id' => $span->spanId(),
            'parent' => $span->parentSpanId(),
            'status' => $span->status()->value,
            'elapsed' => $elapsed,
            'attrs' => $span->attributes(),
        ]);
    }

    public function flush(): void
    {
        // Log-based exporter needs no flush
    }
}
