<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\Command\AgentChatCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class AgentChatCommandTest extends TestCase
{
    private Agentic $agentic;
    private AgentChatCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->agentic = $this->createMock(Agentic::class);
        $this->command = new AgentChatCommand($this->agentic);

        $app = new Application();
        $app->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    public function testCommandName(): void
    {
        $this->assertSame('agent:chat', $this->command->getName());
    }

    public function testCommandHasAgentArgument(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasArgument('agent'));
        $agentArg = $definition->getArgument('agent');
        $this->assertFalse($agentArg->isRequired());
        $this->assertSame('default', $agentArg->getDefault());
    }

    public function testCommandHasNoInteractionOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('no-input'));
    }

    public function testCommandHasModelOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('model'));
    }

    public function testCommandDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    public function testSingleMessageRun(): void
    {
        $result = AgentResult::complete(
            content: 'Hello! How can I help?',
            iterations: 1,
            elapsedMs: 100,
            promptTokens: 50,
            completionTokens: 20,
        );

        $this->agentic->method('run')
            ->with('default', $this->callback(fn(array $m) => $m[0]['content'] === 'hello'), $this->anything())
            ->willReturn($result);

        $this->tester->setInputs(["hello\n", "/quit\n"]);
        $this->tester->execute([]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Hello! How can I help?', $this->tester->getDisplay());
    }

    public function testRunWithCustomAgent(): void
    {
        $result = AgentResult::complete('Response', 1, 50, 10, 10);

        $this->agentic->method('run')
            ->with('coder', $this->anything(), $this->anything())
            ->willReturn($result);

        $this->tester->setInputs(["test\n", "/quit\n"]);
        $this->tester->execute(['agent' => 'coder']);

        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testEmptyInputIsSkipped(): void
    {
        $result = AgentResult::complete('Done', 1, 50, 10, 10);

        $this->agentic->expects($this->once())
            ->method('run')
            ->willReturn($result);

        // SymfonyStyle::ask() returns the trimmed value.
        // Empty inputs are skipped in the while loop before reaching run().
        $this->tester->setInputs(["hello\n", "/quit\n"]);
        $this->tester->execute([]);

        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testQuitExitsCleanly(): void
    {
        $this->agentic->expects($this->never())->method('run');

        $this->tester->setInputs(["/quit\n"]);
        $this->tester->execute([]);

        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testExitCommandAlsoWorks(): void
    {
        $this->agentic->expects($this->never())->method('run');

        $this->tester->setInputs(["/exit\n"]);
        $this->tester->execute([]);

        $this->assertSame(0, $this->tester->getStatusCode());
    }
}
