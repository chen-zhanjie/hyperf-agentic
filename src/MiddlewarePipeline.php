<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function add(MiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * Execute beforeLoop chain — each middleware transforms messages.
     */
    public function beforeLoop(array $messages, array $agentConfig): array
    {
        foreach ($this->middlewares as $mw) {
            $messages = $mw->beforeLoop($messages, $agentConfig);
        }
        return $messages;
    }

    /**
     * Execute afterLoop chain — each middleware can transform the result.
     */
    public function afterLoop(AgentResult $result): AgentResult
    {
        foreach ($this->middlewares as $mw) {
            try {
                $result = $mw->afterLoop($result);
            } catch (\Throwable $e) {
                $this->logger->warning('Middleware afterLoop error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
        return $result;
    }

    /**
     * Execute beforeLlmCall chain — each middleware transforms options.
     */
    public function beforeLlmCall(array $messages, array $options): array
    {
        foreach ($this->middlewares as $mw) {
            $options = $mw->beforeLlmCall($messages, $options);
        }
        return $options;
    }

    /**
     * Execute afterLlmCall chain — notification only.
     */
    public function afterLlmCall(array $response, LlmCallMeta $meta): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->afterLlmCall($response, $meta);
            } catch (\Throwable $e) {
                $this->logger->warning('Middleware afterLlmCall error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }

    /**
     * Execute beforeToolCall chain — first non-null return intercepts.
     */
    public function beforeToolCall(string $name, array $arguments): ?string
    {
        foreach ($this->middlewares as $mw) {
            $result = $mw->beforeToolCall($name, $arguments);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Execute afterToolCall chain — notification only.
     */
    public function afterToolCall(string $name, array $arguments, string $result): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->afterToolCall($name, $arguments, $result);
            } catch (\Throwable $e) {
                $this->logger->warning('Middleware afterToolCall error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }
}
