<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\Agent;
use ChenZhanjie\Agentic\PermissionMode;
use ChenZhanjie\Agentic\Persona\Persona;
use PHPUnit\Framework\TestCase;

class AgentDtoTest extends TestCase
{
    public function testDefaultConstruction(): void
    {
        $agent = new Agent();

        $this->assertSame('Assistant', $agent->name);
        $this->assertSame([], $agent->tools);
        $this->assertSame(15, $agent->maxIterations);
        $this->assertSame(PermissionMode::DEFAULT, $agent->permissionMode);
        $this->assertNull($agent->persona);
        $this->assertNull($agent->model);
        $this->assertNull($agent->provider);
        $this->assertSame('http', $agent->scene);
    }

    public function testCustomConstruction(): void
    {
        $persona = new Persona(name: 'Expert', content: 'You are an expert.');
        $agent = new Agent(
            name: 'Expert',
            persona: $persona,
            tools: ['search', 'ask'],
            maxIterations: 10,
            model: 'gpt-4o',
        );

        $this->assertSame('Expert', $agent->name);
        $this->assertSame($persona, $agent->persona);
        $this->assertSame(['search', 'ask'], $agent->tools);
        $this->assertSame(10, $agent->maxIterations);
        $this->assertSame('gpt-4o', $agent->model);
    }

    public function testFromArrayWithFullConfig(): void
    {
        $persona = new Persona(name: 'Bot', content: 'You are a bot.');
        $agent = Agent::fromArray([
            'persona' => $persona,
            'tools' => ['search'],
            'skills' => ['guide'],
            'guardrails' => ['content_filter'],
            'guardrail_modes' => ['content_filter' => 'async'],
            'tool_permissions' => [
                'allow' => ['search_*'],
                'deny' => ['exec_*'],
                'auto_approve' => true,
            ],
            'permission_mode' => 'auto',
            'max_iterations' => 20,
            'max_cost_tokens' => 100000,
            'system_prompt' => 'Extra rules',
            'model' => 'gpt-4o',
            'provider' => 'openai',
            'scene' => 'cli',
            'async_guardrail_timeout' => 3000,
            'cancellation_timeout_ms' => 60000,
        ]);

        $this->assertSame('Bot', $agent->name);
        $this->assertSame($persona, $agent->persona);
        $this->assertSame(['search'], $agent->tools);
        $this->assertSame(['guide'], $agent->skills);
        $this->assertSame(['content_filter'], $agent->guardrails);
        $this->assertSame(['content_filter' => 'async'], $agent->guardrailModes);
        $this->assertSame(PermissionMode::AUTO, $agent->permissionMode);
        $this->assertSame(20, $agent->maxIterations);
        $this->assertSame(100000, $agent->maxCostTokens);
        $this->assertSame('Extra rules', $agent->systemPrompt);
        $this->assertSame('gpt-4o', $agent->model);
        $this->assertSame('openai', $agent->provider);
        $this->assertSame('cli', $agent->scene);
        $this->assertSame(3000, $agent->asyncGuardrailTimeout);
        $this->assertSame(60000, $agent->cancellationTimeoutMs);
        $this->assertTrue($agent->autoApprove);
    }

    public function testFromArrayWithMinimalConfig(): void
    {
        $agent = Agent::fromArray(['tools' => ['search']]);

        $this->assertSame('Assistant', $agent->name);
        $this->assertSame(['search'], $agent->tools);
        $this->assertSame(15, $agent->maxIterations);
        $this->assertSame(PermissionMode::DEFAULT, $agent->permissionMode);
    }

    public function testFromArrayWithStringPersona(): void
    {
        $agent = Agent::fromArray([
            'persona' => 'You are a helpful assistant.',
        ]);

        $this->assertSame('Assistant', $agent->name);
        // persona is stored as-is (string); resolution happens in AgentRunner
        $this->assertSame('You are a helpful assistant.', $agent->persona);
    }

    public function testFromArrayWithArrayPersonaDerivesName(): void
    {
        $agent = Agent::fromArray([
            'persona' => ['name' => 'Bot', 'content' => 'You are a bot.'],
        ]);

        $this->assertSame('Bot', $agent->name);
    }

    public function testFromArrayWithPersonaObjectDerivesName(): void
    {
        $persona = new Persona(name: 'Expert', content: 'You are an expert.');
        $agent = Agent::fromArray([
            'persona' => $persona,
        ]);

        $this->assertSame('Expert', $agent->name);
    }

    public function testToArrayRoundTrip(): void
    {
        $agent = new Agent(
            name: 'Expert',
            tools: ['search'],
            maxIterations: 10,
            model: 'gpt-4o',
            permissionMode: PermissionMode::AUTO,
        );

        $array = $agent->toArray();

        $this->assertSame('Expert', $array['name']);
        $this->assertSame(['search'], $array['tools']);
        $this->assertSame(10, $array['max_iterations']);
        $this->assertSame('gpt-4o', $array['model']);
        $this->assertSame('auto', $array['permission_mode']);
    }

    public function testFromArrayToArrayPreservesCoreFields(): void
    {
        $original = [
            'tools' => ['search', 'ask'],
            'max_iterations' => 25,
            'model' => 'gpt-4o',
        ];

        $agent = Agent::fromArray($original);
        $array = $agent->toArray();

        $this->assertSame(['search', 'ask'], $array['tools']);
        $this->assertSame(25, $array['max_iterations']);
        $this->assertSame('gpt-4o', $array['model']);
    }

    public function testImmutability(): void
    {
        $agent = new Agent(name: 'Test', tools: ['search']);

        // All properties are readonly — verify via reflection
        $ref = new \ReflectionClass($agent);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue(
                $prop->isReadOnly(),
                "Property {$prop->getName()} should be readonly",
            );
        }
    }
}
