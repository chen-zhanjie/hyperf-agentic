<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Tracing;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Tracing\LogTraceExporter;
use ChenZhanjie\Agentic\Tracing\Span;
use ChenZhanjie\Agentic\Tracing\SpanStatus;
use Psr\Log\LoggerInterface;

class LogTraceExporterTest extends TestCase
{
    private LoggerInterface $logger;
    private LogTraceExporter $exporter;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->exporter = new LogTraceExporter($this->logger);
    }

    public function testExportLogsSpanInfo(): void
    {
        $span = new Span(name: 'llm.call', spanId: 'abc123', startedAt: 1000);
        $span->end(1050);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[Trace] llm.call', $this->callback(function (array $context) {
                return $context['span_id'] === 'abc123'
                    && $context['status'] === 'ok'
                    && $context['elapsed'] === '50ms';
            }));

        $this->exporter->export($span);
    }

    public function testExportWithPendingSpanShowsNA(): void
    {
        $span = new Span(name: 'pending.task', spanId: 'xyz789');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[Trace] pending.task', $this->callback(function (array $context) {
                return $context['elapsed'] === 'N/A'
                    && $context['status'] === 'pending';
            }));

        $this->exporter->export($span);
    }

    public function testExportIncludesAttributes(): void
    {
        $span = new Span(name: 'test', attributes: ['model' => 'gpt-4']);
        $span->end();

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->anything(), $this->callback(function (array $context) {
                return $context['attrs'] === ['model' => 'gpt-4'];
            }));

        $this->exporter->export($span);
    }

    public function testExportIncludesParentSpanId(): void
    {
        $span = new Span(name: 'child', parentSpanId: 'parent123');
        $span->end();

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->anything(), $this->callback(function (array $context) {
                return $context['parent'] === 'parent123';
            }));

        $this->exporter->export($span);
    }

    public function testFlushDoesNotThrow(): void
    {
        $this->exporter->flush();
        $this->assertTrue(true); // no exception
    }
}
