<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Middleware;

use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\Contract\MiddlewareInterface;
use ChenZhanjie\Agentic\LlmCallMeta;

/**
 * Audit middleware — logs all tool calls with PII redaction.
 */
class AuditMiddleware implements MiddlewareInterface
{
    private string $currentSessionId = '';
    private string $currentAgentName = '';

    private readonly ?\Closure $auditLogger;

    public function __construct(?callable $auditLogger = null)
    {
        $this->auditLogger = $auditLogger !== null ? \Closure::fromCallable($auditLogger) : null;
    }

    public function beforeLoop(array $messages, array $agentConfig): array
    {
        $this->currentSessionId = $agentConfig['session_id'] ?? '';
        $this->currentAgentName = $agentConfig['agent_name'] ?? '';
        return $messages;
    }

    public function afterLoop(AgentResult $result): AgentResult
    {
        return $result;
    }

    public function beforeLlmCall(array $messages, array $options): array
    {
        return $options;
    }

    public function afterLlmCall(array $response, LlmCallMeta $meta): void {}

    public function beforeToolCall(string $name, array $arguments): ?string
    {
        $this->log('tool.call', [
            'tool' => $name,
            'agent' => $this->currentAgentName,
            'arguments' => $this->redactSensitive($arguments),
            'session_id' => $this->currentSessionId,
        ]);
        return null;
    }

    public function afterToolCall(string $name, array $arguments, string $result): void
    {
        $this->log('tool.result', [
            'tool' => $name,
            'agent' => $this->currentAgentName,
            'success' => !str_starts_with($result, '工具执行错误'),
            'result_len' => mb_strlen($result),
            'session_id' => $this->currentSessionId,
        ]);
    }

    /**
     * Redact sensitive fields from data — returns a new array without mutating input.
     */
    private function redactSensitive(array $data): array
    {
        static $lowerSensitiveKeys = null;
        if ($lowerSensitiveKeys === null) {
            $lowerSensitiveKeys = [
                'password', 'passwd', 'pwd',
                'api_key', 'apikey', 'api_secret',
                'secret', 'secret_key', 'access_token', 'refresh_token',
                'token', 'auth_token', 'bearer_token',
                'credit_card', 'card_number', 'cvv',
                'ssn', 'id_card', 'phone', 'mobile', 'email',
                'bank_account', 'iban',
            ];
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->redactSensitive($value);
            } elseif (in_array(strtolower($key), $lowerSensitiveKeys, true)) {
                $result[$key] = '***REDACTED***';
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function log(string $event, array $context): void
    {
        if ($this->auditLogger !== null) {
            ($this->auditLogger)($event, $context);
        }
    }
}
