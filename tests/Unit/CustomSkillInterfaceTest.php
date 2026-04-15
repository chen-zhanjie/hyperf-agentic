<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\SkillInterface;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\Tool\Builtin\SkillTool;

class CustomSkillInterfaceTest extends TestCase
{
    // ── Custom SkillInterface implementation (database-driven) ──

    private function createDatabaseSkill(
        string $name,
        string $description,
        string $instructions,
        array $resources = [],
        array $tools = [],
        bool $autoInvoke = true,
        bool $userInvocable = true,
    ): SkillInterface {
        return new class(
            $name, $description, $instructions, $resources, $tools, $autoInvoke, $userInvocable,
        ) implements SkillInterface {
            public function __construct(
                private readonly string $skillName,
                private readonly string $skillDescription,
                private readonly string $skillInstructions,
                private readonly array $skillResources,
                private readonly array $skillTools,
                private readonly bool $skillAutoInvoke,
                private readonly bool $skillUserInvocable,
            ) {}

            public function name(): string { return $this->skillName; }
            public function description(): string { return $this->skillDescription; }
            public function toDescriptionLine(): string
            {
                return "- {$this->skillName}: {$this->skillDescription}";
            }
            public function toFullInstructions(): string { return $this->skillInstructions; }
            public function loadResource(string $relativePath): ?string
            {
                return $this->skillResources[$relativePath] ?? null;
            }
            public function tools(): array { return $this->skillTools; }
            public function autoInvoke(): bool { return $this->skillAutoInvoke; }
            public function userInvocable(): bool { return $this->skillUserInvocable; }
        };
    }

    // ── Registration ──

    public function testCustomSkillRegistersInRegistry(): void
    {
        $skill = $this->createDatabaseSkill('query-builder', 'Build SQL queries', 'Instructions...');
        $registry = new SkillRegistry();
        $registry->register($skill);

        $this->assertSame('query-builder', $registry->get('query-builder')->name());
    }

    // ── Level 1: Description line ──

    public function testCustomSkillDescriptionLine(): void
    {
        $skill = $this->createDatabaseSkill('search', 'Search the database', 'Full instructions');
        $registry = new SkillRegistry();
        $registry->register($skill);

        $index = $registry->buildDescriptionIndex();
        $this->assertStringContainsString('- search: Search the database', $index);
    }

    // ── Level 2: Full instructions via SkillTool ──

    public function testCustomSkillFullInstructionsViaSkillTool(): void
    {
        $skill = $this->createDatabaseSkill(
            'report',
            'Generate reports',
            "# Report Generator\n\n1. Gather data\n2. Format output",
        );
        $registry = new SkillRegistry();
        $registry->register($skill);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['name' => 'report']);

        $this->assertStringContainsString('Report Generator', $result);
        $this->assertStringContainsString('Gather data', $result);
    }

    // ── Level 3: Resource loading via SkillTool ──

    public function testCustomSkillResourceLoadingViaSkillTool(): void
    {
        $skill = $this->createDatabaseSkill(
            'analytics',
            'Analytics skill',
            'Instructions',
            ['templates/query.sql' => 'SELECT * FROM users WHERE active = 1'],
        );
        $registry = new SkillRegistry();
        $registry->register($skill);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['name' => 'analytics', 'resource' => 'templates/query.sql']);

        $this->assertSame('SELECT * FROM users WHERE active = 1', $result);
    }

    public function testCustomSkillResourceNotFoundReturnsNull(): void
    {
        $skill = $this->createDatabaseSkill('analytics', 'Analytics', 'Instructions');
        $registry = new SkillRegistry();
        $registry->register($skill);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['name' => 'analytics', 'resource' => 'nonexistent.txt']);

        $this->assertStringContainsString('不存在', $result);
    }

    // ── Auto-invoke filtering ──

    public function testCustomSkillAutoInvokeFiltering(): void
    {
        $auto = $this->createDatabaseSkill('auto-skill', 'Auto', 'Inst', [], [], true, true);
        $manual = $this->createDatabaseSkill('manual-skill', 'Manual', 'Inst', [], [], false, true);

        $registry = new SkillRegistry();
        $registry->register($auto);
        $registry->register($manual);

        $autoInvocable = $registry->getAutoInvocable();
        $this->assertCount(1, $autoInvocable);
        $this->assertArrayHasKey('auto-skill', $autoInvocable);
    }

    // ── Tools binding ──

    public function testCustomSkillToolsBinding(): void
    {
        $skill = $this->createDatabaseSkill(
            'data-export', 'Export', 'Inst', [], ['query', 'format'],
        );
        $registry = new SkillRegistry();
        $registry->register($skill);

        $tools = $registry->getSkillTools(['data-export']);
        $this->assertSame(['query', 'format'], $tools);
    }
}
