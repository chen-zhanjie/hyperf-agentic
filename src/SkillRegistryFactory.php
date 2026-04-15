<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Skill\SkillRegistry;

/**
 * SkillRegistry factory — loads all SKILL.md files at Hyperf startup.
 */
class SkillRegistryFactory
{
    public function __construct(
        private readonly string $skillsDirectory = '',
    ) {}

    public function __invoke(): SkillRegistry
    {
        $registry = new SkillRegistry();

        if ($this->skillsDirectory !== '' && is_dir($this->skillsDirectory)) {
            $registry->loadFromDirectory($this->skillsDirectory);
        }

        return $registry;
    }
}
