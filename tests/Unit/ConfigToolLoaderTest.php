<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\Loader\ConfigToolLoader;
use ChenZhanjie\Agentic\ToolRegistry;

class ConfigToolLoaderTest extends TestCase
{
    public function testLoadRegistersValidTools(): void
    {
        // Create a mock tool class in the test namespace
        $mockTool = new class implements ToolInterface {
            public function name(): string { return 'mock_tool'; }
            public function description(): string { return 'A mock tool'; }
            public function parameters(): array { return []; }
            public function execute(array $arguments): string { return 'ok'; }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        };
        $className = get_class($mockTool);

        $loader = new ConfigToolLoader(toolClasses: [$className]);
        $registry = new ToolRegistry();
        $loader->load($registry, fn() => $mockTool);

        $this->assertTrue($registry->has('mock_tool'));
    }

    public function testLoadSkipsInvalidClasses(): void
    {
        $loader = new ConfigToolLoader(toolClasses: ['NonExistentClass']);
        $registry = new ToolRegistry();
        $loader->load($registry);

        $this->assertFalse($registry->hasTools());
    }

    public function testLoadWithEmptyClassesArray(): void
    {
        $loader = new ConfigToolLoader(toolClasses: []);
        $registry = new ToolRegistry();
        $loader->load($registry);

        $this->assertFalse($registry->hasTools());
    }
}
