<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Tracing;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Tracing\Span;
use ChenZhanjie\Agentic\Tracing\SpanStatus;

class SpanTest extends TestCase
{
    public function testAutoGeneratesSpanId(): void
    {
        $span = new Span(name: 'test');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->spanId());
    }

    public function testUsesProvidedSpanId(): void
    {
        $span = new Span(name: 'test', spanId: 'abc123');
        $this->assertSame('abc123', $span->spanId());
    }

    public function testName(): void
    {
        $span = new Span(name: 'llm.call');
        $this->assertSame('llm.call', $span->name());
    }

    public function testParentSpanId(): void
    {
        $span = new Span(name: 'tool.run', parentSpanId: 'parent123');
        $this->assertSame('parent123', $span->parentSpanId());
    }

    public function testParentSpanIdDefaultsToNull(): void
    {
        $span = new Span(name: 'root');
        $this->assertNull($span->parentSpanId());
    }

    public function testStartedAtAutoSet(): void
    {
        $before = (int) (hrtime(true) / 1_000_000);
        $span = new Span(name: 'test');
        $after = (int) (hrtime(true) / 1_000_000);

        $this->assertGreaterThanOrEqual($before, $span->startedAt());
        $this->assertLessThanOrEqual($after, $span->startedAt());
    }

    public function testStartedAtUsesProvidedValue(): void
    {
        $span = new Span(name: 'test', startedAt: 1000);
        $this->assertSame(1000, $span->startedAt());
    }

    public function testEndedAtIsNullBeforeEnd(): void
    {
        $span = new Span(name: 'test');
        $this->assertNull($span->endedAt());
    }

    public function testEndSetsEndedAt(): void
    {
        $span = new Span(name: 'test');
        $span->end(2000);
        $this->assertSame(2000, $span->endedAt());
    }

    public function testEndAutoTimestamp(): void
    {
        $span = new Span(name: 'test');
        $span->end();
        $this->assertNotNull($span->endedAt());
    }

    public function testStatusIsPendingBeforeEnd(): void
    {
        $span = new Span(name: 'test');
        $this->assertSame(SpanStatus::PENDING, $span->status());
    }

    public function testStatusIsOkAfterEnd(): void
    {
        $span = new Span(name: 'test');
        $span->end();
        $this->assertSame(SpanStatus::OK, $span->status());
    }

    public function testAttributesFromConstructor(): void
    {
        $span = new Span(name: 'test', attributes: ['model' => 'gpt-4']);
        $this->assertSame(['model' => 'gpt-4'], $span->attributes());
    }

    public function testSetAttribute(): void
    {
        $span = new Span(name: 'test');
        $span->setAttribute('tokens', 150);
        $this->assertSame(['tokens' => 150], $span->attributes());
    }

    public function testSetAttributeOverwrites(): void
    {
        $span = new Span(name: 'test', attributes: ['key' => 'old']);
        $span->setAttribute('key', 'new');
        $this->assertSame(['key' => 'new'], $span->attributes());
    }

    public function testAddEvent(): void
    {
        $span = new Span(name: 'test');
        $span->addEvent('error', ['message' => 'timeout']);

        $events = $span->events();
        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['name']);
        $this->assertSame(['message' => 'timeout'], $events[0]['attributes']);
        $this->assertArrayHasKey('timestamp', $events[0]);
    }

    public function testMultipleEvents(): void
    {
        $span = new Span(name: 'test');
        $span->addEvent('retry', ['attempt' => 1]);
        $span->addEvent('retry', ['attempt' => 2]);

        $this->assertCount(2, $span->events());
    }

    public function testSetErrorStatus(): void
    {
        $span = new Span(name: 'test');
        $span->setStatus(SpanStatus::ERROR);
        $this->assertSame(SpanStatus::ERROR, $span->status());
    }
}
