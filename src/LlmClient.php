<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\LlmAdapterInterface;
use ChenZhanjie\Agentic\LlmAdapter\AnthropicAdapter;
use ChenZhanjie\Agentic\LlmAdapter\OpenAiAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Multi-provider LLM client with load balancing, retry, and middleware hooks.
 *
 * Returns structured LlmResponse DTOs with actual provider/model from the API response.
 * Supports its own middleware pipeline (LlmMiddlewarePipeline) for per-call lifecycle hooks.
 */
class LlmClient
{
    /** @var array<string, array> Provider configurations */
    private readonly array $providerConfigs;

    /** @var array{max_attempts: int, base_delay_ms: int, max_delay_ms: int} */
    private readonly array $retryConfig;

    private readonly string $defaultProvider;
    private readonly LoggerInterface $logger;

    private readonly ?\Closure $adapterFactory;

    private readonly ?LlmMiddlewarePipeline $middleware;

    public function __construct(
        array $providerConfigs = [],
        string $defaultProvider = 'openai',
        array $retryConfig = [],
        ?LoggerInterface $logger = null,
        ?callable $adapterFactory = null,
        ?LlmMiddlewarePipeline $middleware = null,
    ) {
        $this->providerConfigs = $providerConfigs;
        $this->defaultProvider = $defaultProvider;
        $this->retryConfig = array_merge([
            'max_attempts' => 2,
            'base_delay_ms' => 1000,
            'max_delay_ms' => 30000,
        ], $retryConfig);
        $this->logger = $logger ?? new NullLogger();
        $this->adapterFactory = $adapterFactory !== null ? \Closure::fromCallable($adapterFactory) : null;
        $this->middleware = $middleware;
    }

    public function chat(array $messages, array $options = []): LlmResponse
    {
        $provider = $options['provider'] ?? $this->defaultProvider;
        $model = $options['model'] ?? $this->providerConfigs[$provider]['model'] ?? 'gpt-4o';

        $request = new LlmCallRequest($messages, $options, $provider, $model);
        $request = $this->middleware?->beforeCall($request) ?? $request;

        $response = $this->retryWithMiddleware(
            $request,
            fn(string $p, LlmCallRequest $r) => $this->doChat($p, $r->messages, $r->options),
        );

        $this->middleware?->afterCall($request, $response);

        return $response;
    }

    public function chatStream(array $messages, array $options, callable $onChunk): LlmResponse
    {
        $provider = $options['provider'] ?? $this->defaultProvider;
        $model = $options['model'] ?? $this->providerConfigs[$provider]['model'] ?? 'gpt-4o';

        $request = new LlmCallRequest($messages, $options, $provider, $model);
        $request = $this->middleware?->beforeCall($request) ?? $request;

        $response = $this->retryWithMiddleware(
            $request,
            fn(string $p, LlmCallRequest $r) => $this->doChatStream($p, $r->messages, $r->options, $onChunk),
        );

        $this->middleware?->afterCall($request, $response);

        return $response;
    }

    /**
     * @return string[]
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->providerConfigs);
    }

    public function hasProvider(string $name): bool
    {
        return isset($this->providerConfigs[$name]);
    }

    public function getDefaultProvider(): string
    {
        return $this->defaultProvider;
    }

    public function getRetryConfig(): array
    {
        return $this->retryConfig;
    }

    // --- Internal ---

    private function sleep(int $ms): void
    {
        if (\function_exists('\\Swoole\\Coroutine\\System::sleep')) {
            \Swoole\Coroutine\System::sleep($ms / 1000);
        } elseif (class_exists(\Hyperf\Coroutine\Coroutine::class) && \Hyperf\Coroutine\Coroutine::inCoroutine()) {
            \Swoole\Coroutine\System::sleep($ms / 1000);
        } else {
            usleep($ms * 1000);
        }
    }

    protected function doChat(string $provider, array $messages, array $options): array
    {
        $config = $this->getProviderConfig($provider);
        $options['model'] = $options['model'] ?? $config['model'] ?? 'gpt-4o';

        if ($this->adapterFactory !== null) {
            return ($this->adapterFactory)('chat', $provider, $config, $messages, $options);
        }

        return $this->callBuiltInAdapter($provider, $config, $messages, $options);
    }

    protected function doChatStream(string $provider, array $messages, array $options, callable $onChunk): array
    {
        $config = $this->getProviderConfig($provider);
        $options['model'] = $options['model'] ?? $config['model'] ?? 'gpt-4o';

        if ($this->adapterFactory !== null) {
            return ($this->adapterFactory)('chatStream', $provider, $config, $messages, $options, $onChunk);
        }

        return $this->callBuiltInAdapterStream($provider, $config, $messages, $options, $onChunk);
    }

    private function callBuiltInAdapter(string $provider, array $config, array $messages, array $options): array
    {
        return $this->createAdapter($provider, $config)->chat($messages, $options);
    }

    private function callBuiltInAdapterStream(string $provider, array $config, array $messages, array $options, callable $onChunk): array
    {
        return $this->createAdapter($provider, $config)->chatStream($messages, $options, $onChunk);
    }

    private function createAdapter(string $provider, array $config): LlmAdapterInterface
    {
        $protocol = $config['protocol'] ?? 'openai';
        $baseUrl = $config['base_url'] ?? $config['url'] ?? '';
        $apiKey = $config['api_key'] ?? '';

        if ($baseUrl === '' || $apiKey === '') {
            throw new \RuntimeException(
                "Built-in adapter requires 'base_url' and 'api_key' in provider config for [{$provider}]. "
                . "Alternatively, provide an adapterFactory callable."
            );
        }

        return match ($protocol) {
            'anthropic' => new AnthropicAdapter($apiKey, $baseUrl),
            default => new OpenAiAdapter($apiKey, $baseUrl),
        };
    }

    private function getProviderConfig(string $provider): array
    {
        if (!isset($this->providerConfigs[$provider])) {
            throw new \InvalidArgumentException("LLM Provider [{$provider}] is not configured");
        }
        return $this->providerConfigs[$provider];
    }

    /**
     * @return string[]
     */
    private function getFailoverChain(string $primary): array
    {
        $chain = [$primary];
        foreach (array_keys($this->providerConfigs) as $name) {
            if ($name !== $primary) {
                $chain[] = $name;
            }
        }
        return $chain;
    }

    /**
     * Retry with exponential backoff + jitter per provider, with middleware hooks.
     * Each provider gets max_attempts retries; then failover to next.
     */
    private function retryWithMiddleware(LlmCallRequest $request, callable $operation): LlmResponse
    {
        $providers = $this->getFailoverChain($request->provider);
        $maxAttempts = $this->retryConfig['max_attempts'];
        $baseDelay = $this->retryConfig['base_delay_ms'];
        $maxDelay = $this->retryConfig['max_delay_ms'];

        $lastException = null;

        foreach ($providers as $i => $provider) {
            if ($i > 0) {
                $this->middleware?->onFailover($providers[$i - 1], $provider);
            }

            $attempt = 0;

            while ($attempt < $maxAttempts) {
                $startMs = (int) (hrtime(true) / 1_000_000);

                try {
                    $raw = $operation($provider, $request);
                    $latencyMs = (int) (hrtime(true) / 1_000_000) - $startMs;

                    $actualModel = $raw['model'] ?? $request->model;

                    return new LlmResponse(
                        content: is_string($raw['content'] ?? null) ? $raw['content'] : (string) ($raw['content'] ?? ''),
                        usage: $raw['usage'] ?? [],
                        provider: $provider,
                        model: $actualModel,
                        reasoningContent: $raw['reasoning_content'] ?? null,
                        toolCalls: $raw['tool_calls'] ?? [],
                        latencyMs: $latencyMs,
                    );
                } catch (\Throwable $e) {
                    $lastException = $e;
                    ++$attempt;

                    $this->logger->warning("LLM call failed on [{$provider}], attempt {$attempt}", [
                        'error' => $e->getMessage(),
                    ]);

                    $this->middleware?->onRetry($provider, $attempt, $e);

                    if ($attempt < $maxAttempts) {
                        $delay = min(
                            (int) ($baseDelay * pow(2, $attempt - 1)) + random_int(0, 500),
                            $maxDelay,
                        );
                        $this->sleep($delay);
                    }
                }
            }
        }

        throw new \RuntimeException(
            'LLM call failed: ' . ($lastException?->getMessage() ?? 'unknown error'),
            0,
            $lastException,
        );
    }
}
