<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

/**
 * Conversation message persistence interface.
 *
 * Implementations can store messages in a database, Redis, files, etc.
 * The SDK provides MemoryMessageStore for testing; production apps
 * should inject their own implementation (e.g., DatabaseMessageStore).
 */
interface MessageStoreInterface
{
    /** Load all messages for a conversation */
    public function load(string $conversationId): array;

    /** Append new messages to a conversation (non-destructive) */
    public function append(string $conversationId, array $messages): void;

    /** Delete a conversation */
    public function delete(string $conversationId): void;

    /** Check if a conversation exists */
    public function exists(string $conversationId): bool;
}
