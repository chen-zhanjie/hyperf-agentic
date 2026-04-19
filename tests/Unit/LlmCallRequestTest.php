<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\LlmCallRequest;
use PHPUnit\Framework\TestCase;

class LlmCallRequestTest extends TestCase
{
    public function testPropertiesAreAccessible(): void
    {
        $request = new LlmCallRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            options: ['temperature' => 0.7],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $this->assertSame([['role' => 'user', 'content' => 'hi']], $request->messages);
        $this->assertSame(['temperature' => 0.7], $request->options);
        $this->assertSame('openai', $request->provider);
        $this->assertSame('gpt-4o', $request->model);
    }

    public function testWithOverridesSingleField(): void
    {
        $original = new LlmCallRequest(
            messages: [['role' => 'user', 'content' => 'hi']],
            options: ['temperature' => 0.7],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $modified = $original->with(['model' => 'gpt-4o-mini']);

        $this->assertSame('gpt-4o-mini', $modified->model);
        $this->assertSame('openai', $modified->provider);
        $this->assertSame($original->messages, $modified->messages);
        $this->assertSame($original->options, $modified->options);
    }

    public function testWithOverridesMultipleFields(): void
    {
        $original = new LlmCallRequest(
            messages: [],
            options: [],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $modified = $original->with([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-20250514',
            'options' => ['stream' => true],
        ]);

        $this->assertSame('anthropic', $modified->provider);
        $this->assertSame('claude-sonnet-4-20250514', $modified->model);
        $this->assertSame(['stream' => true], $modified->options);
        $this->assertSame([], $modified->messages);
    }

    public function testWithDoesNotMutateOriginal(): void
    {
        $original = new LlmCallRequest(
            messages: [['role' => 'user', 'content' => 'original']],
            options: ['temperature' => 0.5],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $original->with(['model' => 'changed', 'provider' => 'changed']);

        $this->assertSame('gpt-4o', $original->model);
        $this->assertSame('openai', $original->provider);
    }

    public function testWithEmptyOverridesReturnsIdenticalValues(): void
    {
        $original = new LlmCallRequest(
            messages: [['role' => 'user', 'content' => 'test']],
            options: [],
            provider: 'test',
            model: 'test-model',
        );

        $modified = $original->with([]);

        $this->assertSame($original->messages, $modified->messages);
        $this->assertSame($original->options, $modified->options);
        $this->assertSame($original->provider, $modified->provider);
        $this->assertSame($original->model, $modified->model);
    }

    public function testWithIgnoresUnrecognizedKeys(): void
    {
        $original = new LlmCallRequest(
            messages: [],
            options: [],
            provider: 'openai',
            model: 'gpt-4o',
        );

        $modified = $original->with(['proivder' => 'typo', 'unknown' => 42]);

        $this->assertSame('openai', $modified->provider);
        $this->assertSame('gpt-4o', $modified->model);
    }
}
