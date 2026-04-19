<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\LlmCallRequest;
use ChenZhanjie\Agentic\LlmResponse;

interface LlmMiddlewareInterface
{
    public function beforeCall(LlmCallRequest $request): LlmCallRequest;

    /**
     * Observe or transform the LLM response.
     * Return null to pass through the original response, or return a new LlmResponse to replace it.
     */
    public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse;

    public function onRetry(string $provider, int $attempt, \Throwable $error): void;

    public function onFailover(string $fromProvider, string $toProvider): void;

    /**
     * Called for each chunk during chatStream().
     * Use for real-time token counting, live logging, or rate tracking.
     */
    public function onChunk(array $chunk): void;
}
