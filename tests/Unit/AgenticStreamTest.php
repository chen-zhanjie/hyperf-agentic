<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\PromptBuilder;

class AgenticStreamTest extends TestCase
{
    private Agentic $agentic;
    private AgentRunner $runner;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(AgentRunner::class);

        $this->agentic = new Agentic(
            runner: $this->runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: $this->createMock(PromptBuilder::class),
            agentDefs: [
                'default' => ['max_iterations' => 10],
                '__llm__' => ['provider' => 'openai', 'model' => 'gpt-4'],
            ],
        );
    }

    public function testRunStreamCallsRunnerRun(): void
    {
        $result = AgentResult::complete('response', 1, 50, 10, 10);
        $onEvent = fn() => null;

        $this->runner->expects($this->once())
            ->method('run')
            ->with(
                [['role' => 'user', 'content' => 'hello']],
                $this->callback(fn(array $c) => $c['max_iterations'] === 10),
                [],
                $onEvent,
            )
            ->willReturn($result);

        $returned = $this->agentic->runStream('default', [
            ['role' => 'user', 'content' => 'hello'],
        ], $onEvent);

        $this->assertSame('response', $returned->content);
    }

    public function testChatStreamCallsRunnerChatStream(): void
    {
        $this->runner->expects($this->once())
            ->method('chatStream')
            ->with(
                [['role' => 'user', 'content' => 'hi']],
                $this->callback(fn(array $o) => $o['provider'] === 'openai'),
                $this->isType('callable'),
            );

        $this->agentic->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            fn(string $chunk) => null,
        );
    }

    public function testChatStreamWithModelOverride(): void
    {
        $this->runner->expects($this->once())
            ->method('chatStream')
            ->with(
                $this->anything(),
                $this->callback(fn(array $o) => $o['model'] === 'gpt-3.5-turbo'),
                $this->anything(),
            );

        $this->agentic->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            fn(string $chunk) => null,
            ['model_override' => 'gpt-3.5-turbo'],
        );
    }
}
