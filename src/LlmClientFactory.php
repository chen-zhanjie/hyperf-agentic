<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class LlmClientFactory
{
    public function __invoke(ContainerInterface $container): LlmClient
    {
        $config = $container->get(\Hyperf\Contract\ConfigInterface::class);

        $providersConfig = $config->get('agentic.providers', []);
        $providerConfigs = $providersConfig['providers'] ?? [];
        $defaultProvider = $providersConfig['default'] ?? 'openai';

        $logger = null;
        try {
            $logger = $container->get(LoggerInterface::class);
        } catch (\Throwable) {
            // Logger not registered; LlmClient falls back to NullLogger
        }

        $middleware = null;
        try {
            $middleware = $container->get(LlmMiddlewarePipeline::class);
        } catch (\Throwable) {
            // No LLM middleware registered; LlmClient runs without hooks
        }

        return new LlmClient(
            providerConfigs: $providerConfigs,
            defaultProvider: $defaultProvider,
            logger: $logger,
            middleware: $middleware,
        );
    }
}
