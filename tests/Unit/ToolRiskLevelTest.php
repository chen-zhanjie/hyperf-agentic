<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\ToolRiskLevel;

class ToolRiskLevelTest extends TestCase
{
    public function testRiskLevelValues(): void
    {
        $this->assertSame('low', ToolRiskLevel::LOW->value);
        $this->assertSame('medium', ToolRiskLevel::MEDIUM->value);
        $this->assertSame('high', ToolRiskLevel::HIGH->value);
        $this->assertSame('critical', ToolRiskLevel::CRITICAL->value);
    }

    public function testRiskLevelFromValue(): void
    {
        $this->assertSame(ToolRiskLevel::LOW, ToolRiskLevel::from('low'));
        $this->assertSame(ToolRiskLevel::CRITICAL, ToolRiskLevel::from('critical'));
    }
}
