<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\GuardrailAuditLoggerInterface;
use Psr\Log\LoggerInterface;

/**
 * Dual-channel audit logger: PSR-3 logger + callable.
 */
class GuardrailAuditLogger implements GuardrailAuditLoggerInterface
{
    /** @var callable|null */
    private $handler;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        ?callable $handler = null,
    ) {
        $this->handler = $handler;
    }

    public function log(GuardrailAuditEntry $entry): void
    {
        if ($this->logger !== null) {
            $level = $entry->decision === 'pass' ? 'info' : 'warning';
            $this->logger->$level('Guardrail decision', $entry->toArray());
        }

        if ($this->handler !== null) {
            ($this->handler)($entry);
        }
    }
}
