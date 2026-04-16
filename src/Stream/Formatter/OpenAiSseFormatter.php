<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Stream\Formatter;

use ChenZhanjie\Agentic\Contract\StreamFormatterInterface;

/**
 * Formats SDK streaming events into OpenAI-compatible SSE output.
 *
 * Usage with runStream():
 *   $formatter = new OpenAiSseFormatter(fn(string $line) => echo $line);
 *   $result = $agentic->runStream('agent', $messages, $formatter->asOnEvent());
 *
 * Usage with chatStream():
 *   $formatter = new OpenAiSseFormatter(fn(string $line) => echo $line);
 *   $result = $agentic->chatStream($messages, $formatter->asOnChunk());
 *   $formatter->finish($result['usage'] ?? []);
 */
class OpenAiSseFormatter implements StreamFormatterInterface
{
    private readonly string $id;
    private readonly int $created;
    private bool $roleSent = false;
    private bool $doneSent = false;
    private int $toolCallIndex = 0;

    public function __construct(
        private readonly \Closure $write,
        string $id = '',
        private readonly string $model = '',
    ) {
        $this->id = $id !== '' ? $id : uniqid('chatcmpl-');
        $this->created = time();
    }

    public function asOnEvent(): callable
    {
        return function (string $type, array $payload): void {
            match ($type) {
                'started' => $this->emitRole(),
                'text_delta' => $this->emitContent($payload['content'] ?? ''),
                'tool_call' => $this->emitToolCall($payload),
                'complete' => $this->finish([
                    'prompt_tokens' => $payload['prompt_tokens'] ?? 0,
                    'completion_tokens' => $payload['completion_tokens'] ?? 0,
                ]),
                'budget_exceeded' => $this->finish([], 'length'),
                'guardrail_blocked' => $this->finish([], 'content_filter'),
                default => null,
            };
        };
    }

    public function asOnChunk(): callable
    {
        return function (array $chunk): void {
            $this->ensureRoleSent();

            if (isset($chunk['content']) && $chunk['content'] !== '') {
                $this->writeChunk(['content' => $chunk['content']]);
            }
        };
    }

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

    public function done(): void
    {
        if ($this->doneSent) {
            return;
        }
        $this->writeRaw("data: [DONE]\n\n");
        $this->doneSent = true;
    }

    private function emitRole(): void
    {
        if ($this->roleSent) {
            return;
        }
        $this->writeChunk(['role' => 'assistant', 'content' => '']);
        $this->roleSent = true;
    }

    private function emitContent(string $content): void
    {
        $this->ensureRoleSent();
        $this->writeChunk(['content' => $content]);
    }

    private function emitToolCall(array $payload): void
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

    private function ensureRoleSent(): void
    {
        if (!$this->roleSent) {
            $this->emitRole();
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
