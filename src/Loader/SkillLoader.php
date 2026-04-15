<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Loader;

use ChenZhanjie\Agentic\Skill\SkillRegistry;

/**
 * Loads skills from a directory into the SkillRegistry.
 * Each subdirectory with a SKILL.md is loaded.
 */
class SkillLoader
{
    /**
     * @param string[] $directories List of directories to scan for skills
     */
    public function __construct(
        private readonly array $directories = [],
    ) {}

    public function load(SkillRegistry $registry): void
    {
        foreach ($this->directories as $dir) {
            $registry->loadFromDirectory($dir);
        }
    }
}
