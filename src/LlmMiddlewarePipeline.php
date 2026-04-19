<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\LlmMiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LlmMiddlewarePipeline
{
    /** @var LlmMiddlewareInterface[] */
    private array $middlewares = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function add(LlmMiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function beforeCall(LlmCallRequest $request): LlmCallRequest
    {
        foreach ($this->middlewares as $mw) {
            try {
                $request = $mw->beforeCall($request);
            } catch (\Throwable $e) {
                $this->logger->warning('LlmMiddleware beforeCall error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
                throw $e;
            }
        }
        return $request;
    }

    public function afterCall(LlmCallRequest $request, LlmResponse $response): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->afterCall($request, $response);
            } catch (\Throwable $e) {
                $this->logger->warning('LlmMiddleware afterCall error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }

    public function onRetry(string $provider, int $attempt, \Throwable $error): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->onRetry($provider, $attempt, $error);
            } catch (\Throwable $e) {
                $this->logger->warning('LlmMiddleware onRetry error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }

    public function onFailover(string $fromProvider, string $toProvider): void
    {
        foreach ($this->middlewares as $mw) {
            try {
                $mw->onFailover($fromProvider, $toProvider);
            } catch (\Throwable $e) {
                $this->logger->warning('LlmMiddleware onFailover error: ' . $e->getMessage(), [
                    'middleware' => get_class($mw),
                ]);
            }
        }
    }
}
