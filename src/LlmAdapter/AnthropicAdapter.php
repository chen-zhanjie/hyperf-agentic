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
}
