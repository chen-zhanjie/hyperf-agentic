<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\GuardrailEntry;
use ChenZhanjie\Agentic\GuardrailMode;
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\GuardrailResult;

class GuardrailEntryTest extends TestCase
{
    public function testDefaultsToSyncMode(): void
    {
        $guardrail = $this->createMock(GuardrailInterface::class);
        $guardrail->method('name')->willReturn('test');

        $entry = new GuardrailEntry($guardrail);

        $this->assertSame(GuardrailMode::SYNC, $entry->mode);
        $this->assertSame($guardrail, $entry->guardrail);
    }

    public function testExplicitAsyncMode(): void
    {
        $guardrail = $this->createMock(GuardrailInterface::class);
        $guardrail->method('name')->willReturn('async_test');

        $entry = new GuardrailEntry($guardrail, GuardrailMode::ASYNC);

        $this->assertSame(GuardrailMode::ASYNC, $entry->mode);
    }

    public function testPropertiesAreReadonly(): void
    {
        $guardrail = $this->createMock(GuardrailInterface::class);
        $entry = new GuardrailEntry($guardrail, GuardrailMode::ASYNC);

        $reflection = new \ReflectionClass($entry);

        $this->assertTrue($reflection->getProperty('guardrail')->isReadOnly());
        $this->assertTrue($reflection->getProperty('mode')->isReadOnly());
    }
}
