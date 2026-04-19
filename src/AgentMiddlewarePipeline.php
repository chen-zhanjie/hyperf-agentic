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

    public function beforeToolCall(string $name, array $arguments, array $runContext = []): ?string
    {
        foreach ($this->middlewares as $mw) {
            try {
                $result = $mw->beforeToolCall($name, $arguments, $runContext);
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

    public function afterToolCall(string $name, array $arguments, string $result, array $runContext = []): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->afterToolCall($name, $arguments, $result, $runContext);
            } catch (\Throwable $e) {
                $this->logger->warning('AgentMiddleware afterToolCall error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }
}
