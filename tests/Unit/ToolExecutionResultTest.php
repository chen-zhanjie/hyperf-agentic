<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\ToolExecutionResult;

class ToolExecutionResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = ToolExecutionResult::success('my_tool', 'output data');
        $this->assertTrue($result->success);
        $this->assertSame('my_tool', $result->toolName);
        $this->assertSame('output data', $result->content);
        $this->assertFalse($result->truncated);
    }

    public function testSuccessWithTruncation(): void
    {
        $result = ToolExecutionResult::success('tool', 'long...', truncated: true);
        $this->assertTrue($result->truncated);
    }

    public function testErrorFactory(): void
    {
        $result = ToolExecutionResult::error('tool', 'connection refused');
        $this->assertFalse($result->success);
        $this->assertStringContainsString('connection refused', $result->content);
        $this->assertStringContainsString('tool', $result->content);
    }

    public function testErrorWithException(): void
    {
        $e = new \RuntimeException('timeout');
        $result = ToolExecutionResult::error('tool', 'failed', $e);
        $this->assertNotNull($result->exception);
        $this->assertSame($e, $result->exception);
    }

    public function testToTextReturnsContent(): void
    {
        $result = ToolExecutionResult::success('t', 'hello');
        $this->assertSame('hello', $result->toText());
    }
}
