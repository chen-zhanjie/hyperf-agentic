<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Skill\Skill;
use ChenZhanjie\Agentic\Skill\SkillRegistry;

class SkillRegistryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/agentic_skill_test_' . uniqid();
        mkdir($this->tmpDir . '/php-testing', 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->rmdir($this->tmpDir);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createSkillFile(string $dirName, string $name, string $description, array $extra = []): void
    {
        $dir = $this->tmpDir . '/' . $dirName;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $frontmatter = array_merge(['name' => $name, 'description' => $description], $extra);
        $yaml = '';
        foreach ($frontmatter as $k => $v) {
            if (is_array($v)) {
                $yaml .= "{$k}:\n";
                foreach ($v as $item) $yaml .= "  - {$item}\n";
            } elseif (is_bool($v)) {
                $yaml .= "{$k}: " . ($v ? 'true' : 'false') . "\n";
            } else {
                $yaml .= "{$k}: {$v}\n";
            }
        }

        file_put_contents($dir . '/SKILL.md', "---\n{$yaml}---\n\n# Instructions\nDo the thing.");
    }

    // --- Register + Get ---

    public function testRegisterAndGet(): void
    {
        $this->createSkillFile('test-skill', 'test-skill', 'A test skill');
        $skill = Skill::fromMarkdownFile($this->tmpDir . '/test-skill/SKILL.md');

        $registry = new SkillRegistry();
        $registry->register($skill);

        $this->assertSame($skill, $registry->get('test-skill'));
        $this->assertSame('test-skill', $registry->get('test-skill')->name());
        $this->assertNull($registry->get('nonexistent'));
    }

    public function testLoadFromDirectory(): void
    {
        $this->createSkillFile('skill-a', 'skill-a', 'Skill A');
        $this->createSkillFile('skill-b', 'skill-b', 'Skill B');

        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $this->assertSame(2, $registry->count());
        $this->assertNotNull($registry->get('skill-a'));
        $this->assertNotNull($registry->get('skill-b'));
    }

    public function testLoadFromNonexistentDirectoryDoesNotError(): void
    {
        $registry = new SkillRegistry();
        $registry->loadFromDirectory('/nonexistent/path');
        $this->assertSame(0, $registry->count());
    }

    // --- Description index ---

    public function testBuildDescriptionIndex(): void
    {
        $this->createSkillFile('php-test', 'php-test', 'PHP testing patterns');
        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $index = $registry->buildDescriptionIndex();
        $this->assertStringContainsString('php-test', $index);
        $this->assertStringContainsString('PHP testing patterns', $index);
    }

    public function testBuildDescriptionIndexReturnsEmptyForNoSkills(): void
    {
        $registry = new SkillRegistry();
        $this->assertSame('', $registry->buildDescriptionIndex());
    }

    // --- Filtering ---

    public function testGetAutoInvocableExcludesDisabled(): void
    {
        $this->createSkillFile('auto-on', 'auto-on', 'Auto on');
        $this->createSkillFile('auto-off', 'auto-off', 'Auto off', ['disable-auto-invoke' => true]);

        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $auto = $registry->getAutoInvocable();
        $this->assertCount(1, $auto);
        $this->assertArrayHasKey('auto-on', $auto);
    }

    public function testGetAutoInvocableFiltersByName(): void
    {
        $this->createSkillFile('skill-x', 'skill-x', 'X');
        $this->createSkillFile('skill-y', 'skill-y', 'Y');

        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $filtered = $registry->getAutoInvocable(['skill-x']);
        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey('skill-x', $filtered);
    }

    public function testGetUserInvocable(): void
    {
        $this->createSkillFile('user-skill', 'user-skill', 'User');
        $this->createSkillFile('hidden', 'hidden', 'Hidden', ['user-invocable' => false]);

        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $user = $registry->getUserInvocable();
        $this->assertCount(1, $user);
    }

    // --- getSkillTools ---

    public function testGetSkillToolsMergesAndDeduplicates(): void
    {
        $this->createSkillFile('sa', 'sa', 'SA', ['tools' => ['tool1', 'tool2']]);
        $this->createSkillFile('sb', 'sb', 'SB', ['tools' => ['tool2', 'tool3']]);

        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $tools = $registry->getSkillTools(['sa', 'sb']);
        sort($tools);
        $this->assertSame(['tool1', 'tool2', 'tool3'], $tools);
    }

    public function testGetSkillToolsIgnoresUnknownSkills(): void
    {
        $this->createSkillFile('sc', 'sc', 'SC', ['tools' => ['t1']]);
        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $tools = $registry->getSkillTools(['sc', 'nonexistent']);
        $this->assertSame(['t1'], $tools);
    }

    // --- allNames ---

    public function testAllNames(): void
    {
        $this->createSkillFile('alpha', 'alpha', 'A');
        $this->createSkillFile('beta', 'beta', 'B');

        $registry = new SkillRegistry();
        $registry->loadFromDirectory($this->tmpDir);

        $names = $registry->allNames();
        sort($names);
        $this->assertSame(['alpha', 'beta'], $names);
    }
}

class SkillTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/agentic_skill_unit_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testFromMarkdownFileParsesFrontmatter(): void
    {
        $dir = $this->tmpDir . '/my-skill';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', <<<MD
---
name: my-skill
description: Does something useful
tools:
  - tool-a
  - tool-b
---

# My Skill
Instructions here.
MD);

        $skill = Skill::fromMarkdownFile($dir . '/SKILL.md');
        $this->assertSame('my-skill', $skill->name());
        $this->assertSame('Does something useful', $skill->description());
        $this->assertSame(['tool-a', 'tool-b'], $skill->tools());
        $this->assertTrue($skill->autoInvoke());
    }

    public function testFromMarkdownFileThrowsForMissingFrontmatter(): void
    {
        $dir = $this->tmpDir . '/bad-skill';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', 'No frontmatter here');

        $this->expectException(\RuntimeException::class);
        Skill::fromMarkdownFile($dir . '/SKILL.md');
    }

    public function testFromMarkdownFileThrowsForMissingName(): void
    {
        $dir = $this->tmpDir . '/no-name';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\ndescription: has desc\n---\n");

        $this->expectException(\RuntimeException::class);
        Skill::fromMarkdownFile($dir . '/SKILL.md');
    }

    public function testToDescriptionLine(): void
    {
        $dir = $this->tmpDir . '/desc-test';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: desc-test\ndescription: Short desc\n---\n");

        $skill = Skill::fromMarkdownFile($dir . '/SKILL.md');
        $this->assertSame('- desc-test: Short desc', $skill->toDescriptionLine());
    }

    public function testToFullInstructionsIncludesContentAndResources(): void
    {
        $dir = $this->tmpDir . '/full-test';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: full-test\ndescription: Full\n---\n\n# Instructions\nDo it.");

        $skill = Skill::fromMarkdownFile($dir . '/SKILL.md');
        $full = $skill->toFullInstructions();
        $this->assertStringContainsString('Do it.', $full);
    }

    public function testLoadResourceReturnsNullForTraversal(): void
    {
        $dir = $this->tmpDir . '/traversal-test';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: traversal-test\ndescription: T\n---\n");

        $skill = Skill::fromMarkdownFile($dir . '/SKILL.md');
        $this->assertNull($skill->loadResource('../../../etc/passwd'));
    }

    public function testLoadResourceReturnsContentForValidFile(): void
    {
        $dir = $this->tmpDir . '/resource-test';
        mkdir($dir . '/references', 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: resource-test\ndescription: R\n---\n");
        file_put_contents($dir . '/references/data.txt', 'hello world');

        $skill = Skill::fromMarkdownFile($dir . '/SKILL.md');
        $content = $skill->loadResource('references/data.txt');
        $this->assertSame('hello world', $content);
    }

    public function testLoadResourceReturnsNullForNonexistentFile(): void
    {
        $dir = $this->tmpDir . '/missing-test';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: missing-test\ndescription: M\n---\n");

        $skill = Skill::fromMarkdownFile($dir . '/SKILL.md');
        $this->assertNull($skill->loadResource('nonexistent.txt'));
    }
}
