<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Support\TokenEstimator;

class TokenEstimatorTest extends TestCase
{
    public function testEstimateReturnsTokenCount(): void
    {
        // 20 chars / 4 = 5 tokens
        $this->assertSame(5, TokenEstimator::estimate('12345678901234567890'));
    }

    public function testEstimateHandlesEmptyString(): void
    {
        $this->assertSame(0, TokenEstimator::estimate(''));
    }

    public function testEstimateRoundsUp(): void
    {
        // 5 chars / 4 = 1.25 → ceil = 2
        $this->assertSame(2, TokenEstimator::estimate('hello'));
    }

    public function testEstimateHandlesUnicode(): void
    {
        // Chinese characters — 4 chars, each ~3 bytes but mb_strlen = 4
        $this->assertSame(1, TokenEstimator::estimate('你好'));
    }

    public function testEstimateMessagesSumsContent(): void
    {
        $messages = [
            ['role' => 'user', 'content' => '12345678901234567890'], // 5 tokens + 4 overhead
            ['role' => 'assistant', 'content' => '1234'],            // 1 token + 4 overhead
        ];
        // 5 + 4 + 1 + 4 = 14
        $this->assertSame(14, TokenEstimator::estimateMessages($messages));
    }

    public function testEstimateMessagesHandlesEmptyArray(): void
    {
        $this->assertSame(0, TokenEstimator::estimateMessages([]));
    }
}
