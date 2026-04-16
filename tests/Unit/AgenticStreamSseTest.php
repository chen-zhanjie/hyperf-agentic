<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\PromptBuilder;
use PHPUnit\Framework\TestCase;

class AgenticStreamSseTest extends TestCase
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

    public function testRunStreamSseCallsRunStreamAndProducesSse(): void
    {
        $result = AgentResult::complete('Hello world', 1, 50, 10, 10);
        $buffer = '';
        $write = function (string $line) use (&$buffer): void {
            $buffer .= $line;
        };

        $this->runner->expects($this->once())
            ->method('runStream')
            ->willReturnCallback(function (array $messages, array $config, array $options, ?callable $onEvent) use ($result) {
                // Simulate agent lifecycle events
                $onEvent('started', ['agent' => 'default']);
                $onEvent('text_delta', ['content' => 'Hello']);
                $onEvent('text_delta', ['content' => ' world']);
                $onEvent('complete', ['iterations' => 1, 'elapsed_ms' => 50, 'prompt_tokens' => 10, 'completion_tokens' => 10]);
                return $result;
            });

        $returned = $this->agentic->runStreamSse('default', [
            ['role' => 'user', 'content' => 'hello'],
        ], $write);

        $this->assertSame('Hello world', $returned->content);
        $this->assertStringContainsString('chat.completion.chunk', $buffer);
        $this->assertStringContainsString('"role":"assistant"', $buffer);
        $this->assertStringContainsString('"content":"Hello"', $buffer);
        $this->assertStringContainsString('"content":" world"', $buffer);
        $this->assertStringContainsString('"finish_reason":"stop"', $buffer);
        $this->assertStringContainsString('data: [DONE]', $buffer);
    }

    public function testChatStreamSseCallsChatStreamAndProducesSse(): void
    {
        $buffer = '';
        $write = function (string $line) use (&$buffer): void {
            $buffer .= $line;
        };

        $this->runner->expects($this->once())
            ->method('chatStream')
            ->willReturnCallback(function (array $messages, array $options, callable $onChunk) {
                $onChunk(['content' => 'Hi']);
                $onChunk(['content' => ' there']);
                return ['content' => 'Hi there', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5]];
            });

        $returned = $this->agentic->chatStreamSse([
            ['role' => 'user', 'content' => 'hello'],
        ], $write);

        $this->assertSame('Hi there', $returned['content']);
        $this->assertStringContainsString('chat.completion.chunk', $buffer);
        $this->assertStringContainsString('"role":"assistant"', $buffer);
        $this->assertStringContainsString('"content":"Hi"', $buffer);
        $this->assertStringContainsString('"finish_reason":"stop"', $buffer);
        $this->assertStringContainsString('data: [DONE]', $buffer);
    }

    public function testRunStreamWithConfigSseCallsRunStream(): void
    {
        $result = AgentResult::complete('ok', 1, 50, 10, 10);
        $buffer = '';
        $write = function (string $line) use (&$buffer): void {
            $buffer .= $line;
        };

        $this->runner->expects($this->once())
            ->method('runStream')
            ->willReturnCallback(function (array $messages, array $config, array $options, ?callable $onEvent) use ($result) {
                $onEvent('started', ['agent' => 'Test']);
                $onEvent('text_delta', ['content' => 'ok']);
                $onEvent('complete', ['iterations' => 1, 'elapsed_ms' => 50, 'prompt_tokens' => 10, 'completion_tokens' => 10]);
                return $result;
            });

        $returned = $this->agentic->runStreamWithConfigSse(
            ['persona' => 'You are a test assistant.', 'max_iterations' => 5],
            [['role' => 'user', 'content' => 'hi']],
            $write,
        );

        $this->assertSame('ok', $returned->content);
        $this->assertStringContainsString('data: [DONE]', $buffer);
    }

    public function testRunStreamWithConfigCallsRunnerRunStream(): void
    {
        $result = AgentResult::complete('response', 1, 50, 10, 10);
        $onEvent = fn() => null;

        $this->runner->expects($this->once())
            ->method('runStream')
            ->with(
                [['role' => 'user', 'content' => 'hello']],
                $this->anything(),
                [],
                $onEvent,
            )
            ->willReturn($result);

        $returned = $this->agentic->runStreamWithConfig(
            ['max_iterations' => 5],
            [['role' => 'user', 'content' => 'hello']],
            $onEvent,
        );

        $this->assertSame('response', $returned->content);
    }
}
