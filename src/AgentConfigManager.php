<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Agent configuration manager — merges defaults + agent definitions + runtime overrides.
 * Decouples Agentic facade from raw config arrays.
 */
class AgentConfigManager
{
    /**
     * @param array $defaults Default settings from agentic/agentic.php
     * @param array<string, array> $agentDefs Agent definitions from agentic/agents.php
     */
    public function __construct(
        private readonly array $defaults = [],
        private readonly array $agentDefs = [],
    ) {}

    /**
     * Get merged agent config: defaults → agent def → runtime overrides.
     */
    public function get(string $agentName, array $runtimeOptions = []): array
    {
        if (!isset($this->agentDefs[$agentName])) {
            throw new \InvalidArgumentException("Agent [{$agentName}] is not defined");
        }

        return array_replace_recursive(
            $this->defaults,
            $this->agentDefs[$agentName],
            $runtimeOptions,
        );
    }

    public function listAgentNames(): array
    {
        return array_keys($this->agentDefs);
    }

    public function has(string $agentName): bool
    {
        return isset($this->agentDefs[$agentName]);
    }
}
