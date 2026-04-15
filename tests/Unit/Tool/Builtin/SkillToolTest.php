<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Tool\Builtin;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Skill\Skill;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\Tool\Builtin\SkillTool;

class SkillToolTest extends TestCase
{
    public function testNameIsSkill(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $this->assertSame('skill', $tool->name());
    }

    public function testDescriptionMentionsLoading(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $this->assertStringContainsString('技能', $tool->description());
    }

    public function testParametersHasNameField(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $params = $tool->parameters();
        $this->assertSame('object', $params['type']);
        $this->assertArrayHasKey('name', $params['properties']);
        $this->assertContains('name', $params['required']);
    }

    public function testParametersHasOptionalResourceField(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $params = $tool->parameters();
        $this->assertArrayHasKey('resource', $params['properties']);
        // resource is NOT required
        $this->assertNotContains('resource', $params['required']);
    }

    public function testIsEnabledByDefault(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $this->assertTrue($tool->isEnabled());
    }

    public function testIsParallelAllowed(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $this->assertTrue($tool->isParallelAllowed());
    }

    public function testExecuteReturnsErrorForUnknownSkill(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $result = $tool->execute(['name' => 'nonexistent']);

        $this->assertStringContainsString('未注册', $result);
    }

    public function testExecuteLoadsSkillInstructions(): void
    {
        $registry = new SkillRegistry();
        $skill = $this->createMockSkill('test-skill', 'A test skill', 'Do test things');
        $registry->register($skill);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['name' => 'test-skill']);

        $this->assertStringContainsString('Do test things', $result);
    }

    public function testExecuteLoadsResourceFile(): void
    {
        $registry = new SkillRegistry();
        $skill = $this->createMockSkillWithResource(
            'db-query',
            'Query database',
            'Use LIMIT always',
            'references/templates.md',
            'SELECT * FROM orders LIMIT 50',
        );
        $registry->register($skill);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['name' => 'db-query', 'resource' => 'references/templates.md']);

        $this->assertStringContainsString('SELECT * FROM orders LIMIT 50', $result);
    }

    public function testExecuteReturnsErrorForMissingResource(): void
    {
        $registry = new SkillRegistry();
        $skill = $this->createMockSkillWithResource(
            'my-skill',
            'My skill',
            'Instructions',
            'references/exists.md',
            'content',
        );
        $registry->register($skill);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['name' => 'my-skill', 'resource' => 'references/missing.md']);

        $this->assertStringContainsString('不存在', $result);
    }

    // ── helpers ──

    private function createMockSkill(
        string $name,
        string $description,
        string $fullInstructions,
    ): Skill {
        return new class($name, $description, $fullInstructions) extends Skill {
            public function __construct(
                string $name,
                string $desc,
                private readonly string $instructions,
            ) {
                parent::__construct(
                    name: $name,
                    description: $desc,
                    directory: '/tmp/skills/' . $name,
                    filePath: '/tmp/skills/' . $name . '/SKILL.md',
                );
            }

            public function toFullInstructions(): string
            {
                return $this->instructions;
            }

            public function loadResource(string $relativePath): ?string
            {
                return null;
            }
        };
    }

    private function createMockSkillWithResource(
        string $name,
        string $description,
        string $fullInstructions,
        string $resourcePath,
        string $resourceContent,
    ): Skill {
        return new class($name, $description, $fullInstructions, $resourcePath, $resourceContent) extends Skill {
            public function __construct(
                string $name,
                string $desc,
                private readonly string $instructions,
                private readonly string $resPath,
                private readonly string $resContent,
            ) {
                parent::__construct(
                    name: $name,
                    description: $desc,
                    directory: '/tmp/skills/' . $name,
                    filePath: '/tmp/skills/' . $name . '/SKILL.md',
                );
            }

            public function toFullInstructions(): string
            {
                return $this->instructions;
            }

            public function loadResource(string $relativePath): ?string
            {
                return $relativePath === $this->resPath ? $this->resContent : null;
            }
        };
    }
}
