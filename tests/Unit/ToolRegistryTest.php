<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\Contract\ToolInterface;

/**
 * @covers \ChenZhanjie\Agentic\ToolRegistry
 */
class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    // ── Register ──

    public function testRegisterTool(): void
    {
        $tool = $this->createTool('search_products', 'Search products');
        $this->registry->register($tool);

        $this->assertTrue($this->registry->hasTools());
        $this->assertTrue($this->registry->has('search_products'));
        $this->assertSame($tool, $this->registry->resolve('search_products'));
    }

    public function testRegisterDuplicateThrows(): void
    {
        $tool = $this->createTool('search_products', 'Search');
        $this->registry->register($tool);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');
        $this->registry->register($tool);
    }

    // ── Resolve ──

    public function testResolveUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not registered');
        $this->registry->resolve('unknown');
    }

    // ── Only (immutable filter) ──

    public function testOnlyReturnsFilteredClone(): void
    {
        $this->registry->register($this->createTool('a', 'Tool A'));
        $this->registry->register($this->createTool('b', 'Tool B'));
        $this->registry->register($this->createTool('c', 'Tool C'));

        $filtered = $this->registry->only(['a', 'c']);

        // Filtered has only a and c
        $this->assertTrue($filtered->has('a'));
        $this->assertFalse($filtered->has('b'));
        $this->assertTrue($filtered->has('c'));

        // Original is unchanged
        $this->assertTrue($this->registry->has('b'));
        $this->assertEquals(3, $this->registry->count());
        $this->assertEquals(2, $filtered->count());
    }

    // ── Execute ──

    public function testExecuteReturnsSuccessResult(): void
    {
        $tool = $this->createConfigurableTool('test', 'Test', fn() => 'result data');
        $this->registry->register($tool);

        $result = $this->registry->execute('test', []);
        $this->assertTrue($result->success);
        $this->assertEquals('result data', $result->toText());
    }

    public function testExecuteReturnsErrorForUnknownTool(): void
    {
        $result = $this->registry->execute('missing', []);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->toText());
    }

    public function testExecuteReturnsErrorOnException(): void
    {
        $tool = $this->createConfigurableTool('fail', 'Fail', fn() => throw new \RuntimeException('DB down'));
        $this->registry->register($tool);

        $result = $this->registry->execute('fail', []);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('DB down', $result->toText());
    }

    public function testExecuteTruncatesLargeResults(): void
    {
        $longResult = str_repeat('x', 200);
        $tool = $this->createConfigurableTool('big', 'Big', fn() => $longResult);
        $this->registry->register($tool, 'default', 100);

        $result = $this->registry->execute('big', []);
        $this->assertTrue($result->success);
        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(200, mb_strlen($result->toText()));
    }

    public function testExecuteHandlesJsonStringReturn(): void
    {
        $tool = $this->createConfigurableTool('json_tool', 'JSON', fn() => '{"key":"value"}');
        $this->registry->register($tool);

        $result = $this->registry->execute('json_tool', []);
        $this->assertTrue($result->success);
        $this->assertJson($result->toText());
    }

    // ── Schemas ──

    public function testGetAvailableSchemasFiltersDisabled(): void
    {
        $enabled = $this->createTool('enabled', 'Enabled');
        $disabled = $this->createTool('disabled', 'Disabled', false);

        $this->registry->register($enabled);
        $this->registry->register($disabled);

        $schemas = $this->registry->getAvailableSchemas();
        $names = array_map(fn($s) => $s['function']['name'], $schemas);

        $this->assertContains('enabled', $names);
        $this->assertNotContains('disabled', $names);
    }

    public function testGetAvailableNames(): void
    {
        $this->registry->register($this->createTool('a', 'A', true));
        $this->registry->register($this->createTool('b', 'B', false));
        $this->registry->register($this->createTool('c', 'C', true));

        $names = $this->registry->getAvailableNames();
        $this->assertEquals(['a', 'c'], $names);
    }

    public function testGetAvailableDescriptions(): void
    {
        $this->registry->register($this->createTool('a', 'Tool A desc'));
        $this->registry->register($this->createTool('b', 'Tool B desc', false));

        $descs = $this->registry->getAvailableDescriptions();
        $this->assertEquals(['a' => 'Tool A desc'], $descs);
    }

    public function testCountAndHasTools(): void
    {
        $this->assertFalse($this->registry->hasTools());
        $this->assertEquals(0, $this->registry->count());

        $this->registry->register($this->createTool('x', 'X'));
        $this->assertTrue($this->registry->hasTools());
        $this->assertEquals(1, $this->registry->count());
    }

    // ── Helpers ──

    private function createTool(string $name, string $desc, bool $enabled = true): ToolInterface
    {
        return new class($name, $desc, $enabled) implements ToolInterface {
            public function __construct(
                private string $n, private string $d, private bool $e
            ) {}
            public function name(): string { return $this->n; }
            public function description(): string { return $this->d; }
            public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return 'ok'; }
            public function isEnabled(): bool { return $this->e; }
            public function isParallelAllowed(): bool { return true; }
        };
    }

    private function createConfigurableTool(string $name, string $desc, callable $execFn, bool $enabled = true): ToolInterface
    {
        return new class($name, $desc, $execFn, $enabled) implements ToolInterface {
            public function __construct(
                private string $n, private string $d, private $fn, private bool $e
            ) {}
            public function name(): string { return $this->n; }
            public function description(): string { return $this->d; }
            public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return ($this->fn)(); }
            public function isEnabled(): bool { return $this->e; }
            public function isParallelAllowed(): bool { return true; }
        };
    }
}
