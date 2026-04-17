<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

use ChenZhanjie\Agentic\LlmClient;
use PHPUnit\Framework\TestCase;

/**
 * Shared integration test configuration.
 *
 * Reads per-protocol environment variables and provides factory methods
 * for creating LlmClient instances. Each protocol has its own API_KEY,
 * BASE_URL, and MODEL so tests can target different providers.
 *
 * @see .env.test.example for the expected variable names
 */
class IntegrationTestConfig
{
    private const RETRY_CONFIG = ['max_attempts' => 2, 'base_delay_ms' => 1000, 'max_delay_ms' => 5000];

    // ── OpenAI Protocol ──

    public static function openaiProvider(): array
    {
        return [
            'protocol' => 'openai',
            'api_key' => getenv('AGENTIC_TEST_OPENAI_API_KEY') ?: '',
            'base_url' => getenv('AGENTIC_TEST_OPENAI_BASE_URL') ?: '',
            'model' => getenv('AGENTIC_TEST_OPENAI_MODEL') ?: 'gpt-4o',
        ];
    }

    public static function createOpenAiLlmClient(): LlmClient
    {
        return new LlmClient(
            providerConfigs: ['test' => self::openaiProvider()],
            defaultProvider: 'test',
            retryConfig: self::RETRY_CONFIG,
        );
    }

    public static function skipIfNoOpenAIKey(): void
    {
        $key = getenv('AGENTIC_TEST_OPENAI_API_KEY');
        if ($key === false || $key === '' || $key === 'sk-your-openai-key') {
            TestCase::markTestSkipped('AGENTIC_TEST_OPENAI_API_KEY not configured — set it in .env.test');
        }
    }

    // ── Anthropic Protocol ──

    public static function anthropicProvider(): array
    {
        return [
            'protocol' => 'anthropic',
            'api_key' => getenv('AGENTIC_TEST_ANTHROPIC_API_KEY') ?: '',
            'base_url' => getenv('AGENTIC_TEST_ANTHROPIC_BASE_URL') ?: '',
            'model' => getenv('AGENTIC_TEST_ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514',
        ];
    }

    public static function createAnthropicLlmClient(): LlmClient
    {
        return new LlmClient(
            providerConfigs: ['anthropic' => self::anthropicProvider()],
            defaultProvider: 'anthropic',
            retryConfig: self::RETRY_CONFIG,
        );
    }

    public static function skipIfNoAnthropicKey(): void
    {
        $key = getenv('AGENTIC_TEST_ANTHROPIC_API_KEY');
        if ($key === false || $key === '' || $key === 'sk-your-anthropic-key') {
            TestCase::markTestSkipped('AGENTIC_TEST_ANTHROPIC_API_KEY not configured — set it in .env.test');
        }
    }
}
