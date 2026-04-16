<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\GuardrailAuditEntry;

interface GuardrailAuditLoggerInterface
{
    public function log(GuardrailAuditEntry $entry): void;
}
