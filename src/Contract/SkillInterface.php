<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

/**
 * Skill interface — supports 3-level progressive disclosure.
 *
 * Level 1: Description line → injected into cached prompt
 * Level 2: Full instructions → loaded via SkillTool on demand
 * Level 3: Resources → loaded via SkillTool's resource parameter on demand
 */
interface SkillInterface
{
    public function name(): string;

    public function description(): string;

    /** Level 1: Single-line description for the cached prompt */
    public function toDescriptionLine(): string;

    /** Level 2: Full instructions loaded on demand */
    public function toFullInstructions(): string;

    /** Level 3: Load a specific resource by relative path */
    public function loadResource(string $relativePath): ?string;

    /** Tool names bound to this skill */
    public function tools(): array;

    /** Whether the LLM can auto-invoke this skill */
    public function autoInvoke(): bool;

    /** Whether the user can explicitly invoke this skill */
    public function userInvocable(): bool;
}
