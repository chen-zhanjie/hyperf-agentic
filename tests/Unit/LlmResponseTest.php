<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\LlmResponse;
use PHPUnit\Framework\TestCase;

class LlmResponseTest extends TestCase
{
    public function testPropertiesAreAccessible(): void
    {
        $response = new LlmResponse(
            content: 'Hello!',
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5],
            provider: 'openai',
            model: 'gpt-4o',
            reasoningContent: 'thinking...',
            toolCalls: [['name' => 'search']],
        );

        $this->assertSame('Hello!', $response->content);
        $this->assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5], $response->usage);
        $this->assertSame('gpt-4o', $response->model);
        $this->assertSame('openai', $response->provider);
        $this->assertSame('thinking...', $response->reasoningContent);
        $this->assertSame([['name' => 'search']], $response->toolCalls);
    }

    public function testDefaultValues(): void
    {
        $response = new LlmResponse(
            content: 'Hi',
            usage: [],
            provider: 'test',
            model: 'test-model',
        );

        $this->assertNull($response->reasoningContent);
        $this->assertSame([], $response->toolCalls);
        $this->assertSame(0, $response->latencyMs);
    }

    public function testToArray(): void
    {
        $response = new LlmResponse(
            content: 'Hello!',
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $array = $response->toArray();

        $this->assertSame('Hello!', $array['content']);
        $this->assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5], $array['usage']);
        $this->assertSame('gpt-4o', $array['model']);
        $this->assertSame('openai', $array['provider']);
    }

    public function testToArrayWithOptionalFields(): void
    {
        $response = new LlmResponse(
            content: 'test',
            usage: [],
            provider: 'test',
            model: 'test',
            reasoningContent: 'reasoning',
            toolCalls: [['id' => 'call_1']],
        );

        $array = $response->toArray();

        $this->assertSame('reasoning', $array['reasoning_content']);
        $this->assertSame([['id' => 'call_1']], $array['tool_calls']);
    }

    public function testHelperMethods(): void
    {
        $response = new LlmResponse(
            content: 'test',
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $this->assertSame(100, $response->promptTokens());
        $this->assertSame(50, $response->completionTokens());
        $this->assertSame(150, $response->totalTokens());

        $meta = $response->toCallMeta();
        $this->assertSame('openai', $meta->provider);
        $this->assertSame('gpt-4o', $meta->model);
        $this->assertSame(100, $meta->promptTokens);
        $this->assertSame(50, $meta->completionTokens);
        $this->assertSame(150, $meta->totalTokens);
    }

    public function testToArrayIncludesLatencyWhenPositive(): void
    {
        $response = new LlmResponse(
            content: 'test',
            usage: [],
            provider: 'openai',
            model: 'gpt-4o',
            latencyMs: 250,
        );

        $array = $response->toArray();
        $this->assertSame(250, $array['latency_ms']);
    }

    public function testToArrayOmitsLatencyWhenZero(): void
    {
        $response = new LlmResponse(
            content: 'test',
            usage: [],
            provider: 'openai',
            model: 'gpt-4o',
            latencyMs: 0,
        );

        $array = $response->toArray();
        $this->assertArrayNotHasKey('latency_ms', $array);
    }
}
