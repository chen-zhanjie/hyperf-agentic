<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Loader\SkillLoader;
use ChenZhanjie\Agentic\Skill\SkillRegistry;

class SkillLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/agentic_skill_loader_' . uniqid();
        mkdir($this->tmpDir . '/my-skill', 0777, true);
        file_put_contents($this->tmpDir . '/my-skill/SKILL.md', "---\nname: my-skill\ndescription: Test skill\n---\n\n# Instructions");
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

    public function testLoadScansDirectories(): void
    {
        $loader = new SkillLoader(directories: [$this->tmpDir]);
        $registry = new SkillRegistry();
        $loader->load($registry);

        $this->assertSame(1, $registry->count());
        $this->assertNotNull($registry->get('my-skill'));
    }

    public function testLoadWithMultipleDirectories(): void
    {
        $dir2 = $this->tmpDir . '_2';
        mkdir($dir2 . '/other-skill', 0777, true);
        file_put_contents($dir2 . '/other-skill/SKILL.md', "---\nname: other-skill\ndescription: Other\n---\n");

        $loader = new SkillLoader(directories: [$this->tmpDir, $dir2]);
        $registry = new SkillRegistry();
        $loader->load($registry);

        $this->assertSame(2, $registry->count());
        $this->rmdir($dir2);
    }

    public function testLoadWithEmptyDirectories(): void
    {
        $loader = new SkillLoader(directories: []);
        $registry = new SkillRegistry();
        $loader->load($registry);

        $this->assertSame(0, $registry->count());
    }
}
