<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Attribute\AsAgent;

class AsAgentTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attr = new AsAgent();
        $this->assertSame('', $attr->name);
        $this->assertSame('', $attr->description);
    }

    public function testCustomValues(): void
    {
        $attr = new AsAgent(name: 'chat', description: 'Chat agent');
        $this->assertSame('chat', $attr->name);
        $this->assertSame('Chat agent', $attr->description);
    }
}
