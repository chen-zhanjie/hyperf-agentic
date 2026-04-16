<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\ToolGuardrailResult;

class ToolGuardrailResultTest extends TestCase
{
    // ── ok ──

    public function testOkReturnsPassResult(): void
    {
        $result = ToolGuardrailResult::ok();

        $this->assertFalse($result->blocked);
        $this->assertSame('', $result->reason);
        $this->assertNull($result->modifiedArguments);
        $this->assertNull($result->modifiedOutput);
    }

    // ── blocked ──

    public function testBlockedReturnsBlockedResult(): void
    {
        $result = ToolGuardrailResult::blocked('Dangerous operation');

        $this->assertTrue($result->blocked);
        $this->assertSame('Dangerous operation', $result->reason);
        $this->assertNull($result->modifiedArguments);
        $this->assertNull($result->modifiedOutput);
    }

    // ── sanitize ──

    public function testSanitizeReturnsModifiedArguments(): void
    {
        $modifiedArgs = ['query' => 'sanitized_query'];
        $result = ToolGuardrailResult::sanitize($modifiedArgs, 'PII removed');

        $this->assertFalse($result->blocked);
        $this->assertSame('PII removed', $result->reason);
        $this->assertSame($modifiedArgs, $result->modifiedArguments);
        $this->assertNull($result->modifiedOutput);
    }

    // ── transformOutput ──

    public function testTransformOutputReturnsModifiedOutput(): void
    {
        $result = ToolGuardrailResult::transformOutput('redacted output', 'Sensitive data masked');

        $this->assertFalse($result->blocked);
        $this->assertSame('Sensitive data masked', $result->reason);
        $this->assertNull($result->modifiedArguments);
        $this->assertSame('redacted output', $result->modifiedOutput);
    }

    // ── properties are readonly ──

    public function testPropertiesAreReadonly(): void
    {
        $result = ToolGuardrailResult::ok();
        $reflection = new \ReflectionClass($result);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property \${$property->getName()} should be readonly",
            );
        }
    }
}
