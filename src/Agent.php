<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Persona\Persona;

/**
 * Agent DTO — an Agent is a config, not a class.
 *
 * Inspired by OpenAI Agents SDK: agents are data objects that define
 * behavior. The Runner owns execution logic.
 *
 * Can be constructed directly or hydrated from legacy array configs.
 */
class Agent
{
    public function __construct(
        public readonly string $name = 'Assistant',
        public readonly string|Persona|null $persona = null,
        public readonly array $tools = [],
        public readonly array $skills = [],
        public readonly array $guardrails = [],
        public readonly array $guardrailModes = [],
        public readonly array $toolPermissions = [],
        public readonly PermissionMode $permissionMode = PermissionMode::DEFAULT,
        public readonly bool|array|null $autoApprove = null,
        public readonly int $maxIterations = 15,
        public readonly int $maxCostTokens = 0,
        public readonly ?string $systemPrompt = null,
        public readonly ?string $model = null,
        public readonly ?string $provider = null,
        public readonly string $scene = 'http',
        public readonly int $asyncGuardrailTimeout = 5000,
        public readonly int $cancellationTimeoutMs = 0,
    ) {}

    /**
     * Create from legacy array config (used by config files and Agentic facade).
     */
    public static function fromArray(array $config): self
    {
        $permissionMode = PermissionMode::DEFAULT;
        if (isset($config['permission_mode'])) {
            $permissionMode = PermissionMode::from($config['permission_mode']);
        }

        $persona = self::resolvePersona($config['persona'] ?? null);

        return new self(
            name: $config['name'] ?? self::extractPersonaName($persona) ?? 'Assistant',
            persona: $persona,
            tools: $config['tools'] ?? [],
            skills: $config['skills'] ?? [],
            guardrails: $config['guardrails'] ?? [],
            guardrailModes: $config['guardrail_modes'] ?? [],
            toolPermissions: $config['tool_permissions'] ?? [],
            permissionMode: $permissionMode,
            autoApprove: $config['auto_approve'] ?? ($config['tool_permissions']['auto_approve'] ?? null),
            maxIterations: (int) ($config['max_iterations'] ?? 15),
            maxCostTokens: (int) ($config['max_cost_tokens'] ?? 0),
            systemPrompt: $config['system_prompt'] ?? null,
            model: $config['model'] ?? null,
            provider: $config['provider'] ?? null,
            scene: $config['scene'] ?? 'http',
            asyncGuardrailTimeout: (int) ($config['async_guardrail_timeout'] ?? 5000),
            cancellationTimeoutMs: (int) ($config['cancellation_timeout_ms'] ?? 0),
        );
    }

    /**
     * Convert back to legacy array format.
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'persona' => $this->persona,
            'tools' => $this->tools,
            'skills' => $this->skills,
            'guardrails' => $this->guardrails,
            'guardrail_modes' => $this->guardrailModes,
            'tool_permissions' => array_filter([
                'allow' => $this->toolPermissions['allow'] ?? [],
                'ask' => $this->toolPermissions['ask'] ?? [],
                'deny' => $this->toolPermissions['deny'] ?? [],
                'auto_approve' => $this->autoApprove,
            ], fn(mixed $v): bool => $v !== null && $v !== [] && $v !== false),
            'permission_mode' => $this->permissionMode->value,
            'max_iterations' => $this->maxIterations,
            'max_cost_tokens' => $this->maxCostTokens,
            'system_prompt' => $this->systemPrompt,
            'model' => $this->model,
            'provider' => $this->provider,
            'scene' => $this->scene,
            'async_guardrail_timeout' => $this->asyncGuardrailTimeout,
            'cancellation_timeout_ms' => $this->cancellationTimeoutMs,
        ], fn(mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Resolve persona input to Persona object, string, or null.
     */
    private static function resolvePersona(mixed $persona): Persona|string|null
    {
        if ($persona instanceof Persona || is_string($persona) || $persona === null) {
            return $persona;
        }
        if (is_array($persona)) {
            return Persona::fromArray($persona);
        }
        return null;
    }

    /**
     * Extract a name from a resolved persona.
     */
    private static function extractPersonaName(Persona|string|null $persona): ?string
    {
        if ($persona instanceof Persona) {
            return $persona->name;
        }
        return null;
    }
}
