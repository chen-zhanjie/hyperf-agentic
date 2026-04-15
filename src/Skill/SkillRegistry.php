<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Skill;

use ChenZhanjie\Agentic\Contract\SkillInterface;

class SkillRegistry
{
    /** @var array<string, SkillInterface> */
    private array $skills = [];

    public function register(SkillInterface $skill): void
    {
        $this->skills[$skill->name()] = $skill;
    }

    public function loadFromDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*/SKILL.md') ?: [] as $skillFile) {
            $this->register(Skill::fromMarkdownFile($skillFile));
        }
    }

    public function get(string $name): ?SkillInterface
    {
        return $this->skills[$name] ?? null;
    }

    /** Skills that LLM can auto-invoke */
    public function getAutoInvocable(array $enabledNames = []): array
    {
        $pool = empty($enabledNames)
            ? $this->skills
            : array_intersect_key($this->skills, array_flip($enabledNames));

        return array_filter($pool, fn(SkillInterface $s) => $s->autoInvoke());
    }

    public function getUserInvocable(): array
    {
        return array_filter($this->skills, fn(SkillInterface $s) => $s->userInvocable());
    }

    /** Level 1: description index for cached prompt */
    public function buildDescriptionIndex(array $enabledNames = []): string
    {
        $skills = $this->getAutoInvocable($enabledNames);
        if (empty($skills)) {
            return '';
        }

        $lines = ["## 可用技能\n", "当需要遵循特定操作规范时，调用 skill 工具获取完整指南：\n"];
        foreach ($skills as $skill) {
            $lines[] = $skill->toDescriptionLine();
        }

        return implode("\n", $lines);
    }

    /** Get all tool names bound to specified skills */
    public function getSkillTools(array $skillNames): array
    {
        $tools = [];
        foreach ($skillNames as $name) {
            if (isset($this->skills[$name])) {
                $tools = array_merge($tools, $this->skills[$name]->tools());
            }
        }
        return array_unique($tools);
    }

    public function allNames(): array
    {
        return array_keys($this->skills);
    }

    public function count(): int
    {
        return count($this->skills);
    }
}
