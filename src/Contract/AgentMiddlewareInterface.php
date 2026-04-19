<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\AgentResult;

interface AgentMiddlewareInterface
{
    public function beforeLoop(array $messages, array $agentConfig): array;

    public function afterLoop(AgentResult $result): AgentResult;

    public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string;

    public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void;
}
