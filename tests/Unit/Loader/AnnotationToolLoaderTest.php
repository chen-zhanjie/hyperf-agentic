<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Attribute\AsTool;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\Loader\AnnotationToolLoader;
use ChenZhanjie\Agentic\ToolRegistry;

class AnnotationToolLoaderTest extends TestCase
{
    public function testLoadWithNoAnnotations(): void
    {
        $registry = new ToolRegistry();
        $loader = new AnnotationToolLoader([]); // no annotated classes

        $loader->load($registry);
        $this->assertFalse($registry->hasTools());
    }

    public function testLoadRegistersAnnotatedTool(): void
    {
        $registry = new ToolRegistry();
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn('annotated_tool');
        $tool->method('isEnabled')->willReturn(true);

        $loader = new AnnotationToolLoader([get_class($tool)]);

        // With a factory that returns our mock
        $loader->load($registry, fn(string $class) => $tool);

        $this->assertTrue($registry->has('annotated_tool'));
    }

    public function testLoadSkipsNonToolClasses(): void
    {
        $registry = new ToolRegistry();
        $loader = new AnnotationToolLoader([\stdClass::class]);

        $loader->load($registry, fn(string $class) => new $class());
        $this->assertFalse($registry->hasTools());
    }

    public function testLoadSkipsNonExistentClasses(): void
    {
        $registry = new ToolRegistry();
        $loader = new AnnotationToolLoader(['NonExistentClass123']);

        $loader->load($registry);
        $this->assertFalse($registry->hasTools());
    }

    public function testLoadWithNullFactoryCreatesInstance(): void
    {
        // Use a real anonymous class that implements ToolInterface
        $registry = new ToolRegistry();

        $toolClass = new class implements ToolInterface {
            public function name(): string { return 'anon_tool'; }
            public function description(): string { return 'test'; }
            public function parameters(): array { return []; }
            public function execute(array $arguments): array|string { return 'ok'; }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        };

        $loader = new AnnotationToolLoader([get_class($toolClass)]);
        $loader->load($registry); // null factory

        $this->assertTrue($registry->has('anon_tool'));
    }
}
