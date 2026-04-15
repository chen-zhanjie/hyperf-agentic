<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\SessionStoreInterface;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\PromptBuilder;

class AgenticResumeTest extends TestCase
{
    private Agentic $agentic;
    private AgentRunner $runner;
    private SessionStoreInterface $sessionStore;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(AgentRunner::class);
        $this->sessionStore = $this->createMock(SessionStoreInterface::class);

        $this->agentic = new Agentic(
            runner: $this->runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: $this->createMock(PromptBuilder::class),
            sessionStore: $this->sessionStore,
        );
    }

    public function testResumeRestoresStateAndCallsRunner(): void
    {
        $state = [
            'messages' => [['role' => 'user', 'content' => 'hello']],
            'remaining_iterations' => 5,
            'agent_config' => ['max_iterations' => 10],
            'agent_name' => 'default',
        ];

        $result = AgentResult::complete('Done', 1, 50, 10, 10);

        $this->sessionStore->method('getAndDelete')
            ->with('sess_1', 'suspended_agent')
            ->willReturn($state);

        $this->runner->expects($this->once())
            ->method('resume')
            ->with($state, 'sess_1')
            ->willReturn($result);

        $returned = $this->agentic->resume('sess_1');
        $this->assertSame('Done', $returned->content);
    }

    public function testResumeThrowsWhenSessionNotFound(): void
    {
        $this->sessionStore->method('getAndDelete')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Session sess_missing not found or expired');

        $this->agentic->resume('sess_missing');
    }

    public function testResumeThrowsWhenStateInvalid(): void
    {
        $this->sessionStore->method('getAndDelete')->willReturn([]); // empty state

        $this->expectException(\RuntimeException::class);

        $this->agentic->resume('sess_bad');
    }
}
