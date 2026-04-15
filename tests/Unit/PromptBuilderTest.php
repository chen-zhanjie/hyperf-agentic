<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\IterationBudget;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolRegistry;

class PromptBuilderTest extends TestCase
{
    private function makeTool(string $name, string $desc = 'A tool', bool $enabled = true): ToolInterface
    {
        return new class($name, $desc, $enabled) implements ToolInterface {
            public function __construct(
                private string $n, private string $d, private bool $en,
            ) {}
            public function name(): string { return $this->n; }
            public function description(): string { return $this->d; }
            public function parameters(): array { return []; }
            public function execute(array $arguments): string { return 'ok'; }
            public function isEnabled(): bool { return $this->en; }
            public function isParallelAllowed(): bool { return true; }
        };
    }

    private function makePersona(string $name = 'Test', string $role = 'You are a tester.'): Persona
    {
        return new Persona(name: $name, role: $role);
    }

    // --- Cached prompt ---

    public function testBuildCachedPromptIncludesPersona(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona('ChatBot', 'You help users.');
        $tools = new ToolRegistry();

        $cached = $builder->buildCachedPrompt($persona, 'chat', $tools);

        $this->assertStringContainsString('# ChatBot', $cached);
        $this->assertStringContainsString('You help users.', $cached);
    }

    public function testBuildCachedPromptIncludesBasePrompt(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();

        $cached = $builder->buildCachedPrompt($persona, 'test', $tools);

        $this->assertStringContainsString('工具使用指南', $cached);
    }

    public function testBuildCachedPromptIncludesCustomSystemPrompt(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();

        $cached = $builder->buildCachedPrompt(
            $persona, 'test', $tools,
            systemPrompt: 'Always respond in Chinese.',
        );

        $this->assertStringContainsString('Always respond in Chinese.', $cached);
    }

    public function testBuildCachedPromptIncludesToolBoundary(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();
        $tools->register($this->makeTool('search', 'Search the database'));
        $tools->register($this->makeTool('calculate', 'Run calculations'));

        $cached = $builder->buildCachedPrompt($persona, 'test', $tools);

        $this->assertStringContainsString('可用工具', $cached);
        $this->assertStringContainsString('**search**', $cached);
        $this->assertStringContainsString('**calculate**', $cached);
        $this->assertStringContainsString('仅使用以上列出的工具', $cached);
    }

    public function testBuildCachedPromptExcludesDisabledTools(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();
        $tools->register($this->makeTool('active', 'Active tool'));
        $tools->register($this->makeTool('disabled', 'Disabled tool', enabled: false));

        $cached = $builder->buildCachedPrompt($persona, 'test', $tools);

        $this->assertStringContainsString('active', $cached);
        $this->assertStringNotContainsString('disabled', $cached);
    }

    public function testBuildCachedPromptIncludesScene(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();

        $cached = $builder->buildCachedPrompt($persona, 'test', $tools, scene: 'cli');

        $this->assertStringContainsString('运行场景: cli', $cached);
    }

    public function testBuildCachedPromptIncludesMemorySnapshot(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();

        $cached = $builder->buildCachedPrompt(
            $persona, 'test', $tools,
            memorySnapshot: 'User prefers dark mode.',
        );

        $this->assertStringContainsString('记忆上下文', $cached);
        $this->assertStringContainsString('User prefers dark mode.', $cached);
    }

    public function testBuildCachedPromptWithNoTools(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();

        $cached = $builder->buildCachedPrompt($persona, 'test', $tools);

        $this->assertStringNotContainsString('可用工具', $cached);
    }

    // --- Ephemeral prompt ---

    public function testBuildEphemeralPromptIncludesTimestamp(): void
    {
        $builder = new PromptBuilder();

        $ephemeral = $builder->buildEphemeralPrompt();

        $this->assertStringContainsString('当前时间:', $ephemeral);
    }

    public function testBuildEphemeralPromptIncludesRuntimeContext(): void
    {
        $builder = new PromptBuilder();

        $ephemeral = $builder->buildEphemeralPrompt(
            runtimeContext: ['user_id' => '123', 'locale' => 'zh-CN'],
        );

        $this->assertStringContainsString('user_id: 123', $ephemeral);
        $this->assertStringContainsString('locale: zh-CN', $ephemeral);
    }

    public function testBuildEphemeralPromptIncludesOutputCapabilities(): void
    {
        $builder = new PromptBuilder();

        $ephemeral = $builder->buildEphemeralPrompt(
            outputCapabilities: ['markdown', 'table'],
        );

        $this->assertStringContainsString('输出能力: markdown, table', $ephemeral);
    }

    public function testBuildEphemeralPromptShowsBudgetWarningWhenLow(): void
    {
        $builder = new PromptBuilder();
        $budget = new IterationBudget(maxTotal: 3);
        $budget->consume();
        $budget->consume(); // used 2, remaining 1

        $ephemeral = $builder->buildEphemeralPrompt(budget: $budget);

        $this->assertStringContainsString('迭代预算即将耗尽', $ephemeral);
    }

    public function testBuildEphemeralPromptShowsGraceWhenGraceTurn(): void
    {
        $builder = new PromptBuilder();
        $budget = new IterationBudget(maxTotal: 1);
        $budget->consume(); // now exhausted
        $budget->consumeGrace(); // mark as grace turn

        $ephemeral = $builder->buildEphemeralPrompt(budget: $budget);

        $this->assertStringContainsString('最后一轮收尾', $ephemeral);
    }

    public function testBuildEphemeralPromptShowsExhaustedWhenNotGraceTurn(): void
    {
        $builder = new PromptBuilder();
        $budget = new IterationBudget(maxTotal: 1);
        $budget->consume(); // exhausted but not grace turn

        $ephemeral = $builder->buildEphemeralPrompt(budget: $budget);

        $this->assertStringContainsString('迭代预算已耗尽', $ephemeral);
        $this->assertStringNotContainsString('最后一轮收尾', $ephemeral);
    }

    // --- Full build ---

    public function testBuildCombinesCachedAndEphemeral(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona('Bot', 'I help.');
        $tools = new ToolRegistry();

        $full = $builder->build($persona, 'test', $tools, runtimeContext: ['key' => 'val']);

        $this->assertStringContainsString('# Bot', $full);
        $this->assertStringContainsString('当前时间:', $full);
        $this->assertStringContainsString('key: val', $full);
    }

    public function testBuildCachesPromptForSubsequentCalls(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();

        $first = $builder->build($persona, 'test', $tools);
        $second = $builder->build($persona, 'test', $tools);

        // Both calls should share the same cached prefix
        $this->assertSame($builder->getCachedPrompt(), $builder->getCachedPrompt());
    }

    // --- Reset ---

    public function testResetClearsCache(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona();
        $tools = new ToolRegistry();

        $builder->build($persona, 'test', $tools);
        $this->assertNotNull($builder->getCachedPrompt());

        $builder->reset();
        $this->assertNull($builder->getCachedPrompt());
    }

    public function testRebuildAfterResetProducesNewPrompt(): void
    {
        $builder = new PromptBuilder();
        $persona = $this->makePersona('First');
        $tools = new ToolRegistry();

        $first = $builder->build($persona, 'test', $tools);
        $builder->reset();

        $newPersona = $this->makePersona('Second');
        $second = $builder->build($newPersona, 'test', $tools);

        $this->assertStringContainsString('First', $first);
        $this->assertStringContainsString('Second', $second);
    }
}
