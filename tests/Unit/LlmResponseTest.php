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
            model: 'gpt-4o',
            provider: 'openai',
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
        $response = new LlmResponse(content: 'Hi', usage: []);

        $this->assertNull($response->model);
        $this->assertNull($response->provider);
        $this->assertNull($response->reasoningContent);
        $this->assertSame([], $response->toolCalls);
    }

    public function testToArray(): void
    {
        $response = new LlmResponse(
            content: 'Hello!',
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5],
            model: 'gpt-4o',
            provider: 'openai',
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
            reasoningContent: 'reasoning',
            toolCalls: [['id' => 'call_1']],
        );

        $array = $response->toArray();

        $this->assertSame('reasoning', $array['reasoning_content']);
        $this->assertSame([['id' => 'call_1']], $array['tool_calls']);
    }
}
