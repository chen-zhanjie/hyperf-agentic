<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Exception;

/**
 * Agent suspended exception — flow control signal (not an error).
 * Triggered by: HttpHumanInputResolver, frontend tool calls.
 */
class AgentSuspendedException extends \RuntimeException
{
    public function __construct(
        string $reason,
        array $data = [],
        private readonly string $resumeToken = '',
    ) {
        parent::__construct($reason);
    }

    public function getResumeToken(): string
    {
        return $this->resumeToken;
    }
}
