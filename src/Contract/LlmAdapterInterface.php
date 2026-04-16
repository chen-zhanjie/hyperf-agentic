<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

/**
 * Contract for LLM protocol adapters.
 *
 * Each adapter translates between the SDK's unified message format
 * and a specific LLM provider's wire protocol.
 *
 * Inspired by the Vercel AI SDK's LanguageModel interface:
 * adapters are thin translation layers that normalize responses.
 */
interface LlmAdapterInterface
{
    /**
     * Send a chat completion request (non-streaming).
     *
     * @param array $messages SDK-format messages (role, content, tool_calls, tool_call_id)
     * @param array $options  LLM options (model, tools, temperature, max_tokens, etc.)
     * @return array Normalized response: ['content' => string, 'tool_calls' => array, 'usage' => array]
     */
    public function chat(array $messages, array $options): array;

    /**
     * Send a streaming chat completion request.
     *
     * @param array    $messages SDK-format messages
     * @param array    $options  LLM options
     * @param callable $onChunk  fn(array $chunk): void — receives normalized deltas
     * @return array Normalized response (content from accumulation, usage, tool_calls)
     */
    public function chatStream(array $messages, array $options, callable $onChunk): array;
}
