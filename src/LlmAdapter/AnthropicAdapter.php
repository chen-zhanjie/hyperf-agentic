<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\LlmAdapter;

use ChenZhanjie\Agentic\Contract\LlmAdapterInterface;

/**
 * Anthropic Messages API adapter.
 *
 * Translates between the SDK's internal message format and the
 * Anthropic /v1/messages protocol.
 *
 * Stateless: all streaming state is local to each chatStream() call.
 */
class AnthropicAdapter implements LlmAdapterInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
    ) {}

    public function chat(array $messages, array $options): array
    {
        $model = $options['model'] ?? 'claude-sonnet-4-20250514';

        [$systemPrompt, $cleanMessages] = $this->extractSystemPrompt($messages);
        $anthropicMessages = $this->convertMessages($cleanMessages);
        $tools = $this->convertTools($options['tools'] ?? null);

        $body = array_filter([
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => $systemPrompt,
            'messages' => $anthropicMessages,
            'tools' => $tools,
        ], fn(mixed $v): bool => $v !== null && $v !== '');

        $response = $this->httpPost(rtrim($this->baseUrl, '/') . '/messages', $body);

        return $this->normalizeResponse($response);
    }

    public function chatStream(array $messages, array $options, callable $onChunk): array
    {
        $model = $options['model'] ?? 'claude-sonnet-4-20250514';

        [$systemPrompt, $cleanMessages] = $this->extractSystemPrompt($messages);
        $anthropicMessages = $this->convertMessages($cleanMessages);
        $tools = $this->convertTools($options['tools'] ?? null);

        $body = array_filter([
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => $systemPrompt,
            'messages' => $anthropicMessages,
            'tools' => $tools,
            'stream' => true,
        ], fn(mixed $v): bool => $v !== null && $v !== '');

        // Streaming state is local — no instance property leakage between calls
        $sseBuffer = '';
        $streamState = [];

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => function ($_, string $data) use ($onChunk, &$sseBuffer, &$streamState): int {
                $this->processSseChunk($data, $onChunk, $sseBuffer, $streamState);
                return strlen($data);
            },
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Anthropic API error (HTTP {$httpCode})");
        }

        return $this->buildStreamedResponse($streamState);
    }

    /**
     * Extract system messages from the SDK message array.
     *
     * @return array{0: string|null, 1: array} [system prompt, remaining messages]
     */
    private function extractSystemPrompt(array $messages): array
    {
        $systemParts = [];
        $remaining = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemParts[] = $msg['content'] ?? '';
            } else {
                $remaining[] = $msg;
            }
        }

        $system = empty($systemParts) ? null : implode("\n\n", $systemParts);
        return [$system, $remaining];
    }

    private function convertMessages(array $messages): array
    {
        $converted = [];
        $pendingToolResults = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'user') {
                if (!empty($pendingToolResults)) {
                    $converted[] = ['role' => 'user', 'content' => $pendingToolResults];
                    $pendingToolResults = [];
                }
                $converted[] = ['role' => 'user', 'content' => $msg['content'] ?? ''];
            } elseif ($role === 'assistant') {
                if (!empty($pendingToolResults)) {
                    $converted[] = ['role' => 'user', 'content' => $pendingToolResults];
                    $pendingToolResults = [];
                }

                $content = [];
                $text = $msg['content'] ?? '';
                if (is_string($text) && $text !== '') {
                    $content[] = ['type' => 'text', 'text' => $text];
                }

                $toolCalls = $msg['tool_calls'] ?? [];
                foreach ($toolCalls as $tc) {
                    $arguments = $tc['function']['arguments'] ?? '{}';
                    $input = json_decode($arguments, true) ?? [];
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'] ?? ('tool_' . uniqid()),
                        'name' => $tc['function']['name'] ?? '',
                        'input' => $input,
                    ];
                }

                if (!empty($content)) {
                    $converted[] = ['role' => 'assistant', 'content' => $content];
                }
            } elseif ($role === 'tool') {
                $pendingToolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $msg['tool_call_id'] ?? '',
                    'content' => $msg['content'] ?? '',
                ];
            }
        }

        if (!empty($pendingToolResults)) {
            $converted[] = ['role' => 'user', 'content' => $pendingToolResults];
        }

        return $converted;
    }

    private function convertTools(?array $tools): ?array
    {
        if ($tools === null || empty($tools)) {
            return null;
        }

        $converted = [];
        foreach ($tools as $tool) {
            if (isset($tool['name'], $tool['input_schema'])) {
                $converted[] = $tool;
                continue;
            }

            $func = $tool['function'] ?? $tool;
            $converted[] = array_filter([
                'name' => $func['name'] ?? '',
                'description' => $func['description'] ?? '',
                'input_schema' => $func['parameters'] ?? $func['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ], fn(mixed $v): bool => $v !== '');
        }

        return $converted;
    }

    private function normalizeResponse(array $data): array
    {
        $contentBlocks = $data['content'] ?? [];
        $usage = $data['usage'] ?? [];

        $text = '';
        $toolCalls = [];
        $reasoningContent = null;

        foreach ($contentBlocks as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $text .= $block['text'] ?? '';
            } elseif ($type === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? ('tool_' . uniqid()),
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'] ?? '',
                        'arguments' => json_encode($block['input'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                    ],
                ];
            } elseif ($type === 'thinking') {
                $reasoningContent = ($reasoningContent ?? '') . ($block['thinking'] ?? '');
            }
        }

        $result = [
            'content' => $text,
            'usage' => [
                'prompt_tokens' => $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
            ],
        ];

        if ($reasoningContent !== null) {
            $result['reasoning_content'] = $reasoningContent;
        }

        if (!empty($toolCalls)) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
    }

    private function httpPost(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Anthropic API error (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \RuntimeException('Invalid JSON response from Anthropic API');
        }

        return $data;
    }

    /**
     * Process an SSE chunk, updating local state and emitting to onChunk.
     */
    private function processSseChunk(string $raw, callable $onChunk, string &$sseBuffer, array &$streamState): void
    {
        $sseBuffer .= $raw;
        $lines = explode("\n", $sseBuffer);
        $sseBuffer = array_pop($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]') {
                continue;
            }
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }

            $json = substr($line, 6);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                continue;
            }

            $type = $data['type'] ?? '';

            // Ensure usage is initialized (guards against missing message_start)
            if (!isset($streamState['usage'])) {
                $streamState['usage'] = ['prompt_tokens' => 0, 'completion_tokens' => 0];
            }

            // message_start — capture initial usage
            if ($type === 'message_start') {
                $message = $data['message'] ?? [];
                $usage = $message['usage'] ?? [];
                $streamState['usage'] = [
                    'prompt_tokens' => $usage['input_tokens'] ?? 0,
                    'completion_tokens' => 0,
                ];
            }

            // content_block_delta — text, thinking, tool arguments
            if ($type === 'content_block_delta') {
                $delta = $data['delta'] ?? [];
                $deltaType = $delta['type'] ?? '';

                if ($deltaType === 'text_delta' && isset($delta['text'])) {
                    $onChunk(['content' => $delta['text']]);
                }

                if ($deltaType === 'thinking_delta' && isset($delta['thinking'])) {
                    $onChunk(['reasoning_content' => $delta['thinking']]);
                }

                if ($deltaType === 'input_json_delta' && isset($delta['partial_json'])) {
                    $index = $data['index'] ?? 0;
                    if (!isset($streamState['tool_calls'][$index])) {
                        $streamState['tool_calls'][$index] = [
                            'id' => '',
                            'type' => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    $streamState['tool_calls'][$index]['function']['arguments'] .= $delta['partial_json'];
                }
            }

            // content_block_start — tool_use blocks
            if ($type === 'content_block_start') {
                $block = $data['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $index = $data['index'] ?? 0;
                    $streamState['tool_calls'][$index] = [
                        'id' => $block['id'] ?? '',
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'] ?? '',
                            'arguments' => '',
                        ],
                    ];
                }
            }

            // message_delta — final usage and stop_reason
            if ($type === 'message_delta') {
                $delta = $data['delta'] ?? [];
                if (!empty($delta['stop_reason'])) {
                    $streamState['finish_reason'] = $delta['stop_reason'];
                }
                $usage = $data['usage'] ?? [];
                $streamState['usage']['completion_tokens'] = $usage['output_tokens'] ?? 0;
            }

            if ($type === 'message_stop') {
                $streamState['finish_reason'] = $streamState['finish_reason'] ?? 'stop';
            }
        }
    }

    private function buildStreamedResponse(array $streamState): array
    {
        $result = [
            'content' => '',
            'usage' => $streamState['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0],
        ];

        if (!empty($streamState['tool_calls'])) {
            foreach ($streamState['tool_calls'] as $i => $tc) {
                $args = $tc['function']['arguments'] ?? '';
                json_decode($args);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $streamState['tool_calls'][$i]['function']['arguments'] = '{}';
                }
            }
            $result['tool_calls'] = array_values($streamState['tool_calls']);
        }

        return $result;
    }
}
