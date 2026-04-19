<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Immutable context passed to agent middleware tool-call hooks.
 * Replaces loose array $runContext with typed, IDE-friendly DTO.
 */
class ToolCallContext
{
    public function __construct(
        public readonly ?string $sessionId = null,
        public readonly string $agentName = '',
        public readonly ?string $toolCallId = null,
        public readonly int $iteration = 0,
    ) {}

    public function with(array $overrides): self
    {
        return new self(
            sessionId: $overrides['sessionId'] ?? $this->sessionId,
            agentName: $overrides['agentName'] ?? $this->agentName,
            toolCallId: $overrides['toolCallId'] ?? $this->toolCallId,
            iteration: $overrides['iteration'] ?? $this->iteration,
        );
    }
}
