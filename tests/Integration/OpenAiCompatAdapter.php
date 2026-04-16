<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

/**
 * OpenAI-compatible HTTP adapter for integration tests.
 *
 * Sends real HTTP requests to any OpenAI-compatible API endpoint.
 * Translates responses into the array format expected by LlmClient::adapterFactory.
 */
class OpenAiCompatAdapter
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    /**
     * Adapter factory callback compatible with LlmClient constructor.
     *
     * Usage:
     *   new LlmClient(..., adapterFactory: new OpenAiCompatAdapter(...)->factory())
     */
    public function factory(): callable
    {
        return function (string $type, string $provider, array $config, array $messages, array $options): array {
            return match ($type) {
                'chat' => $this->chat($config, $messages, $options),
                default => throw new \InvalidArgumentException("Unsupported operation: {$type}"),
            };
        };
    }

    /**
     * Send a chat completion request and return normalized response.
     */
    public function chat(array $config, array $messages, array $options): array
    {
        $model = $options['model'] ?? $config['model'] ?? 'gpt-4o';
        $tools = $options['tools'] ?? null;

        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        if ($tools !== null && !empty($tools)) {
            $body['tools'] = $tools;
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("HTTP request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("LLM API error (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \RuntimeException('Invalid JSON response from LLM API');
        }

        return $this->normalizeResponse($data);
    }

    /**
     * Normalize OpenAI-format response to SDK array format.
     */
    private function normalizeResponse(array $data): array
    {
        $choice = $data['choices'][0] ?? null;
        if ($choice === null) {
            throw new \RuntimeException('No choices in LLM response');
        }

        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';
        $reasoningContent = $message['reasoning_content'] ?? null;
        $toolCalls = $message['tool_calls'] ?? null;
        $usage = $data['usage'] ?? [];

        // Normalize usage to expected format
        $normalizedUsage = [
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
        ];

        $result = [
            'content' => $content,
            'usage' => $normalizedUsage,
        ];

        if ($reasoningContent !== null) {
            $result['reasoning_content'] = $reasoningContent;
        }

        if (!empty($toolCalls)) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
    }
}
