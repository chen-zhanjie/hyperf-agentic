<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class AgentResult
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $reasoningContent = null,
        public readonly int $iterations = 0,
        public readonly int $elapsedMs = 0,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $toolCalls = 0,
        public readonly ?string $stopReason = null,
        private readonly ?string $suspendedReason = null,
        private readonly ?array $suspendedData = null,
        public readonly bool $recalled = false,
        public readonly ?string $recallReason = null,
        public readonly ?string $messageId = null,
    ) {}

    public static function complete(
        string $content,
        int $iterations = 0,
        int $elapsedMs = 0,
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $toolCalls = 0,
        ?string $reasoningContent = null,
    ): self {
        return new self(
            content: $content,
            reasoningContent: $reasoningContent,
            iterations: $iterations,
            elapsedMs: $elapsedMs,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            toolCalls: $toolCalls,
            stopReason: 'complete',
        );
    }

    public static function suspended(string $reason, array $data = []): self
    {
        return new self(
            content: '',
            stopReason: 'suspended',
            suspendedReason: $reason,
            suspendedData: $data,
        );
    }

    public static function budgetExhausted(int $iterations, int $max): self
    {
        return new self(
            content: '',
            iterations: $iterations,
            stopReason: 'budget_exhausted',
        );
    }

    public static function guardrailBlocked(string $type, string $reason, int $elapsedMs = 0): self
    {
        return new self(
            content: '',
            stopReason: 'guardrail',
            elapsedMs: $elapsedMs,
            suspendedReason: $type,
            suspendedData: ['reason' => $reason],
        );
    }

    public static function recalled(
        string $content,
        string $reason,
        string $messageId = '',
        int $elapsedMs = 0,
    ): self {
        return new self(
            content: $content,
            stopReason: 'guardrail',
            elapsedMs: $elapsedMs,
            recalled: true,
            recallReason: $reason,
            messageId: $messageId !== '' ? $messageId : null,
        );
    }

    public function isComplete(): bool
    {
        return $this->stopReason !== null && $this->suspendedReason === null;
    }

    public function isSuspended(): bool
    {
        return $this->suspendedReason !== null;
    }

    public function isBudgetExhausted(): bool
    {
        return $this->stopReason === 'budget_exhausted';
    }

    public function isGuardrailBlocked(): bool
    {
        return $this->stopReason === 'guardrail';
    }

    public function isRecalled(): bool
    {
        return $this->recalled;
    }

    public function getSuspendedReason(): ?string
    {
        return $this->suspendedReason;
    }

    public function getSuspendedData(): ?array
    {
        return $this->suspendedData;
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'reasoning_content' => $this->reasoningContent,
            'iterations' => $this->iterations,
            'elapsed_ms' => $this->elapsedMs,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'tool_calls' => $this->toolCalls,
            'stop_reason' => $this->stopReason,
            'suspended' => $this->isSuspended(),
            'recalled' => $this->recalled,
            'recall_reason' => $this->recallReason,
            'message_id' => $this->messageId,
        ];
    }
}
