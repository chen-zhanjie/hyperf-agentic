<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\LlmAdapter;

/**
 * Anthropic Messages API adapter.
 *
 * Translates between the SDK's internal message format and the
 * Anthropic /v1/messages protocol.
 *
 * Key differences handled:
 * - System prompt: extracted from messages → top-level `system` parameter
 * - Tool results: SDK `{role: tool, tool_call_id, content}` → Anthropic `{role: user, content: [{type: tool_result}]}`
 * - Tool calls in response: Anthropic `tool_use` content blocks → SDK `tool_calls` array
 */
class AnthropicAdapter
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Send a messages request to the Anthropic API.
     *
     * @param array $messages SDK-format messages
     * @param array $options  LLM options (model, tools, max_tokens, etc.)
     * @return array Normalized response: ['content' => string, 'tool_calls' => array, 'usage' => array]
     */
    public function chat(array $messages, array $options): array
    {
        $model = $options['model'] ?? 'claude-sonnet-4-20250514';

        // Extract system messages from the message array
        [$systemPrompt, $cleanMessages] = $this->extractSystemPrompt($messages);

        // Convert SDK messages to Anthropic format
        $anthropicMessages = $this->convertMessages($cleanMessages);

        // Convert tools to Anthropic format
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

    /**
     * Send a streaming messages request to the Anthropic API.
     *
     * @param array    $messages SDK-format messages
     * @param array    $options  LLM options
     * @param callable $onChunk  fn(array $chunk) => void
     * @return array Normalized response (usage may be empty for streaming)
     */
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

        return $this->httpStream(rtrim($this->baseUrl, '/') . '/messages', $body, $onChunk);
    }

    /**
     * Extract system messages from the SDK message array.
     * Anthropic requires system prompt as a top-level parameter, not in messages.
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

    /**
     * Convert SDK-format messages to Anthropic-format messages.
     *
     * SDK format:
     *   {role: "user", content: "..."}
     *   {role: "assistant", content: "...", tool_calls: [...]}
     *   {role: "tool", tool_call_id: "...", content: "..."}
     *
     * Anthropic format:
     *   {role: "user", content: "..."}
     *   {role: "assistant", content: [{type: "text", text: "..."}, {type: "tool_use", ...}]}
     *   {role: "user", content: [{type: "tool_result", tool_use_id: "...", content: "..."}]}
     */
    private function convertMessages(array $messages): array
    {
        $converted = [];
        $pendingToolResults = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'user') {
                // Flush any pending tool results first
                if (!empty($pendingToolResults)) {
                    $converted[] = ['role' => 'user', 'content' => $pendingToolResults];
                    $pendingToolResults = [];
                }
                $converted[] = ['role' => 'user', 'content' => $msg['content'] ?? ''];
            } elseif ($role === 'assistant') {
                // Flush pending tool results
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
                // Accumulate tool results — Anthropic sends them as a single user message
                $pendingToolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $msg['tool_call_id'] ?? '',
                    'content' => $msg['content'] ?? '',
                ];
            }
        }

        // Flush any remaining tool results
        if (!empty($pendingToolResults)) {
            $converted[] = ['role' => 'user', 'content' => $pendingToolResults];
        }

        return $converted;
    }

    /**
     * Convert SDK tool schemas (OpenAI function format) to Anthropic tool format.
     *
     * SDK: {type: "function", function: {name, description, parameters}}
     * Anthropic: {name, description, input_schema}
     */
    private function convertTools(?array $tools): ?array
    {
        if ($tools === null || empty($tools)) {
            return null;
        }

        $converted = [];
        foreach ($tools as $tool) {
            // Already in Anthropic format?
            if (isset($tool['name'], $tool['input_schema'])) {
                $converted[] = $tool;
                continue;
            }

            // Convert from OpenAI format
            $func = $tool['function'] ?? $tool;
            $converted[] = array_filter([
                'name' => $func['name'] ?? '',
                'description' => $func['description'] ?? '',
                'input_schema' => $func['parameters'] ?? $func['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ], fn(mixed $v): bool => $v !== '');
        }

        return $converted;
    }

    /**
     * Normalize Anthropic response to SDK array format.
     *
     * Anthropic response:
     *   content: [{type: "text", text: "..."}, {type: "tool_use", id, name, input}, ...]
     *   usage: {input_tokens, output_tokens}
     */
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
                $reasoningContent = $block['thinking'] ?? null;
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
     * Execute a streaming HTTP POST and parse SSE chunks.
     *
     * @param callable $onChunk fn(array $chunk) => void
     */
    private function httpStream(string $url, array $body, callable $onChunk): array
    {
        $ch = curl_init($url);
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
            CURLOPT_WRITEFUNCTION => function ($_, string $data) use ($onChunk): int {
                $this->processSseLine($data, $onChunk);
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

        return $this->buildStreamedResponse();
    }

    /** @var string Accumulated SSE buffer across write callbacks */
    private string $sseBuffer = '';

    /** @var array<string, mixed> Accumulated streamed state */
    private array $streamState = [];

    private function processSseLine(string $raw, callable $onChunk): void
    {
        $this->sseBuffer .= $raw;
        $lines = explode("\n", $this->sseBuffer);
        $this->sseBuffer = array_pop($lines); // keep incomplete tail

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
                    if (!isset($this->streamState['tool_calls'][$index])) {
                        $this->streamState['tool_calls'][$index] = [
                            'id' => '',
                            'type' => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    $this->streamState['tool_calls'][$index]['function']['arguments'] .= $delta['partial_json'];
                }
            }

            if ($type === 'content_block_start') {
                $block = $data['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $index = $data['index'] ?? 0;
                    $this->streamState['tool_calls'][$index] = [
                        'id' => $block['id'] ?? '',
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'] ?? '',
                            'arguments' => '',
                        ],
                    ];
                }
            }

            if ($type === 'message_stop') {
                $this->streamState['finish_reason'] = 'stop';
            }
        }
    }

    private function buildStreamedResponse(): array
    {
        $result = [
            'content' => '',
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
        ];

        if (!empty($this->streamState['tool_calls'])) {
            // Ensure arguments are valid JSON strings
            foreach ($this->streamState['tool_calls'] as $i => $tc) {
                $args = $tc['function']['arguments'] ?? '';
                json_decode($args);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->streamState['tool_calls'][$i]['function']['arguments'] = '{}';
                }
            }
            $result['tool_calls'] = array_values($this->streamState['tool_calls']);
        }

        return $result;
    }
}
