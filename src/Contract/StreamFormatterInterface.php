<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

interface StreamFormatterInterface
{
    /**
     * Return a callable compatible with AgentRunner's $onEvent parameter.
     * Signature: callable(string $type, array $payload): void
     */
    public function asOnEvent(): callable;

    /**
     * Return a callable compatible with LlmClient's $onChunk parameter.
     * Signature: callable(array $chunk): void
     */
    public function asOnChunk(): callable;

    /**
     * Emit the final chunk and [DONE] sentinel.
     */
    public function finish(array $usage = [], string $finishReason = 'stop'): void;

    /**
     * Emit the [DONE] sentinel if not already sent.
     */
    public function done(): void;
}
