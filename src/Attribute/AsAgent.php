<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Attribute;

use Attribute;

/**
 * Marks a class as an Agent definition.
 * Reserved for future use — current SDK is config-driven.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsAgent
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $description = '',
    ) {}
}
