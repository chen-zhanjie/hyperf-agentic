<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Loader;

use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\ToolRegistry;

/**
 * Loads tools discovered via #[AsTool] annotation scanning.
 * Receives the list of annotated class names (typically from Hyperf AnnotationCollector).
 */
class AnnotationToolLoader
{
    /**
     * @param class-string[] $annotatedClasses Classes found by annotation scanner
     */
    public function __construct(
        private readonly array $annotatedClasses = [],
    ) {}

    /**
     * Register annotation-discovered tools into the registry.
     * Uses a callable factory to support DI container resolution.
     */
    public function load(ToolRegistry $registry, ?callable $factory = null): void
    {
        foreach ($this->annotatedClasses as $className) {
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
