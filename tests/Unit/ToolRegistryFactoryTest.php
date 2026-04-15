<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\Tool\Builtin\AskTool;
use ChenZhanjie\Agentic\Tool\Builtin\SkillTool;
use ChenZhanjie\Agentic\ToolRegistryFactory;
use ChenZhanjie\Agentic\Loader\AnnotationToolLoader;
use ChenZhanjie\Agentic\Loader\ConfigToolLoader;
use Psr\Container\ContainerInterface;

class ToolRegistryFactoryTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
    }

    public function testFactoryReturnsPopulatedRegistry(): void
    {
        $annotationLoader = $this->createMock(AnnotationToolLoader::class);
        $annotationLoader->method('load')->willReturnCallback(
            fn($registry) => null // no annotation tools
        );

        $configLoader = $this->createMock(ConfigToolLoader::class);
        $configLoader->method('load')->willReturnCallback(
            fn($registry) => null // no config tools
        );

        $askTool = $this->createMock(AskTool::class);
        $askTool->method('name')->willReturn('ask');
        $askTool->method('isEnabled')->willReturn(true);

        $this->container->method('get')
            ->willReturnMap([
                [AnnotationToolLoader::class, $annotationLoader],
                [ConfigToolLoader::class, $configLoader],
                [AskTool::class, $askTool],
            ]);

        $factory = new ToolRegistryFactory($this->container);
        $registry = ($factory)();

        $this->assertTrue($registry->has('ask'));
    }

    public function testFactoryLoadsAnnotationTools(): void
    {
        $annotationTool = $this->createMock(ToolInterface::class);
        $annotationTool->method('name')->willReturn('search');
        $annotationTool->method('isEnabled')->willReturn(true);

        $annotationLoader = $this->createMock(AnnotationToolLoader::class);
        $annotationLoader->expects($this->once())->method('load');

        $configLoader = $this->createMock(ConfigToolLoader::class);
        $askTool = $this->createMock(AskTool::class);
        $askTool->method('name')->willReturn('ask');
        $askTool->method('isEnabled')->willReturn(true);

        $this->container->method('get')
            ->willReturnMap([
                [AnnotationToolLoader::class, $annotationLoader],
                [ConfigToolLoader::class, $configLoader],
                [AskTool::class, $askTool],
            ]);

        $factory = new ToolRegistryFactory($this->container);
        ($factory)();
    }
}
