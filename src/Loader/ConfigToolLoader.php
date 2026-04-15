<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Loader;

use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\ToolRegistry;

/**
 * Loads tools from config array (agentic/tools.php 'classes' key).
 * Instantiates each class and registers it.
 */
class ConfigToolLoader
{
    /**
     * @param class-string[] $toolClasses List of ToolInterface class names
     */
    public function __construct(
        private readonly array $toolClasses = [],
    ) {}

    /**
     * Register config-declared tools into the registry.
     * Uses a callable factory to support DI container resolution.
     */
    public function load(ToolRegistry $registry, ?callable $factory = null): void
    {
        foreach ($this->toolClasses as $className) {
            if (!is_string($className) || !class_exists($className)) {
                continue;
            }

            $tool = $factory !== null
                ? $factory($className)
                : new $className();

            if ($tool instanceof ToolInterface) {
                $registry->register($tool);
            }
        }
    }
}
