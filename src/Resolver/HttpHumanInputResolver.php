<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Resolver;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\SessionStoreInterface;
use ChenZhanjie\Agentic\Exception\AgentSuspendedException;

/**
 * HTTP non-blocking resolver — suspends agent and waits for resume.
 * Stores pending ask in session, throws AgentSuspendedException.
 */
class HttpHumanInputResolver implements HumanInputResolverInterface
{
    public function __construct(
        private readonly SessionStoreInterface $sessionStore,
        private readonly string $sessionId,
    ) {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $sessionId)) {
            throw new \InvalidArgumentException('Invalid session ID format');
        }
    }

    public function ask(string $message, array $fields = []): array
    {
        $this->sessionStore->set($this->sessionId, 'pending_ask', [
            'message' => $message,
            'fields' => $fields,
            'asked_at' => time(),
        ]);

        throw new AgentSuspendedException(
            reason: 'waiting_for_human_input',
            data: ['message' => $message, 'fields' => $fields],
        );
    }

    public function isBlocking(): bool
    {
        return false;
    }
}
