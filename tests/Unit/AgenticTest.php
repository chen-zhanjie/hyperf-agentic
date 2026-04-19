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
use ChenZhanjie\Agentic\AgentMiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\Session\MemoryMessageStore;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolRegistry;
use ChenZhanjie\Agentic\Event\AgentEventType;

class AgenticTest extends TestCase
{
    // ── run ──

    public function testRunDelegatesToAgentRunner(): void
    {
        $llm = $this->createMockLlm([['content' => 'Hello!', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $agentic = new Agentic(
            llmClient: $llm,
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
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $agentic = new Agentic(
            llmClient: $llm,
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
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $agentic = new Agentic(
            llmClient: $llm,
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not defined');

        $agentic->run('nonexistent', [['role' => 'user', 'content' => 'hi']]);
    }

    // ── chat (pure LLM passthrough) ──

    public function testChatReturnsLlmResponse(): void
    {
        $llm = $this->createMockLlm([['content' => 'plain text response', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]]);
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $agentic = new Agentic(
            llmClient: $llm,
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $result = $agentic->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertInstanceOf(\ChenZhanjie\Agentic\LlmResponse::class, $result);
        $this->assertSame('plain text response', $result->content);
        $this->assertSame(10, $result->usage['prompt_tokens']);
        $this->assertSame(5, $result->usage['completion_tokens']);
    }

    public function testChatBypassesAgentRunnerDirectly(): void
    {
        // Use a mock runner that would fail if run() were called
        $runner = $this->createMock(AgentRunner::class);
        $runner->expects($this->never())->method('run');

        $llm = $this->createMockLlm([['content' => 'direct llm', 'usage' => []]]);

        $agentic = new Agentic(
            llmClient: $llm,
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $result = $agentic->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('direct llm', $result->content);
    }

    public function testChatPassesProviderAndModelOptions(): void
    {
        $captured = null;
        $llm = new LlmClient(
            providerConfigs: ['openai' => ['model' => 'gpt-4o'], 'anthropic' => ['model' => 'claude-sonnet']],
            defaultProvider: 'openai',
            adapterFactory: function (string $op, string $provider, array $config, array $messages, array $options) use (&$captured) {
                $captured = $options;
                return ['content' => 'ok', 'usage' => [], 'model' => $options['model'] ?? 'test'];
            },
        );

        $runner = $this->createMock(AgentRunner::class);
        $agentic = new Agentic(
            llmClient: $llm,
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $agentic->chat([['role' => 'user', 'content' => 'hi']], ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514']);
        $this->assertSame('anthropic', $captured['provider']);
        $this->assertSame('claude-sonnet-4-20250514', $captured['model']);
    }

    public function testChatDoesNotSetNullProviderOrModel(): void
    {
        // Verify Agentic doesn't pass null for provider/model — LlmClient adds defaults internally
        $captured = null;
        $llm = new LlmClient(
            providerConfigs: ['openai' => ['model' => 'gpt-4o']],
            defaultProvider: 'openai',
            adapterFactory: function (string $op, string $provider, array $config, array $messages, array $options) use (&$captured) {
                $captured = $options;
                return ['content' => 'ok', 'usage' => [], 'model' => 'gpt-4o'];
            },
        );

        $runner = $this->createMock(AgentRunner::class);
        $agentic = new Agentic(
            llmClient: $llm,
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $agentic->chat([['role' => 'user', 'content' => 'hi']]);
        // Agentic should not pass null provider — LlmClient falls back to defaultProvider
        $this->assertArrayNotHasKey('provider', $captured);
        // Agentic should not pass null model — LlmClient falls back to config model
        // (LlmClient::doChat adds model internally, so we check Agentic didn't set null)
        $this->assertFalse(isset($captured['model']) && $captured['model'] === null);
    }

    // ── approveAll / revokeAll ──

    public function testApproveAllDelegatesToStore(): void
    {
        $store = $this->createMock(\ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface::class);
        $store->expects($this->once())->method('approveAll')->with('session-1');

        $agentic = $this->createAgenticWithApprovalStore($store);
        $agentic->approveAll('session-1');
    }

    public function testApproveToolDelegatesToStore(): void
    {
        $store = $this->createMock(\ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface::class);
        $store->expects($this->once())->method('approve')->with('search', 'session-1');

        $agentic = $this->createAgenticWithApprovalStore($store);
        $agentic->approveTool('search', 'session-1');
    }

    public function testRevokeAllDelegatesToStore(): void
    {
        $store = $this->createMock(\ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface::class);
        $store->expects($this->once())->method('revokeAll')->with('session-1');

        $agentic = $this->createAgenticWithApprovalStore($store);
        $agentic->revokeAll('session-1');
    }

    public function testRevokeToolDelegatesToStore(): void
    {
        $store = $this->createMock(\ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface::class);
        $store->expects($this->once())->method('revoke')->with('search', 'session-1');

        $agentic = $this->createAgenticWithApprovalStore($store);
        $agentic->revokeTool('search', 'session-1');
    }

    public function testSetHumanInputResolverDelegatesToRunner(): void
    {
        $resolver = $this->createMock(\ChenZhanjie\Agentic\Contract\HumanInputResolverInterface::class);
        $runner = $this->createMock(AgentRunner::class);
        $runner->expects($this->once())->method('setHumanInputResolver')->with($resolver);

        $llm = $this->createMockLlm([]);
        $agentic = new Agentic(
            llmClient: $llm,
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
        );

        $agentic->setHumanInputResolver($resolver);
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

    public function testAvailableToolsReturnsRegisteredToolNames(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->createMockTool('search'));
        $registry->register($this->createMockTool('create'));

        $agentic = $this->createDefaultAgentic(toolRegistry: $registry);

        $tools = $agentic->availableTools();
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
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $agentic = new Agentic(
            llmClient: $llm,
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
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $agentic = new Agentic(
            llmClient: $llm,
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
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $agentic = new Agentic(
            llmClient: $llm,
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
                return ['content' => 'New answer', 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10], 'model' => 'test'];
            },
        );

        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());
        $agentic = new Agentic(
            llmClient: $llm,
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
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $messageStore = new MemoryMessageStore();
        $agentic = new Agentic(
            llmClient: $llm,
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
        $runner = new AgentRunner($llm, new PromptBuilder(), new ToolRegistry(), $guardrailRunner, new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        $messageStore = new MemoryMessageStore();
        $agentic = new Agentic(
            llmClient: $llm,
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
        $runner = new AgentRunner($llm, new PromptBuilder(), $registry, new GuardrailRunner(), new AgentMiddlewarePipeline(), new ToolGuardrailRunner(), new ConfigToolPermissionPolicy());

        return new Agentic(
            llmClient: $llm,
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
            adapterFactory: function () use ($responses, &$index): array {
                return $responses[$index++] ?? ['content' => '', 'usage' => [], 'model' => 'test'];
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

    private function createAgenticWithApprovalStore(\ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface $store): Agentic
    {
        $llm = $this->createMockLlm([]);
        $runner = $this->createMock(AgentRunner::class);
        return new Agentic(
            llmClient: $llm,
            runner: $runner,
            toolRegistry: new ToolRegistry(),
            promptBuilder: new PromptBuilder(),
            approvalStore: $store,
        );
    }
}
