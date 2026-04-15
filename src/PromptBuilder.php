<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\Support\DefaultPrompts;

/**
 * 7-layer prompt builder with cached + ephemeral separation.
 *
 * Cached (built once per session):
 *   Layer 1: Persona (SOUL.md)
 *   Layer 2: SDK base prompt (base.md)
 *   Layer 3: Agent system_prompt (config)
 *   Layer 4: Tool boundary declarations
 *   Layer 5: Scene + dynamic context (memory/scene)
 *
 * Ephemeral (rebuilt each turn):
 *   Layer 6: Runtime context (frontend caps, @-mention, timestamp)
 *   Layer 7: Budget warning / Grace message
 */
class PromptBuilder
{
    private ?string $cachedPrompt = null;

    /**
     * Build cached layers — called once per session.
     */
    public function buildCachedPrompt(
        Persona $persona,
        string $agentName,
        ToolRegistry $tools,
        string $systemPrompt = '',
        string $scene = 'http',
        ?SkillRegistry $skillRegistry = null,
        array $enabledSkills = [],
        ?string $memorySnapshot = null,
    ): string {
        $layers = [];

        // Layer 1: Persona
        $personaText = $persona->toPromptText();
        if ($personaText !== '') {
            $layers[] = $personaText;
        }

        // Layer 2: SDK base prompt
        $basePrompt = DefaultPrompts::getBasePrompt();
        if ($basePrompt !== '') {
            $layers[] = $basePrompt;
        }

        // Layer 3: Agent custom system prompt
        if ($systemPrompt !== '') {
            $layers[] = $systemPrompt;
        }

        // Layer 4: Tool boundary declarations
        $toolBoundary = $this->buildToolBoundaryPrompt($tools);
        if ($toolBoundary !== '') {
            $layers[] = $toolBoundary;
        }

        // Layer 5: Scene + dynamic context
        $contextParts = [];
        if ($scene !== '') {
            $contextParts[] = "运行场景: {$scene}";
        }

        // Skill description index (Level 1)
        if ($skillRegistry !== null) {
            $skillIndex = $skillRegistry->buildDescriptionIndex($enabledSkills);
            if ($skillIndex !== '') {
                $contextParts[] = $skillIndex;
            }
        }

        // Memory snapshot
        if ($memorySnapshot !== null && $memorySnapshot !== '') {
            $contextParts[] = "## 记忆上下文\n{$memorySnapshot}";
        }

        if (!empty($contextParts)) {
            $layers[] = implode("\n\n", $contextParts);
        }

        $this->cachedPrompt = implode("\n\n---\n\n", $layers);
        return $this->cachedPrompt;
    }

    /**
     * Build ephemeral layers — called each turn.
     */
    public function buildEphemeralPrompt(
        array $runtimeContext = [],
        array $outputCapabilities = [],
        ?IterationBudget $budget = null,
        ?CostBudget $costBudget = null,
    ): string {
        $layers = [];

        // Layer 6: Runtime context
        $runtimeParts = [];
        if (!empty($runtimeContext)) {
            foreach ($runtimeContext as $key => $value) {
                $runtimeParts[] = "{$key}: {$value}";
            }
        }
        if (!empty($outputCapabilities)) {
            $runtimeParts[] = '输出能力: ' . implode(', ', $outputCapabilities);
        }
        $runtimeParts[] = '当前时间: ' . date('Y-m-d H:i:s');

        if (!empty($runtimeParts)) {
            $layers[] = implode("\n", $runtimeParts);
        }

        // Layer 7: Budget warning / Grace / CostBudget
        if ($budget !== null) {
            if ($budget->isGraceTurn()) {
                $layers[] = "⚠️ 迭代预算已耗尽，这是最后一轮收尾。请直接给出最终回答，不要再调用工具。";
            } elseif ($budget->isExhausted()) {
                $layers[] = "⚠️ 迭代预算已耗尽，请立即总结当前结果。";
            } elseif ($budget->remaining() <= 2) {
                $layers[] = "⚠️ 注意：迭代预算即将耗尽（剩余 {$budget->remaining()} 轮），请尽快收尾。";
            }
        }
        if ($costBudget !== null && $costBudget->isNearLimit() && !$costBudget->isExceeded()) {
            $remaining = $costBudget->remaining();
            $layers[] = "⚠️ Token 预算接近上限（剩余约 {$remaining} tokens），请尽快收尾，避免冗长的工具调用。";
        }

        return implode("\n\n", $layers);
    }

    /**
     * Build full system prompt = cached + ephemeral.
     */
    public function build(
        Persona $persona,
        string $agentName,
        ToolRegistry $tools,
        array $runtimeContext = [],
        ?IterationBudget $budget = null,
        string $systemPrompt = '',
        string $scene = 'http',
        ?SkillRegistry $skillRegistry = null,
        array $enabledSkills = [],
        ?CostBudget $costBudget = null,
    ): string {
        if ($this->cachedPrompt === null) {
            $this->buildCachedPrompt(
                $persona, $agentName, $tools, $systemPrompt, $scene,
                $skillRegistry, $enabledSkills,
            );
        }

        $ephemeral = $this->buildEphemeralPrompt($runtimeContext, [], $budget, $costBudget);

        if ($ephemeral === '') {
            return $this->cachedPrompt;
        }

        return $this->cachedPrompt . "\n\n---\n\n" . $ephemeral;
    }

    /**
     * Reset cached prompt (call when switching agents).
     */
    public function reset(): void
    {
        $this->cachedPrompt = null;
    }

    /**
     * Get the cached prompt (null if not built yet).
     */
    public function getCachedPrompt(): ?string
    {
        return $this->cachedPrompt;
    }

    /**
     * Layer 4: Build tool boundary declaration from available tools.
     */
    private function buildToolBoundaryPrompt(ToolRegistry $tools): string
    {
        if (!$tools->hasTools()) {
            return '';
        }

        $descriptions = $tools->getAvailableDescriptions();
        if (empty($descriptions)) {
            return '';
        }

        $lines = ["## 可用工具\n"];
        foreach ($descriptions as $name => $desc) {
            $lines[] = "- **{$name}**: {$desc}";
        }
        $lines[] = "\n仅使用以上列出的工具。不要调用不存在的工具。";

        return implode("\n", $lines);
    }
}
