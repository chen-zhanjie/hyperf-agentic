<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Tracing;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\SpanInterface;
use ChenZhanjie\Agentic\Contract\TraceExporterInterface;
use ChenZhanjie\Agentic\Tracing\Tracer;

class TracerTest extends TestCase
{
    private TraceExporterInterface $exporter;
    private Tracer $tracer;

    protected function setUp(): void
    {
        $this->exporter = $this->createMock(TraceExporterInterface::class);
        $this->tracer = new Tracer($this->exporter);
    }

    public function testStartSpanReturnsSpanInterface(): void
    {
        $span = $this->tracer->startSpan('agent.run');
        $this->assertInstanceOf(SpanInterface::class, $span);
        $this->assertSame('agent.run', $span->name());
    }

    public function testStartSpanWithParent(): void
    {
        $parent = $this->tracer->startSpan('agent.run');
        $child = $this->tracer->startSpan('llm.call', parent: $parent);

        $this->assertSame($parent->spanId(), $child->parentSpanId());
    }

    public function testStartSpanWithAttributes(): void
    {
        $span = $this->tracer->startSpan('llm.call', attributes: ['model' => 'gpt-4']);
        $this->assertSame(['model' => 'gpt-4'], $span->attributes());
    }

    public function testEndSpanExportsAndRemoves(): void
    {
        $span = $this->tracer->startSpan('test');

        $this->exporter->expects($this->once())
            ->method('export')
            ->with($this->callback(fn(SpanInterface $s) => $s->endedAt() !== null));

        $this->tracer->endSpan($span);
    }

    public function testFlushEndsAllActiveSpans(): void
    {
        $span1 = $this->tracer->startSpan('a');
        $span2 = $this->tracer->startSpan('b');

        $this->exporter->expects($this->exactly(2))
            ->method('export');

        $this->exporter->expects($this->once())
            ->method('flush');

        $this->tracer->flush();

        $this->assertNotNull($span1->endedAt());
        $this->assertNotNull($span2->endedAt());
    }

    public function testFlushWithNoActiveSpansCallsFlush(): void
    {
        $this->exporter->expects($this->never())
            ->method('export');

        $this->exporter->expects($this->once())
            ->method('flush');

        $this->tracer->flush();
    }
}
