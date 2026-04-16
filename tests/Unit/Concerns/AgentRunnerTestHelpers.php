<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Concerns;

use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolRegistry;

trait AgentRunnerTestHelpers
{
    protected function createRunner(
        LlmClient $llmClient,
        ?ToolRegistry $toolRegistry = null,
        ?GuardrailRunner $guardrailRunner = null,
        ?MiddlewarePipeline $pipeline = null,
        ?SkillRegistry $skillRegistry = null,
        ?ToolGuardrailRunner $toolGuardrailRunner = null,
        ?ToolPermissionPolicyInterface $permissionPolicy = null,
        ?PermissionApprovalStoreInterface $approvalStore = null,
    ): AgentRunner {
        return new AgentRunner(
            llmClient: $llmClient,
            promptBuilder: new \ChenZhanjie\Agentic\PromptBuilder(),
            toolRegistry: $toolRegistry ?? new ToolRegistry(),
            guardrailRunner: $guardrailRunner ?? new GuardrailRunner(),
            middleware: $pipeline ?? new MiddlewarePipeline(),
            toolGuardrailRunner: $toolGuardrailRunner ?? new ToolGuardrailRunner(),
            permissionPolicy: $permissionPolicy ?? new ConfigToolPermissionPolicy(),
            approvalStore: $approvalStore,
            skillRegistry: $skillRegistry,
        );
    }

    protected function createMockLlm(array $responses): LlmClient
    {
        $index = 0;
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options) use ($responses, &$index): string|array {
                $response = $responses[$index] ?? ['content' => 'no more responses'];
                $index++;
                return $response;
            },
        );
    }

    protected function createCallbackMockLlm(callable $callback): LlmClient
    {
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: $callback,
        );
    }

    protected function createInfiniteToolCallLlm(string $toolName, string $args): LlmClient
    {
        return new LlmClient(
            providerConfigs: ['test' => ['model' => 'test-model']],
            defaultProvider: 'test',
            adapterFactory: function (string $type, string $provider, array $config, array $messages, array $options) use ($toolName, $args): array {
                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'call_' . uniqid(), 'type' => 'function', 'function' => [
                            'name' => $toolName,
                            'arguments' => $args,
                        ]],
                    ],
                    'usage' => $this->usage(100, 30),
                ];
            },
        );
    }

    protected function createMockTool(string $name, string $description, string|array $returnValue): ToolInterface
    {
        return new class($name, $description, $returnValue) implements ToolInterface {
            public function __construct(
                private readonly string $toolName,
                private readonly string $toolDesc,
                private readonly string|array $returnValue,
            ) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return $this->toolDesc; }
            public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $arguments): string|array { return $this->returnValue; }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        };
    }

    protected function defaultConfig(int $maxIterations = 15, ?int $maxCostTokens = null): array
    {
        $config = [
            'max_iterations' => $maxIterations,
            'persona' => new Persona(
                name: 'Test',
                content: 'You are a test assistant.',
            ),
            'system_prompt' => '',
            'tools' => [],
            'scene' => 'test',
        ];
        if ($maxCostTokens !== null) {
            $config['max_cost_tokens'] = $maxCostTokens;
        }
        return $config;
    }

    protected function usage(int $prompt, int $completion): array
    {
        return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion];
    }
}
