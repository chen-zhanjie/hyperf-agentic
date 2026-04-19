<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\ToolInterface;

class ToolEntry
{
    public function __construct(
        public readonly ToolInterface $tool,
        public readonly string $group = 'default',
        public readonly int $maxResultSize = 100000,
        public readonly bool $builtin = false,
    ) {}
}
