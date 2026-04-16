<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\LlmAdapter;

use ChenZhanjie\Agentic\Contract\LlmAdapterInterface;

/**
 * OpenAI-compatible chat completions adapter.
 *
 * Translates between the SDK's internal message format and the
 * OpenAI /v1/chat/completions protocol.
 *
 * Stateless: all streaming state is local to each chatStream() call.
 */
class OpenAiAdapter implements LlmAdapterInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
    ) {}

    public function chat(array $messages, array $options): array
    {
        $model = $options['model'] ?? 'gpt-4o';

        $body = array_filter([
            'model' => $model,
            'messages' => $messages,
            'tools' => $options['tools'] ?? null,
        ], fn(mixed $v): bool => $v !== null);

        $response = $this->httpPost(rtrim($this->baseUrl, '/') . '/chat/completions', $body);

        return $this->normalizeResponse($response);
    }

    public function chatStream(array $messages, array $options, callable $onChunk): array
    {
        $model = $options['model'] ?? 'gpt-4o';

        $body = array_filter([
            'model' => $model,
            'messages' => $messages,
            'tools' => $options['tools'] ?? null,
            'stream' => true,
        ], fn(mixed $v): bool => $v !== null);

        // Streaming state is local — no instance property leakage between calls
        $sseBuffer = '';
        $streamState = [];

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
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
            throw new \RuntimeException("LLM API error (HTTP {$httpCode})");
        }

        return $this->buildStreamedResponse($streamState);
    }

    private function normalizeResponse(array $data): array
    {
        $choice = $data['choices'][0] ?? null;
        if ($choice === null) {
            throw new \RuntimeException('No choices in OpenAI response');
        }

        $message = $choice['message'] ?? [];
        $usage = $data['usage'] ?? [];

        $result = [
            'content' => $message['content'] ?? '',
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
            ],
        ];

        if (isset($message['reasoning_content'])) {
            $result['reasoning_content'] = $message['reasoning_content'];
        }

        $toolCalls = $message['tool_calls'] ?? null;
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
                'Authorization: Bearer ' . $this->apiKey,
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
            throw new \RuntimeException("LLM API error (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \RuntimeException('Invalid JSON response from LLM API');
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
        $sseBuffer = array_pop($lines); // keep incomplete tail

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

            $choice = $data['choices'][0] ?? null;
            if (!is_array($choice)) {
                continue;
            }

            $delta = $choice['delta'] ?? [];

            // Text delta
            if (isset($delta['content']) && $delta['content'] !== '') {
                $onChunk(['content' => $delta['content']]);
            }

            // Reasoning delta
            if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                $onChunk(['reasoning_content' => $delta['reasoning_content']]);
            }

            // Tool call deltas
            if (!empty($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $index = $tc['index'] ?? 0;
                    if (!isset($streamState['tool_calls'][$index])) {
                        $streamState['tool_calls'][$index] = [
                            'id' => $tc['id'] ?? '',
                            'type' => $tc['type'] ?? 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    if (!empty($tc['function']['name'] ?? '')) {
                        $streamState['tool_calls'][$index]['function']['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $streamState['tool_calls'][$index]['function']['arguments'] .= $tc['function']['arguments'];
                    }
                    if (!empty($tc['id'] ?? '')) {
                        $streamState['tool_calls'][$index]['id'] = $tc['id'];
                    }
                }
            }

            // Finish reason
            if (!empty($choice['finish_reason'])) {
                $streamState['finish_reason'] = $choice['finish_reason'];
            }
        }
    }

    private function buildStreamedResponse(array $streamState): array
    {
        $result = [
            'content' => '',
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
        ];

        if (!empty($streamState['tool_calls'])) {
            $result['tool_calls'] = array_values($streamState['tool_calls']);
        }

        return $result;
    }
}
