<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Attribute;

use Attribute;

/**
 * Marks a class as an Agent tool.
 * Hyperf AnnotationCollector will scan for this attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsTool
{
    public function __construct(
        public readonly string $group = 'default',
        public readonly int $maxResultSize = 8000,
    ) {}
}
