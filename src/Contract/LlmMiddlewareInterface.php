<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\LlmCallRequest;
use ChenZhanjie\Agentic\LlmResponse;

interface LlmMiddlewareInterface
{
    public function beforeCall(LlmCallRequest $request): LlmCallRequest;

    public function afterCall(LlmCallRequest $request, LlmResponse $response): void;

    public function onRetry(string $provider, int $attempt, \Throwable $error): void;

    public function onFailover(string $fromProvider, string $toProvider): void;
}
