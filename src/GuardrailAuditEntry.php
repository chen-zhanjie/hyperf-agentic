<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Immutable audit record for a guardrail decision.
 */
class GuardrailAuditEntry
{
    public readonly float $timestamp;

    public function __construct(
        public readonly string $guardrailName,
        public readonly string $phase,
        public readonly string $decision,
        public readonly string $reason = '',
        public readonly ?float $durationMs = null,
        ?float $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? microtime(true);
    }

    /**
     * @return array{guardrail_name: string, phase: string, decision: string, reason: string, duration_ms: float|null, timestamp: float}
     */
    public function toArray(): array
    {
        return [
            'guardrail_name' => $this->guardrailName,
            'phase' => $this->phase,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'duration_ms' => $this->durationMs,
            'timestamp' => $this->timestamp,
        ];
    }
}
