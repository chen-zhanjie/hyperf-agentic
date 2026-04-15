<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Multi-provider LLM client with load balancing and retry.
 */
class LlmClient
{
    /** @var array<string, array> Provider configurations */
    private readonly array $providerConfigs;

    /** @var array{max_attempts: int, base_delay_ms: int, max_delay_ms: int} */
    private readonly array $retryConfig;

    private readonly string $defaultProvider;
    private readonly LoggerInterface $logger;

    /** @var callable|null Adapter factory */
    private $adapterFactory = null;

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
        $this->adapterFactory = $adapterFactory;
    }

    public function chat(array $messages, array $options = []): string|array
    {
        $provider = $options['provider'] ?? $this->defaultProvider;
        $providers = $this->getFailoverChain($provider);

        return $this->retry(
            fn(string $p) => $this->doChat($p, $messages, $options),
            $providers,
        );
    }

    public function chatStream(array $messages, array $options, callable $onChunk): void
    {
        $provider = $options['provider'] ?? $this->defaultProvider;
        $providers = $this->getFailoverChain($provider);

        $this->retry(
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

    protected function doChat(string $provider, array $messages, array $options): string|array
    {
        $config = $this->getProviderConfig($provider);
        $options['model'] = $options['model'] ?? $config['model'] ?? 'gpt-4o';

        if ($this->adapterFactory !== null) {
            return ($this->adapterFactory)('chat', $provider, $config, $messages, $options);
        }

        throw new \RuntimeException(
            "LLM adapter not configured. Set adapterFactory or use Hyperf integration."
        );
    }

    protected function doChatStream(string $provider, array $messages, array $options, callable $onChunk): void
    {
        $config = $this->getProviderConfig($provider);
        $options['model'] = $options['model'] ?? $config['model'] ?? 'gpt-4o';

        if ($this->adapterFactory !== null) {
            ($this->adapterFactory)('chatStream', $provider, $config, $messages, $options, $onChunk);
            return;
        }

        throw new \RuntimeException(
            "LLM adapter not configured. Set adapterFactory or use Hyperf integration."
        );
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
