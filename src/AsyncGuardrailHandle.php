<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class AsyncGuardrailHandle
{
    private ?GuardrailResult $result = null;
    private bool $completed = false;

    public function complete(?GuardrailResult $result): void
    {
        if ($this->completed) {
            return;
        }
        $this->result = $result;
        $this->completed = true;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function getResult(): ?GuardrailResult
    {
        return $this->result;
    }

    public function isBlocked(): bool
    {
        return $this->completed && $this->result !== null && $this->result->tripwire;
    }
}
