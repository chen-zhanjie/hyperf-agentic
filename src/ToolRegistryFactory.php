<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Loader\AnnotationToolLoader;
use ChenZhanjie\Agentic\Loader\ConfigToolLoader;
use ChenZhanjie\Agentic\Tool\Builtin\AskTool;
use Psr\Container\ContainerInterface;

/**
 * ToolRegistry factory — loads all tools at Hyperf startup.
 * Order: annotation tools → config tools → built-in tools (AskTool).
 */
class ToolRegistryFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function __invoke(): ToolRegistry
    {
        $registry = new ToolRegistry();

        // 1. Annotation-discovered tools
        $this->container->get(AnnotationToolLoader::class)->load($registry);

        // 2. Config-declared tools
        $this->container->get(ConfigToolLoader::class)->load($registry);

        // 3. Built-in tools
        $registry->register($this->container->get(AskTool::class));

        return $registry;
    }
}
