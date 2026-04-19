<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\AgentMiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AgentMiddlewarePipeline
{
    /** @var AgentMiddlewareInterface[] */
    private array $middlewares = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function add(AgentMiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function onAgentStart(array $agentConfig, array $options): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->onAgentStart($agentConfig, $options);
            } catch (\Throwable $e) {
                $this->logger->warning('AgentMiddleware onAgentStart error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }

    public function beforeLoop(array $messages, array $agentConfig): array
    {
        foreach ($this->middlewares as $mw) {
            try {
                $messages = $mw->beforeLoop($messages, $agentConfig);
            } catch (\Throwable $e) {
                $this->logger->warning('AgentMiddleware beforeLoop error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
                throw $e;
            }
        }
        return $messages;
    }

    public function afterLoop(AgentResult $result): AgentResult
    {
        foreach ($this->middlewares as $mw) {
            try {
                $result = $mw->afterLoop($result);
            } catch (\Throwable $e) {
                $this->logger->warning('AgentMiddleware afterLoop error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
        return $result;
    }

    public function beforeToolCall(string $name, array $arguments, ToolCallContext $context): ?string
    {
        foreach ($this->middlewares as $mw) {
            try {
                $result = $mw->beforeToolCall($name, $arguments, $context);
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AgentMiddleware beforeToolCall error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                    'tool' => $name,
                ]);
            }
        }
        return null;
    }

    public function afterToolCall(string $name, array $arguments, string $result, ToolCallContext $context): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->afterToolCall($name, $arguments, $result, $context);
            } catch (\Throwable $e) {
                $this->logger->warning('AgentMiddleware afterToolCall error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }
}
