<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentConfigManager;

class AgentConfigManagerTest extends TestCase
{
    private AgentConfigManager $manager;

    protected function setUp(): void
    {
        $this->manager = new AgentConfigManager(
            defaults: ['max_iterations' => 15, 'scene' => 'http'],
            agentDefs: [
                'default' => ['system_prompt' => 'You are helpful', 'tools' => ['search']],
                'coder' => ['system_prompt' => 'You write code', 'max_iterations' => 30],
            ],
        );
    }

    public function testGetReturnsMergedConfig(): void
    {
        $config = $this->manager->get('default');

        $this->assertSame(15, $config['max_iterations']);
        $this->assertSame('http', $config['scene']);
        $this->assertSame('You are helpful', $config['system_prompt']);
        $this->assertSame(['search'], $config['tools']);
    }

    public function testGetAgentOverridesDefaults(): void
    {
        $config = $this->manager->get('coder');

        $this->assertSame(30, $config['max_iterations']); // overridden by agent
        $this->assertSame('http', $config['scene']); // inherited from defaults
    }

    public function testGetWithRuntimeOverrides(): void
    {
        $config = $this->manager->get('default', ['max_iterations' => 5]);

        $this->assertSame(5, $config['max_iterations']); // runtime wins
    }

    public function testGetThrowsForUndefinedAgent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent [unknown] is not defined');

        $this->manager->get('unknown');
    }

    public function testListAgentNames(): void
    {
        $names = $this->manager->listAgentNames();
        $this->assertSame(['default', 'coder'], $names);
    }

    public function testHasReturnsTrue(): void
    {
        $this->assertTrue($this->manager->has('default'));
    }

    public function testHasReturnsFalse(): void
    {
        $this->assertFalse($this->manager->has('nonexistent'));
    }

    public function testDeepMergeWithArrays(): void
    {
        $manager = new AgentConfigManager(
            defaults: ['tools' => ['search', 'ask']],
            agentDefs: [
                'default' => ['tools' => ['code']],
            ],
        );

        $config = $manager->get('default');
        // array_replace_recursive replaces array keys by position
        $this->assertSame(['code', 'ask'], $config['tools']);
    }
}
