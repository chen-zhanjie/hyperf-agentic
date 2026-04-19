<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Session;

use ChenZhanjie\Agentic\Contract\MessageStoreInterface;

/**
 * In-memory message store — for testing and stateless usage.
 */
class MemoryMessageStore implements MessageStoreInterface
{
    /** @var array<string, array> */
    private array $conversations = [];

    public function load(string $conversationId): array
    {
        return $this->conversations[$conversationId] ?? [];
    }

    public function append(string $conversationId, array $messages): void
    {
        if (!isset($this->conversations[$conversationId])) {
            $this->conversations[$conversationId] = [];
        }
        foreach ($messages as $message) {
            $this->conversations[$conversationId][] = $message;
        }
    }

    public function delete(string $conversationId): void
    {
        unset($this->conversations[$conversationId]);
    }

    public function exists(string $conversationId): bool
    {
        return isset($this->conversations[$conversationId]) && !empty($this->conversations[$conversationId]);
    }

    public function recall(string $conversationId, string $messageId, string $reason): bool
    {
        if (!isset($this->conversations[$conversationId])) {
            return false;
        }

        foreach ($this->conversations[$conversationId] as $i => $msg) {
            if (($msg['id'] ?? null) === $messageId) {
                $this->conversations[$conversationId][$i]['recalled'] = true;
                $this->conversations[$conversationId][$i]['recall_reason'] = $reason;
                $this->conversations[$conversationId][$i]['content'] = '[消息已撤回]';
                return true;
            }
        }

        return false;
    }
}
