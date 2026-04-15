<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\GuardrailResult;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\Session\MemoryMessageStore;
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
                    'persona' => new Persona(name: 'General', content: 'You are a helpful assistant.'),
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
                    'persona' => new Persona(name: 'TestBot', content: 'You are a test bot.'),
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
        $this->expectExceptionMessage('not defined');

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
        $persona = new Persona(name: 'ChatBot', content: 'You are a chat bot.');
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

    // ── runWithConfig ──

    public function testRunWithConfigBypassesAgentNameLookup(): void
    {
        $llm = $this->createMockLlm([['content' => 'Dynamic response', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        // No agents defined, but runWithConfig still works
        $result = $agentic->runWithConfig(
            ['persona' => new Persona(name: 'Dynamic', content: 'You are dynamic.')],
            [['role' => 'user', 'content' => 'hi']],
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('Dynamic response', $result->content);
    }

    public function testRunWithConfigMergesDefaults(): void
    {
        $llm = $this->createMockLlm([['content' => 'ok', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
            defaults: ['max_iterations' => 5, 'scene' => 'http'],
        );

        $result = $agentic->runWithConfig(
            ['persona' => new Persona(name: 'Bot', content: 'You are a bot.')],
            [['role' => 'user', 'content' => 'hi']],
        );

        $this->assertTrue($result->isComplete());
    }

    public function testRunStreamWithConfigWorks(): void
    {
        $llm = $this->createMockLlm([['content' => 'streamed', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $result = $agentic->runStreamWithConfig(
            ['persona' => new Persona(name: 'Bot', content: 'You are a bot.')],
            [['role' => 'user', 'content' => 'hi']],
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('streamed', $result->content);
    }

    // ── conversation_id support ──

    public function testRunWithConfigLoadsHistoryAndAppendsWithConversationId(): void
    {
        $messageStore = new MemoryMessageStore();

        // Pre-seed history
        $messageStore->append('conv-123', [
            ['role' => 'user', 'content' => 'Previous question'],
            ['role' => 'assistant', 'content' => 'Previous answer'],
        ]);

        $callCount = 0;
        $capturedMessages = null;
        $llm = new LlmClient(
            providerConfigs: ['test' => ['model' => 'test']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options) use (&$callCount, &$capturedMessages) {
                ++$callCount;
                $capturedMessages = $messages;
                return ['content' => 'New answer', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]];
            },
        );

        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());
        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
            messageStore: $messageStore,
        );

        $result = $agentic->runWithConfig(
            ['persona' => new Persona(name: 'Bot', content: 'You are a bot.')],
            [['role' => 'user', 'content' => 'New question']],
            ['conversation_id' => 'conv-123'],
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame('New answer', $result->content);

        // Verify history was loaded — messages sent to LLM should include prior messages
        $this->assertNotNull($capturedMessages);
        // System message + 2 history + 1 new = 4 messages
        $this->assertCount(4, $capturedMessages);
        $this->assertSame('Previous question', $capturedMessages[1]['content']);
        $this->assertSame('Previous answer', $capturedMessages[2]['content']);
        $this->assertSame('New question', $capturedMessages[3]['content']);

        // Verify messages were appended to store
        $stored = $messageStore->load('conv-123');
        // Original 2 + new user + assistant response = 4
        $this->assertCount(4, $stored);
    }

    public function testRunWithConfigWithoutConversationIdIsStateless(): void
    {
        $llm = $this->createMockLlm([['content' => 'Response', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new MiddlewarePipeline());

        $messageStore = new MemoryMessageStore();
        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
            messageStore: $messageStore,
        );

        $result = $agentic->runWithConfig(
            ['persona' => new Persona(name: 'Bot', content: 'You are a bot.')],
            [['role' => 'user', 'content' => 'hi']],
        );

        $this->assertTrue($result->isComplete());
        // No messages stored — stateless mode
        $this->assertSame(0, count(array_filter($messageStore->load('any'))));
    }

    public function testGuardrailBlockedResultDoesNotPersistMessages(): void
    {
        $guardrailRunner = new GuardrailRunner();
        $guardrailRunner->register(new class implements GuardrailInterface {
            public function name(): string { return 'block_all'; }
            public function checkInput(array $messages): GuardrailResult
            {
                return GuardrailResult::blocked('Blocked');
            }
            public function checkOutput(string $content): GuardrailResult
            {
                return GuardrailResult::ok();
            }
        });

        $llm = $this->createMockLlm(['should not be called']);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), $guardrailRunner, new MiddlewarePipeline());

        $messageStore = new MemoryMessageStore();
        $agentic = new Agentic(
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
            messageStore: $messageStore,
        );

        $result = $agentic->runWithConfig(
            ['persona' => new Persona(name: 'Bot', content: 'You are a bot.')],
            [['role' => 'user', 'content' => 'blocked input']],
            ['conversation_id' => 'conv-blocked'],
        );

        $this->assertFalse($result->isComplete());
        // No messages should be persisted for a blocked result
        $this->assertSame([], $messageStore->load('conv-blocked'));
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
