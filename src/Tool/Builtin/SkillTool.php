<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tool\Builtin;

use ChenZhanjie\Agentic\Contract\SkillInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\Skill\SkillRegistry;

/**
 * Built-in skill loading tool — 3-level progressive disclosure.
 *
 * Level 1: Description index (in cached prompt, always visible)
 * Level 2: Full SKILL.md instructions (loaded via this tool)
 * Level 3: Bundled resources (loaded via this tool's resource parameter)
 */
class SkillTool implements ToolInterface
{
    public function __construct(
        private readonly SkillRegistry $skillRegistry,
    ) {}

    public function name(): string
    {
        return 'skill';
    }

    public function description(): string
    {
        return '加载指定技能的完整操作指南和资源文件。当你认为需要遵循某个技能的规范来完成任务时，调用此工具获取详细指导。可加载 references/、scripts/、assets/ 目录下的资源文件。';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => '要加载的技能名称',
                ],
                'resource' => [
                    'type' => 'string',
                    'description' => '可选：技能目录下的资源文件相对路径（如 "references/query_templates.md"）。不指定则加载完整 SKILL.md 指令。',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments): string
    {
        $name = (string) ($arguments['name'] ?? '');
        /** @var SkillInterface|null $skill */
        $skill = $this->skillRegistry->get($name);

        if ($skill === null) {
            return "错误：技能 [{$name}] 未注册";
        }

        // Level 3: load bundled resource file
        if (isset($arguments['resource'])) {
            $resource = (string) $arguments['resource'];
            // Defense in depth: reject obviously malicious paths
            if (str_contains($resource, '..') || str_starts_with($resource, '/') || str_contains($resource, "\0")) {
                return "错误：资源文件路径 [{$resource}] 无效。";
            }
            $content = $skill->loadResource($resource);
            if ($content === null) {
                return "错误：资源文件 [{$resource}] 不存在或不可读。可用文件列表请查看技能指令。";
            }
            return $content;
        }

        // Level 2: full SKILL.md instructions
        return $skill->toFullInstructions();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isParallelAllowed(): bool
    {
        return true;
    }
}
