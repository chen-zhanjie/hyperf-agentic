<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Attribute\AsTool;

class AsToolTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attr = new AsTool();
        $this->assertSame('default', $attr->group);
        $this->assertSame(8000, $attr->maxResultSize);
    }

    public function testCustomValues(): void
    {
        $attr = new AsTool(group: 'builtin', maxResultSize: 4096);
        $this->assertSame('builtin', $attr->group);
        $this->assertSame(4096, $attr->maxResultSize);
    }

    public function testReadonlyProperties(): void
    {
        $attr = new AsTool(group: 'api');
        $this->assertSame('api', $attr->group);
    }
}
