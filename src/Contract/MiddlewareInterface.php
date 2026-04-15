<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\AgentResult;

interface MiddlewareInterface
{
    public function beforeLoop(array $messages, array $agentConfig): array;
    public function afterLoop(AgentResult $result): AgentResult;
    public function beforeLlmCall(array $messages, array $options): array;
    public function afterLlmCall(array $response, array $usage): void;
    public function beforeToolCall(string $name, array $arguments): ?string;
    public function afterToolCall(string $name, array $arguments, string $result): void;
}
