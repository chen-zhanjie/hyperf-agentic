<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\LlmCallMeta;
use PHPUnit\Framework\TestCase;

class LlmCallMetaTest extends TestCase
{
    public function testPropertiesAreReadonly(): void
    {
        $meta = new LlmCallMeta(
            provider: 'openai',
            model: 'gpt-4o',
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
        );

        $this->assertSame('openai', $meta->provider);
        $this->assertSame('gpt-4o', $meta->model);
        $this->assertSame(100, $meta->promptTokens);
        $this->assertSame(50, $meta->completionTokens);
        $this->assertSame(150, $meta->totalTokens);
    }

    public function testZeroTokens(): void
    {
        $meta = new LlmCallMeta(
            provider: '',
            model: '',
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
        );

        $this->assertSame(0, $meta->totalTokens);
    }
}
