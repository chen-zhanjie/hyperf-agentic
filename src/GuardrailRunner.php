<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\GuardrailInterface;

class GuardrailRunner
{
    /** @var GuardrailEntry[] */
    private array $entries = [];

    public function register(
        GuardrailInterface $guardrail,
        GuardrailMode $mode = GuardrailMode::SYNC,
    ): void {
        $this->entries[] = new GuardrailEntry($guardrail, $mode);
    }

    /**
     * Load guardrails from class names, replacing any previously registered.
     *
     * Supports two formats:
     *   - String: '\SomeGuardrail' → SYNC mode
     *   - Array:  ['class' => '\SomeGuardrail', 'mode' => 'async'] → explicit mode
     *
     * @param array<class-string<GuardrailInterface>|array{class?: string, mode?: string}> $guardrailConfigs
     */
    public function loadFromConfig(array $guardrailConfigs): void
    {
        $this->entries = [];
        foreach ($guardrailConfigs as $config) {
            if (is_string($config)) {
                $className = $config;
                $mode = GuardrailMode::SYNC;
            } else {
                $className = $config['class'] ?? '';
                $mode = ($config['mode'] ?? 'sync') === 'async'
                    ? GuardrailMode::ASYNC
                    : GuardrailMode::SYNC;
            }

            if (!class_exists($className)) {
                continue;
            }
            if (!is_a($className, GuardrailInterface::class, true)) {
                throw new \InvalidArgumentException(
                    "Class [{$className}] does not implement GuardrailInterface"
                );
            }
            $this->entries[] = new GuardrailEntry(new $className(), $mode);
        }
    }

    /**
     * Run all input guardrails synchronously. Returns the first tripped result, or null if all pass.
     */
    public function checkInput(array $messages): ?GuardrailResult
    {
        foreach ($this->entries as $entry) {
            $result = $entry->guardrail->checkInput($messages);
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
        foreach ($this->entries as $entry) {
            $result = $entry->guardrail->checkOutput($content);
            if ($result->tripwire) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Run input guardrails with async support. SYNC entries execute immediately;
     * ASYNC entries are dispatched to coroutines (if Swoole is available) or
     * fall back to synchronous execution.
     */
    public function checkInputAsync(array $messages): AsyncGuardrailContext
    {
        $ctx = new AsyncGuardrailContext('input');

        foreach ($this->entries as $entry) {
            if ($entry->mode === GuardrailMode::SYNC) {
                $result = $entry->guardrail->checkInput($messages);
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
     * Run output guardrails with async support. Same semantics as checkInputAsync.
     */
    public function checkOutputAsync(string $content): AsyncGuardrailContext
    {
        $ctx = new AsyncGuardrailContext('output');

        foreach ($this->entries as $entry) {
            if ($entry->mode === GuardrailMode::SYNC) {
                $result = $entry->guardrail->checkOutput($content);
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
     * Empty $names returns all guardrails (no filtering).
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
                    return new GuardrailEntry($entry->guardrail, $modes[$name]);
                }
                return $entry;
            },
            $this->entries,
        );
        return $runner;
    }

    /**
     * Dispatch an async guardrail check — uses Swoole coroutine if available,
     * otherwise falls back to synchronous execution.
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
            // No Swoole — execute synchronously
            $result = $check();
            $handle->complete($result->tripwire ? $result : null);
        }
    }
}
