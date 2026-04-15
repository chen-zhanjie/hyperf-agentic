<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\SkillRegistryFactory;

class SkillRegistryFactoryTest extends TestCase
{
    public function testFactoryReturnsEmptyRegistryWhenNoDir(): void
    {
        $factory = new SkillRegistryFactory('');
        $registry = ($factory)();

        $this->assertInstanceOf(SkillRegistry::class, $registry);
        $this->assertSame(0, $registry->count());
    }

    public function testFactoryReturnsEmptyRegistryWhenDirNotExist(): void
    {
        $factory = new SkillRegistryFactory('/nonexistent/path/skills');
        $registry = ($factory)();

        $this->assertInstanceOf(SkillRegistry::class, $registry);
    }
}
