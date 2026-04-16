<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\LlmAdapter;

/**
 * OpenAI-compatible chat completions adapter.
 *
 * Translates between the SDK's internal message format and the
 * OpenAI /v1/chat/completions protocol.
 */
class OpenAiAdapter
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Send a chat completion request.
     *
     * @param array $messages SDK-format messages (role, content, tool_calls, tool_call_id)
     * @param array $options  LLM options (model, tools, temperature, etc.)
     * @return array Normalized response: ['content' => string, 'tool_calls' => array, 'usage' => array]
     */
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
}
