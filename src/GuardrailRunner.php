<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\GuardrailAuditLoggerInterface;
use ChenZhanjie\Agentic\Contract\GuardrailInterface;

class GuardrailRunner
{
    /** @var GuardrailEntry[] */
    private array $entries = [];

    private bool $sorted = true;

    public function __construct(
        private readonly ?GuardrailAuditLoggerInterface $auditLogger = null,
    ) {}

    public function register(
        GuardrailInterface $guardrail,
        GuardrailMode $mode = GuardrailMode::SYNC,
        int $priority = 0,
    ): void {
        $this->entries[] = new GuardrailEntry($guardrail, $mode, $priority);
        $this->sorted = false;
    }

    /**
     * Load guardrails from class names, replacing any previously registered.
     *
     * Supports formats:
     *   - String: '\SomeGuardrail' → SYNC mode, priority 0
     *   - Array:  ['class' => X, 'mode' => 'async', 'priority' => 100]
     *
     * @param array<class-string<GuardrailInterface>|array{class?: string, mode?: string, priority?: int}> $guardrailConfigs
     */
    public function loadFromConfig(array $guardrailConfigs): void
    {
        $this->entries = [];
        $this->sorted = true;
        foreach ($guardrailConfigs as $config) {
            if (is_string($config)) {
                $className = $config;
                $mode = GuardrailMode::SYNC;
                $priority = 0;
            } else {
                $className = $config['class'] ?? '';
                $mode = ($config['mode'] ?? 'sync') === 'async'
                    ? GuardrailMode::ASYNC
                    : GuardrailMode::SYNC;
                $priority = (int) ($config['priority'] ?? 0);
            }

            if (!class_exists($className)) {
                continue;
            }
            if (!is_a($className, GuardrailInterface::class, true)) {
                throw new \InvalidArgumentException(
                    "Class [{$className}] does not implement GuardrailInterface"
                );
            }
            $this->entries[] = new GuardrailEntry(new $className(), $mode, $priority);
        }
        // Sort by priority if any entry has non-zero priority
        $hasPriority = false;
        foreach ($this->entries as $entry) {
            if ($entry->priority !== 0) {
                $hasPriority = true;
                break;
            }
        }
        if ($hasPriority) {
            usort($this->entries, fn(GuardrailEntry $a, GuardrailEntry $b) => $b->priority <=> $a->priority);
        }
    }

    /**
     * Run all input guardrails synchronously. Returns the first tripped result, or null if all pass.
     */
    public function checkInput(array $messages): ?GuardrailResult
    {
        $this->ensureSorted();

        foreach ($this->entries as $entry) {
            $start = hrtime(true);
            $result = $entry->guardrail->checkInput($messages);
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            $this->audit($entry->guardrail->name(), 'input', $result->tripwire ? 'blocked' : 'pass', $result->tripwire ? $result->reason : '', $durationMs);

            if ($result->tripwire) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Run all output guardrails synchronously. Returns the first tripped result, or null if all pass.
     */
    public function checkOutput(string $content): ?GuardrailResult
    {
        $this->ensureSorted();

        foreach ($this->entries as $entry) {
            $start = hrtime(true);
            $result = $entry->guardrail->checkOutput($content);
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            $this->audit($entry->guardrail->name(), 'output', $result->tripwire ? 'blocked' : 'pass', $result->tripwire ? $result->reason : '', $durationMs);

            if ($result->tripwire) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Run input guardrails with async support.
     */
    public function checkInputAsync(array $messages): AsyncGuardrailContext
    {
        $this->ensureSorted();
        $ctx = new AsyncGuardrailContext('input');

        foreach ($this->entries as $entry) {
            if ($entry->mode === GuardrailMode::SYNC) {
                $start = hrtime(true);
                $result = $entry->guardrail->checkInput($messages);
                $durationMs = (hrtime(true) - $start) / 1_000_000;

                $this->audit($entry->guardrail->name(), 'input', $result->tripwire ? 'blocked' : 'pass', $result->tripwire ? $result->reason : '', $durationMs);

                if ($result->tripwire) {
                    $ctx->setSyncResult($result);
                    break;
                }
            } else {
                $this->dispatchAsync(
                    $ctx,
                    $entry->guardrail->name(),
                    fn () => $entry->guardrail->checkInput($messages),
                );
            }
        }

        return $ctx;
    }

    /**
     * Run output guardrails with async support.
     */
    public function checkOutputAsync(string $content): AsyncGuardrailContext
    {
        $this->ensureSorted();
        $ctx = new AsyncGuardrailContext('output');

        foreach ($this->entries as $entry) {
            if ($entry->mode === GuardrailMode::SYNC) {
                $start = hrtime(true);
                $result = $entry->guardrail->checkOutput($content);
                $durationMs = (hrtime(true) - $start) / 1_000_000;

                $this->audit($entry->guardrail->name(), 'output', $result->tripwire ? 'blocked' : 'pass', $result->tripwire ? $result->reason : '', $durationMs);

                if ($result->tripwire) {
                    $ctx->setSyncResult($result);
                    break;
                }
            } else {
                $this->dispatchAsync(
                    $ctx,
                    $entry->guardrail->name(),
                    fn () => $entry->guardrail->checkOutput($content),
                );
            }
        }

        return $ctx;
    }

    /**
     * Filter guardrails by names, returns new instance (immutable).
     *
     * @param string[] $names Guardrail names to keep
     */
    public function only(array $names): self
    {
        if (empty($names)) {
            return clone $this;
        }

        $filtered = clone $this;
        $filtered->entries = array_filter(
            $this->entries,
            fn(GuardrailEntry $e) => in_array($e->guardrail->name(), $names, true),
        );
        return $filtered;
    }

    /**
     * Return a new runner with mode overrides applied by guardrail name.
     *
     * @param array<string, GuardrailMode> $modes Map of guardrail name → mode
     */
    public function withModes(array $modes): self
    {
        $runner = clone $this;
        $runner->entries = array_map(
            function (GuardrailEntry $entry) use ($modes): GuardrailEntry {
                $name = $entry->guardrail->name();
                if (isset($modes[$name])) {
                    return new GuardrailEntry($entry->guardrail, $modes[$name], $entry->priority);
                }
                return $entry;
            },
            $this->entries,
        );
        return $runner;
    }

    /**
     * Sort entries by priority (descending — higher priority executes first).
     */
    private function ensureSorted(): void
    {
        if ($this->sorted) {
            return;
        }

        usort($this->entries, fn(GuardrailEntry $a, GuardrailEntry $b) => $b->priority <=> $a->priority);
        $this->sorted = true;
    }

    /**
     * Record an audit entry if audit logger is configured.
     */
    private function audit(string $name, string $phase, string $decision, string $reason, float $durationMs): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->log(new GuardrailAuditEntry(
            guardrailName: $name,
            phase: $phase,
            decision: $decision,
            reason: $reason,
            durationMs: $durationMs,
        ));
    }

    /**
     * Dispatch an async guardrail check.
     */
    private function dispatchAsync(
        AsyncGuardrailContext $ctx,
        string $name,
        callable $check,
    ): void {
        $handle = new AsyncGuardrailHandle();
        $ctx->addHandle($handle, $name);

        if (class_exists(\Swoole\Coroutine::class)) {
            \Swoole\Coroutine::create(function () use ($handle, $check): void {
                $result = $check();
                $handle->complete($result->tripwire ? $result : null);
            });
        } else {
            $result = $check();
            $handle->complete($result->tripwire ? $result : null);
        }
    }
}
