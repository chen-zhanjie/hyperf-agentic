<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Stream;

/**
 * Writes OpenAI-compatible SSE chunks to a write callback.
 *
 * Thin transport adapter: subscribes to internal agent events
 * and converts them to the standard SSE wire format for HTTP responses.
 *
 * Usage:
 *   $sse = new SseWriter(fn(string $line) => $eventStream->write($line), model: 'gpt-4o');
 *   $result = $agentic->runStream('agent', $messages, $sse->asOnEvent());
 */
class SseWriter
{
    private readonly string $id;
    private readonly int $created;
    private string $model;
    private bool $roleSent = false;
    private bool $doneSent = false;
    private int $toolCallIndex = 0;

    public function __construct(
        private readonly \Closure $write,
        string $id = '',
        string $model = '',
    ) {
        $this->id = $id !== '' ? $id : uniqid('chatcmpl-');
        $this->created = time();
        $this->model = $model;
    }

    /**
     * Returns a callable compatible with AgentRunner's $onEvent callback.
     * Converts agent lifecycle events into SSE chunks.
     */
    public function asOnEvent(): callable
    {
        return function (string $type, array $payload): void {
            match ($type) {
                'started' => $this->handleStarted($payload),
                'text_delta' => $this->writeContent($payload['content'] ?? ''),
                'reasoning_delta' => $this->writeReasoning($payload['content'] ?? ''),
                'tool_call' => $this->writeToolCall($payload),
                'tool_result' => $this->writeToolResult($payload),
                'complete' => $this->finish([
                    'prompt_tokens' => $payload['prompt_tokens'] ?? 0,
                    'completion_tokens' => $payload['completion_tokens'] ?? 0,
                ]),
                'budget_exceeded' => $this->finish([], 'length'),
                'guardrail_blocked' => $this->finish([], 'content_filter'),
                'error' => $this->finish([], 'stop'),
                'suspended' => $this->finish([], 'stop'),
                default => null,
            };
        };
    }

    /**
     * Returns a callable compatible with LlmClient's $onChunk callback.
     * Auto-emits role delta on first chunk, then content deltas.
     */
    public function asOnChunk(): callable
    {
        return function (array $chunk): void {
            $this->ensureRoleSent();

            if (isset($chunk['content']) && $chunk['content'] !== '') {
                $this->writeChunk(['content' => $chunk['content']]);
            }
        };
    }

    /**
     * Emit the finish chunk with usage and [DONE] sentinel.
     *
     * Internally calls done(), so there is no need to call done() separately.
     *
     * @param array $usage Token usage: `['prompt_tokens' => int, 'completion_tokens' => int]`
     * @param string $finishReason One of: 'stop', 'length', 'content_filter', 'tool_calls'
     */
    public function finish(array $usage = [], string $finishReason = 'stop'): void
    {
        $this->ensureRoleSent();

        $envelope = [
            'id' => $this->id,
            'object' => 'chat.completion.chunk',
            'created' => $this->created,
            'model' => $this->model,
            'choices' => [[
                'index' => 0,
                'delta' => new \stdClass(),
                'finish_reason' => $finishReason,
            ]],
        ];

        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        if ($promptTokens > 0 || $completionTokens > 0) {
            $envelope['usage'] = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ];
        }

        $this->writeRaw('data: ' . json_encode($envelope, JSON_UNESCAPED_UNICODE) . "\n\n");
        $this->done();
    }

    /**
     * Emit the [DONE] sentinel. Idempotent.
     */
    public function done(): void
    {
        if ($this->doneSent) {
            return;
        }
        $this->writeRaw("data: [DONE]\n\n");
        $this->doneSent = true;
    }

    private function handleStarted(array $payload): void
    {
        // Capture model from agent config if not explicitly provided
        if ($this->model === '' && isset($payload['model']) && $payload['model'] !== '') {
            $this->model = $payload['model'];
        }
        $this->writeRole();
    }

    private function writeRole(): void
    {
        if ($this->roleSent) {
            return;
        }
        $this->writeChunk(['role' => 'assistant', 'content' => '']);
        $this->roleSent = true;
    }

    private function writeContent(string $content): void
    {
        $this->ensureRoleSent();
        $this->writeChunk(['content' => $content]);
    }

    private function writeReasoning(string $content): void
    {
        $this->ensureRoleSent();
        $this->writeChunk(['reasoning_content' => $content]);
    }

    private function writeToolCall(array $payload): void
    {
        $this->ensureRoleSent();

        $arguments = $payload['arguments'] ?? [];
        $toolCall = [
            'index' => $this->toolCallIndex++,
            'id' => $payload['call_id'] ?? ('call_' . uniqid()),
            'type' => 'function',
            'function' => [
                'name' => $payload['name'] ?? '',
                'arguments' => json_encode(
                    is_array($arguments) ? $arguments : [],
                    JSON_UNESCAPED_UNICODE,
                ),
            ],
        ];

        $this->writeChunk(['tool_calls' => [$toolCall]]);
    }

    private function writeToolResult(array $payload): void
    {
        $data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->writeRaw("event: tool_result\ndata: {$data}\n\n");
    }

    private function ensureRoleSent(): void
    {
        if (!$this->roleSent) {
            $this->writeRole();
        }
    }

    private function writeChunk(array $delta): void
    {
        $envelope = [
            'id' => $this->id,
            'object' => 'chat.completion.chunk',
            'created' => $this->created,
            'model' => $this->model,
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => null,
            ]],
        ];

        $this->writeRaw('data: ' . json_encode($envelope, JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private function writeRaw(string $line): void
    {
        ($this->write)($line);
    }
}
