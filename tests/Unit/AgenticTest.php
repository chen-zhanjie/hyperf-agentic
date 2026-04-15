<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\Event\AgentEventType;

class AgenticTest extends TestCase
{
    // ── run ──

    public function testRunDelegatesToAgentRunner(): void
    {
        $llm = $this->createMockLlm([['content' => 'Hello!', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
            agentDefs: [
                'general' => [
                    'persona' => new Persona(name: 'General', role: 'Assistant', goal: 'Help', backstory: ''),
                    'max_iterations' => 5,
                ],
            ],
        );

        $result = $agentic->run('general', [['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($result->isComplete());
        $this->assertSame('Hello!', $result->content);
    }

    public function testRunPassesAgentConfig(): void
    {
        $llm = $this->createMockLlm([['content' => 'response', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
            agentDefs: [
                'test-agent' => [
                    'persona' => new Persona(name: 'TestBot', role: 'Tester', goal: 'Test', backstory: ''),
                    'max_iterations' => 5,
                    'system_prompt' => 'You are a test bot',
                ],
            ],
        );

        $result = $agentic->run('test-agent', [['role' => 'user', 'content' => 'test']]);

        $this->assertTrue($result->isComplete());
    }

    public function testRunThrowsForUndefinedAgent(): void
    {
        $llm = $this->createMockLlm([]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未定义');

        $agentic->run('nonexistent', [['role' => 'user', 'content' => 'hi']]);
    }

    // ── chat (pure LLM passthrough) ──

    public function testChatReturnsStringResponse(): void
    {
        $llm = $this->createMockLlm(['plain text response']);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $result = $agentic->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('plain text response', $result);
    }

    // ── agents list ──

    public function testAgentsReturnsDefinedNames(): void
    {
        $agentic = $this->createDefaultAgentic(agentDefs: [
            'chat' => ['persona' => null],
            'general' => ['persona' => null],
        ]);

        $names = $agentic->agents();
        $this->assertContains('chat', $names);
        $this->assertContains('general', $names);
    }

    public function testAgentsReturnsEmptyWhenNoneDefined(): void
    {
        $agentic = $this->createDefaultAgentic();
        $this->assertSame([], $agentic->agents());
    }

    // ── tools ──

    public function testToolsReturnsRegisteredToolNames(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->createMockTool('search'));
        $registry->register($this->createMockTool('create'));

        $agentic = $this->createDefaultAgentic(toolRegistry: $registry);

        $tools = $agentic->tools();
        $this->assertContains('search', $tools);
        $this->assertContains('create', $tools);
    }

    public function testAvailableToolsFiltersDisabled(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->createMockTool('enabled'));
        $registry->register($this->createMockTool('disabled', enabled: false));

        $agentic = $this->createDefaultAgentic(toolRegistry: $registry);

        $available = $agentic->availableTools();
        $this->assertContains('enabled', $available);
        $this->assertNotContains('disabled', $available);
    }

    // ── persona ──

    public function testPersonaReturnsAgentPersona(): void
    {
        $persona = new Persona(name: 'ChatBot', role: 'Chat', goal: 'Chat well', backstory: 'Test');
        $agentic = $this->createDefaultAgentic(agentDefs: [
            'chat' => ['persona' => $persona],
        ]);

        $result = $agentic->persona('chat');
        $this->assertSame($persona, $result);
    }

    public function testPersonaReturnsNullForUndefinedAgent(): void
    {
        $agentic = $this->createDefaultAgentic();
        $this->assertNull($agentic->persona('nonexistent'));
    }

    public function testPersonaReturnsNullWhenNoPersonaConfigured(): void
    {
        $agentic = $this->createDefaultAgentic(agentDefs: [
            'bare' => ['max_iterations' => 5],
        ]);
        $this->assertNull($agentic->persona('bare'));
    }

    // ── has ──

    public function testHasReturnsTrueForDefinedAgent(): void
    {
        $agentic = $this->createDefaultAgentic(agentDefs: [
            'chat' => ['persona' => null],
        ]);
        $this->assertTrue($agentic->has('chat'));
        $this->assertFalse($agentic->has('other'));
    }

    // ── helpers ──

    private function createDefaultAgentic(
        ?ToolRegistry $toolRegistry = null,
        array $agentDefs = [],
    ): Agentic {
        $registry = $toolRegistry ?? new ToolRegistry();
        $llm = $this->createMockLlm([]);
        $runner = new AgentRunner($llm, new PromptBuilder(), $registry, new GuardrailRunner(), new MiddlewarePipeline());

        return new Agentic(
            runner: $runner,
            toolRegistry: $registry,
            promptBuilder: new PromptBuilder(),
            agentDefs: $agentDefs,
        );
    }

    private function createMockLlm(array $responses): LlmClient
    {
        $index = 0;
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test']],
            defaultProvider: 'test',
            adapterFactory: function () use ($responses, &$index): string|array {
                return $responses[$index++] ?? ['content' => '', 'usage' => []];
            },
        );
    }

    private function createMockTool(string $name, bool $enabled = true): ToolInterface
    {
        return new class($name, $enabled) implements ToolInterface {
            public function __construct(
                private readonly string $toolName,
                private readonly bool $toolEnabled,
            ) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return "Tool {$this->toolName}"; }
            public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string { return 'ok'; }
            public function isEnabled(): bool { return $this->toolEnabled; }
            public function isParallelAllowed(): bool { return true; }
        };
    }
}
