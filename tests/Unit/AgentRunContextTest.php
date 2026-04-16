<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentRunContext;
use ChenZhanjie\Agentic\CancellationToken;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\ToolGuardrailRunner;

class AgentRunContextTest extends TestCase
{
    private ConfigToolPermissionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ConfigToolPermissionPolicy();
    }

    // ── construction ──

    public function testConstructsWithRequiredParameters(): void
    {
        $guardrails = new GuardrailRunner();
        $toolGuardrails = new ToolGuardrailRunner();
        $context = new AgentRunContext(guardrails: $guardrails, toolGuardrails: $toolGuardrails, permissionPolicy: $this->policy);

        $this->assertSame($guardrails, $context->guardrails);
        $this->assertSame($toolGuardrails, $context->toolGuardrails);
        $this->assertSame($this->policy, $context->permissionPolicy);
        $this->assertNull($context->humanInputResolver);
        $this->assertSame([], $context->agentToolHandlers);
        $this->assertNull($context->cancellationToken);
    }

    public function testConstructsWithAllParameters(): void
    {
        $guardrails = new GuardrailRunner();
        $toolGuardrails = new ToolGuardrailRunner();
        $token = new CancellationToken();
        $handlers = ['search' => fn () => 'result'];

        $context = new AgentRunContext(
            guardrails: $guardrails,
            toolGuardrails: $toolGuardrails,
            permissionPolicy: $this->policy,
            humanInputResolver: null,
            agentToolHandlers: $handlers,
            cancellationToken: $token,
        );

        $this->assertSame($guardrails, $context->guardrails);
        $this->assertSame($toolGuardrails, $context->toolGuardrails);
        $this->assertSame($this->policy, $context->permissionPolicy);
        $this->assertNull($context->humanInputResolver);
        $this->assertSame($handlers, $context->agentToolHandlers);
        $this->assertSame($token, $context->cancellationToken);
    }

    // ── isCancelled ──

    public function testIsCancelledReturnsFalseWhenNoToken(): void
    {
        $context = new AgentRunContext(guardrails: new GuardrailRunner(), toolGuardrails: new ToolGuardrailRunner(), permissionPolicy: $this->policy);
        $this->assertFalse($context->isCancelled());
    }

    public function testIsCancelledReturnsFalseWhenTokenNotCancelled(): void
    {
        $token = new CancellationToken();
        $context = new AgentRunContext(
            guardrails: new GuardrailRunner(),
            toolGuardrails: new ToolGuardrailRunner(),
            permissionPolicy: $this->policy,
            cancellationToken: $token,
        );

        $this->assertFalse($context->isCancelled());
    }

    public function testIsCancelledReturnsTrueWhenTokenCancelled(): void
    {
        $token = new CancellationToken();
        $token->cancel('timeout');

        $context = new AgentRunContext(
            guardrails: new GuardrailRunner(),
            toolGuardrails: new ToolGuardrailRunner(),
            permissionPolicy: $this->policy,
            cancellationToken: $token,
        );

        $this->assertTrue($context->isCancelled());
    }

    // ── immutability ──

    public function testPropertiesAreReadonly(): void
    {
        $context = new AgentRunContext(guardrails: new GuardrailRunner(), toolGuardrails: new ToolGuardrailRunner(), permissionPolicy: $this->policy);
        $reflection = new \ReflectionClass($context);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property \${$property->getName()} should be readonly",
            );
        }
    }

    // ── agentToolHandlers ──

    public function testAgentToolHandlersDefaultEmpty(): void
    {
        $context = new AgentRunContext(guardrails: new GuardrailRunner(), toolGuardrails: new ToolGuardrailRunner(), permissionPolicy: $this->policy);
        $this->assertSame([], $context->agentToolHandlers);
    }

    public function testAgentToolHandlersPreserved(): void
    {
        $handler = fn (array $args): string => 'result';
        $context = new AgentRunContext(
            guardrails: new GuardrailRunner(),
            toolGuardrails: new ToolGuardrailRunner(),
            permissionPolicy: $this->policy,
            agentToolHandlers: ['my_tool' => $handler],
        );

        $this->assertArrayHasKey('my_tool', $context->agentToolHandlers);
        $this->assertSame('result', ($context->agentToolHandlers['my_tool'])([]));
    }

    // ── guardrails per-request isolation ──

    public function testDifferentContextsUseDifferentGuardrails(): void
    {
        $runner1 = new GuardrailRunner();
        $runner2 = new GuardrailRunner();

        $context1 = new AgentRunContext(guardrails: $runner1, toolGuardrails: new ToolGuardrailRunner(), permissionPolicy: $this->policy);
        $context2 = new AgentRunContext(guardrails: $runner2, toolGuardrails: new ToolGuardrailRunner(), permissionPolicy: $this->policy);

        $this->assertNotSame($context1->guardrails, $context2->guardrails);
    }
}
