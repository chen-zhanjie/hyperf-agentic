<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\LlmAdapter\AnthropicAdapter;
use ChenZhanjie\Agentic\LlmAdapter\OpenAiAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Multi-provider LLM client with load balancing and retry.
 *
 * Supports two built-in protocols:
 * - 'openai': OpenAI-compatible /v1/chat/completions
 * - 'anthropic': Anthropic /v1/messages
 *
 * Each provider config declares its protocol via the 'protocol' key.
 * If omitted, defaults to 'openai' for backward compatibility.
 *
 * Custom adapters can still be injected via the $adapterFactory parameter.
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

    public function __construct(
        array $providerConfigs = [],
        string $defaultProvider = 'openai',
        array $retryConfig = [],
        ?LoggerInterface $logger = null,
        ?callable $adapterFactory = null,
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
    }

    public function chat(array $messages, array $options = []): array
    {
        $provider = $options['provider'] ?? $this->defaultProvider;
        $providers = $this->getFailoverChain($provider);

        return $this->retry(
            fn(string $p) => $this->doChat($p, $messages, $options),
            $providers,
        );
    }

    public function chatStream(array $messages, array $options, callable $onChunk): array
    {
        $provider = $options['provider'] ?? $this->defaultProvider;
        $providers = $this->getFailoverChain($provider);

        return $this->retry(
            fn(string $p) => $this->doChatStream($p, $messages, $options, $onChunk),
            $providers,
        );
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

    /**
     * Dispatch to the correct built-in adapter based on provider protocol.
     */
    private function callBuiltInAdapter(string $provider, array $config, array $messages, array $options): array
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
            'anthropic' => (new AnthropicAdapter($apiKey, $baseUrl))->chat($messages, $options),
            default => (new OpenAiAdapter($apiKey, $baseUrl))->chat($messages, $options),
        };
    }

    /**
     * Dispatch streaming request to the correct built-in adapter.
     */
    private function callBuiltInAdapterStream(string $provider, array $config, array $messages, array $options, callable $onChunk): array
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
            'anthropic' => (new AnthropicAdapter($apiKey, $baseUrl))->chatStream($messages, $options, $onChunk),
            default => (new OpenAiAdapter($apiKey, $baseUrl))->chatStream($messages, $options, $onChunk),
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
     * Retry with exponential backoff + jitter per provider.
     * Each provider gets max_attempts retries; then failover to next.
     */
    private function retry(callable $operation, array $providers): mixed
    {
        $maxAttempts = $this->retryConfig['max_attempts'];
        $baseDelay = $this->retryConfig['base_delay_ms'];
        $maxDelay = $this->retryConfig['max_delay_ms'];

        $lastException = null;

        foreach ($providers as $provider) {
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                try {
                    return $operation($provider);
                } catch (\Throwable $e) {
                    $lastException = $e;
                    ++$attempt;

                    $this->logger->warning("LLM call failed on [{$provider}], attempt {$attempt}", [
                        'error' => $e->getMessage(),
                    ]);

                    if ($attempt < $maxAttempts) {
                        $delay = min(
                            (int) ($baseDelay * pow(2, $attempt - 1)) + random_int(0, 500),
                            $maxDelay,
                        );
                        usleep($delay * 1000);
                    }
                }
            }
            // Exhausted retries for this provider, failover to next
        }

        throw new \RuntimeException(
            'LLM call failed: ' . ($lastException?->getMessage() ?? 'unknown error'),
            0,
            $lastException,
        );
    }
}
