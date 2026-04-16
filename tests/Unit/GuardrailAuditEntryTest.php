<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\GuardrailAuditEntry;

class GuardrailAuditEntryTest extends TestCase
{
    public function testConstructsWithDefaults(): void
    {
        $entry = new GuardrailAuditEntry(
            guardrailName: 'safety',
            phase: 'input',
            decision: 'pass',
        );

        $this->assertSame('safety', $entry->guardrailName);
        $this->assertSame('input', $entry->phase);
        $this->assertSame('pass', $entry->decision);
        $this->assertSame('', $entry->reason);
        $this->assertNull($entry->durationMs);
        $this->assertGreaterThan(0.0, $entry->timestamp);
    }

    public function testConstructsWithAllParameters(): void
    {
        $entry = new GuardrailAuditEntry(
            guardrailName: 'pii',
            phase: 'output',
            decision: 'blocked',
            reason: 'SSN detected',
            durationMs: 12.5,
            timestamp: 1700000000.0,
        );

        $this->assertSame('pii', $entry->guardrailName);
        $this->assertSame('output', $entry->phase);
        $this->assertSame('blocked', $entry->decision);
        $this->assertSame('SSN detected', $entry->reason);
        $this->assertSame(12.5, $entry->durationMs);
        $this->assertSame(1700000000.0, $entry->timestamp);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $entry = new GuardrailAuditEntry(
            guardrailName: 'toxicity',
            phase: 'tool_input',
            decision: 'sanitized',
            reason: 'PII removed',
            durationMs: 5.0,
        );

        $array = $entry->toArray();

        $this->assertSame('toxicity', $array['guardrail_name']);
        $this->assertSame('tool_input', $array['phase']);
        $this->assertSame('sanitized', $array['decision']);
        $this->assertSame('PII removed', $array['reason']);
        $this->assertSame(5.0, $array['duration_ms']);
        $this->assertArrayHasKey('timestamp', $array);
    }

    public function testPropertiesAreReadonly(): void
    {
        $entry = new GuardrailAuditEntry(
            guardrailName: 'test',
            phase: 'input',
            decision: 'pass',
        );
        $reflection = new \ReflectionClass($entry);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property \${$property->getName()} should be readonly",
            );
        }
    }
}
