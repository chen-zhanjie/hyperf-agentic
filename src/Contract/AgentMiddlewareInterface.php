<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\ToolCallContext;

interface AgentMiddlewareInterface
{
    /**
     * Called once when the agent run starts, before any processing.
     * Use for initialization (setting up coroutine context, logging start, etc.).
     */
    public function onAgentStart(array $agentConfig, array $options): void;

    public function beforeLoop(array $messages, array $agentConfig): array;

    public function afterLoop(AgentResult $result): AgentResult;

    public function beforeToolCall(string $name, array $arguments, ToolCallContext $context): ?string;

    public function afterToolCall(string $name, array $arguments, string $result, ToolCallContext $context): void;
}
