<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\AgentRunner;

use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\ApprovalChoice;
use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\PermissionApprovalStore;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\Tests\Unit\Concerns\AgentRunnerTestHelpers;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolPermissionDecision;
use ChenZhanjie\Agentic\ToolRegistry;
use PHPUnit\Framework\TestCase;

class PermissionRunnerTest extends TestCase
{
    use AgentRunnerTestHelpers;

    public function testAutoApprovedToolEmitsEvent(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $approvalStore = new PermissionApprovalStore();
        $approvalStore->approve('search', 'conv-123');

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'search', 'arguments' => '{"q":"test"}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Search completed', 'usage' => $this->usage(120, 20)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = ['type' => $type, 'payload' => $payload];
        };

        $runner = $this->createRunner($llm, $registry, approvalStore: $approvalStore);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search']],
            $this->defaultConfig(),
            ['conversation_id' => 'conv-123'],
            $onEvent,
        );

        $this->assertTrue($result->isComplete());
        $eventTypes = array_column($events, 'type');
        $this->assertContains('tool_auto_approved', $eventTypes);

        $autoApprovedEvents = array_filter($events, fn ($e) => $e['type'] === 'tool_auto_approved');
        $this->assertSame('search', reset($autoApprovedEvents)['payload']['name']);
    }

    public function testAskChoiceOnceExecutesToolWithoutRecording(): void
    {
        $tool = $this->createMockTool('delete_db', 'Delete DB', '{"deleted":true}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $approvalStore = new PermissionApprovalStore();
        $resolver = $this->createMock(HumanInputResolverInterface::class);
        $resolver->method('isBlocking')->willReturn(true);
        $resolver->method('ask')->willReturn([
            'confirmed' => true,
            'values' => ['choice' => ApprovalChoice::ONCE->value],
        ]);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'delete_db', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Done deleting', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry, approvalStore: $approvalStore);
        $runner->setHumanInputResolver($resolver);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'delete']],
            array_merge($this->defaultConfig(), [
                'tool_permissions' => ['ask' => ['delete_db']],
            ]),
            ['conversation_id' => 'conv-once'],
        );

        $this->assertTrue($result->isComplete());
        $this->assertFalse($approvalStore->isApproved('delete_db', 'conv-once'));
    }

    public function testAskChoiceToolRecordsApprovalForSession(): void
    {
        $tool = $this->createMockTool('delete_db', 'Delete DB', '{"deleted":true}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $approvalStore = new PermissionApprovalStore();
        $resolver = $this->createMock(HumanInputResolverInterface::class);
        $resolver->method('isBlocking')->willReturn(true);
        $resolver->method('ask')->willReturn([
            'confirmed' => true,
            'values' => ['choice' => ApprovalChoice::TOOL->value],
        ]);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'delete_db', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Done', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry, approvalStore: $approvalStore);
        $runner->setHumanInputResolver($resolver);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'delete']],
            array_merge($this->defaultConfig(), [
                'tool_permissions' => ['ask' => ['delete_db']],
            ]),
            ['conversation_id' => 'conv-tool'],
        );

        $this->assertTrue($result->isComplete());
        $this->assertFalse($approvalStore->isApproved('delete_db', 'conv-tool'));
    }

    public function testAskChoiceSessionRecordsApproveAllForSession(): void
    {
        $tool = $this->createMockTool('delete_db', 'Delete DB', '{"deleted":true}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $approvalStore = new PermissionApprovalStore();
        $resolver = $this->createMock(HumanInputResolverInterface::class);
        $resolver->method('isBlocking')->willReturn(true);
        $resolver->method('ask')->willReturn([
            'confirmed' => true,
            'values' => ['choice' => ApprovalChoice::SESSION->value],
        ]);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'delete_db', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Done', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry, approvalStore: $approvalStore);
        $runner->setHumanInputResolver($resolver);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'delete']],
            array_merge($this->defaultConfig(), [
                'tool_permissions' => ['ask' => ['delete_db']],
            ]),
            ['conversation_id' => 'conv-session'],
        );

        $this->assertTrue($result->isComplete());
        $this->assertFalse($approvalStore->isApproved('delete_db', 'conv-session'));
    }

    public function testAskChoiceDenyReturnsDenialMessage(): void
    {
        $tool = $this->createMockTool('delete_db', 'Delete DB', '{"deleted":true}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $resolver = $this->createMock(HumanInputResolverInterface::class);
        $resolver->method('isBlocking')->willReturn(true);
        $resolver->method('ask')->willReturn([
            'confirmed' => false,
            'values' => ['choice' => ApprovalChoice::DENY->value],
        ]);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'delete_db', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Understood, not deleting', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry);
        $runner->setHumanInputResolver($resolver);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'delete']],
            array_merge($this->defaultConfig(), [
                'tool_permissions' => ['ask' => ['delete_db']],
            ]),
        );

        $this->assertTrue($result->isComplete());
    }

    public function testAskWithoutBlockingResolverAutoDenies(): void
    {
        $tool = $this->createMockTool('delete_db', 'Delete DB', '{"deleted":true}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $resolver = $this->createMock(HumanInputResolverInterface::class);
        $resolver->method('isBlocking')->willReturn(false);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'delete_db', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Cannot delete', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry);
        $runner->setHumanInputResolver($resolver);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'delete']],
            array_merge($this->defaultConfig(), [
                'tool_permissions' => ['ask' => ['delete_db']],
            ]),
        );

        $this->assertTrue($result->isComplete());
    }

    public function testAutoApproveConfigPrePopulatesStore(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $approvalStore = new PermissionApprovalStore();

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'search', 'arguments' => '{"q":"test"}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Search done', 'usage' => $this->usage(120, 20)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner = $this->createRunner($llm, $registry, approvalStore: $approvalStore);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'search']],
            array_merge($this->defaultConfig(), [
                'tool_permissions' => [
                    'ask' => ['search'],
                    'auto_approve' => ['search'],
                ],
            ]),
            ['conversation_id' => 'conv-auto'],
            $onEvent,
        );

        $this->assertTrue($result->isComplete());
        $this->assertContains('tool_auto_approved', $events);
    }

    public function testAutoApproveTrueApprovesAllForSession(): void
    {
        $tool = $this->createMockTool('delete_all', 'Delete All', '{"deleted":true}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $approvalStore = new PermissionApprovalStore();

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'delete_all', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Done', 'usage' => $this->usage(120, 20)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner = $this->createRunner($llm, $registry, approvalStore: $approvalStore);
        $result = $runner->run(
            [['role' => 'user', 'content' => 'nuke']],
            array_merge($this->defaultConfig(), [
                'auto_approve' => true,
            ]),
            ['conversation_id' => 'conv-all'],
            $onEvent,
        );

        $this->assertTrue($result->isComplete());
        $this->assertContains('tool_auto_approved', $events);
    }

    public function testPermissionModeAutoBypassesAllAsks(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'search', 'arguments' => '{"q":"test"}'],
                ]],
                'usage' => $this->usage(100, 30),
            ],
            ['content' => 'Done', 'usage' => $this->usage(120, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry);

        $result = $runner->run(
            [['role' => 'user', 'content' => 'search']],
            array_merge($this->defaultConfig(), [
                'permission_mode' => 'auto',
            ]),
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame(1, $result->toolCalls);
    }

    public function testPerRequestStoreCloneIsolation(): void
    {
        $tool = $this->createMockTool('search', 'Search', '{"result":"data"}');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $approvalStore = new PermissionApprovalStore();

        $llm = $this->createMockLlm([
            ['content' => 'done', 'usage' => $this->usage(50, 20)],
        ]);

        $runner = $this->createRunner($llm, $registry, approvalStore: $approvalStore);

        $runner->run(
            [['role' => 'user', 'content' => 'hi']],
            array_merge($this->defaultConfig(), [
                'auto_approve' => true,
            ]),
            ['conversation_id' => 'conv-1'],
        );

        $this->assertFalse($approvalStore->isApproved('any_tool', 'conv-1'));
    }

    public function testToolDeniedEventEmittedWhenPermissionPolicyDenies(): void
    {
        $denyAllPolicy = new class implements ToolPermissionPolicyInterface {
            public function decide(string $toolName, \ChenZhanjie\Agentic\ToolRiskLevel $riskLevel, array $arguments): \ChenZhanjie\Agentic\ToolPermissionDecision
            {
                return \ChenZhanjie\Agentic\ToolPermissionDecision::DENY;
            }
        };

        $llm = $this->createMockLlm([
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'search', 'arguments' => '{}'],
                ]],
                'usage' => $this->usage(50, 20),
            ],
            ['content' => 'Done', 'usage' => $this->usage(50, 20)],
        ]);

        $events = [];
        $onEvent = function (string $type, array $payload) use (&$events): void {
            $events[] = $type;
        };

        $runner = new AgentRunner(
            llmClient: $llm,
            promptBuilder: new PromptBuilder(),
            toolRegistry: new ToolRegistry(),
            guardrailRunner: new GuardrailRunner(),
            middleware: new MiddlewarePipeline(),
            toolGuardrailRunner: new ToolGuardrailRunner(),
            permissionPolicy: $denyAllPolicy,
        );

        $result = $runner->run(
            [['role' => 'user', 'content' => 'search']],
            $this->defaultConfig(),
            [],
            $onEvent,
        );

        $this->assertContains('tool_denied', $events, 'TOOL_DENIED event should be emitted when permission policy denies');
    }
}
