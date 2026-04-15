<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Exception\AgentSuspendedException;

class AgentSuspendedExceptionTest extends TestCase
{
    public function testExceptionCarriesResumeToken(): void
    {
        $ex = new AgentSuspendedException('awaiting_input', ['key' => 'val'], 'token123');
        $this->assertSame('awaiting_input', $ex->getMessage());
        $this->assertSame('token123', $ex->getResumeToken());
    }

    public function testExceptionWithEmptyToken(): void
    {
        $ex = new AgentSuspendedException('paused');
        $this->assertSame('', $ex->getResumeToken());
    }
}
