<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Support;

class TokenEstimator
{
    /**
     * Rough token estimation for prompt budgeting.
     * Uses ~4 chars per token heuristic (GPT-family average).
     */
    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Estimate tokens for an array of message objects.
     */
    public static function estimateMessages(array $messages): int
    {
        $total = 0;
        foreach ($messages as $msg) {
            $total += self::estimate((string) ($msg['content'] ?? ''));
            // Each message has ~4 tokens overhead (role, separators)
            $total += 4;
        }
        return $total;
    }
}
