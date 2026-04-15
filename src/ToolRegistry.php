<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\ToolInterface;

class ToolRegistry
{
    /** @var array<string, ToolEntry> */
    private array $entries = [];

    public function register(ToolInterface $tool, string $group = 'default', int $maxResultSize = 100000): void
    {
        $name = $tool->name();
        if (isset($this->entries[$name])) {
            throw new \InvalidArgumentException("Tool [{$name}] is already registered");
        }
        $this->entries[$name] = new ToolEntry($tool, $group, $maxResultSize);
    }

    /** Filter by names, returns new instance (immutable) */
    public function only(array $names): self
    {
        $filtered = clone $this;
        $filtered->entries = array_intersect_key($this->entries, array_flip($names));
        return $filtered;
    }

    public function resolve(string $name): ToolInterface
    {
        if (!isset($this->entries[$name])) {
            throw new \InvalidArgumentException("Tool [{$name}] is not registered");
        }
        return $this->entries[$name]->tool;
    }

    public function execute(string $name, array $arguments): ToolExecutionResult
    {
        $entry = $this->entries[$name] ?? null;
        if ($entry === null) {
            return ToolExecutionResult::error($name, "Tool [{$name}] not found");
        }

        try {
            $result = $entry->tool->execute($arguments);
            $text = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string) $result;
            $truncated = false;

            if (mb_strlen($text) > $entry->maxResultSize) {
                $text = mb_substr($text, 0, $entry->maxResultSize)
                    . "\n\n[结果已截断，原始长度: " . mb_strlen($text) . " 字符]";
                $truncated = true;
            }

            return ToolExecutionResult::success($name, $text, $truncated);
        } catch (\Throwable $e) {
            return ToolExecutionResult::error($name, $e->getMessage(), $e);
        }
    }

    public function executeText(string $name, array $arguments): string
    {
        return $this->execute($name, $arguments)->toText();
    }

    public function getAvailableSchemas(): array
    {
        $schemas = [];
        foreach ($this->entries as $name => $entry) {
            if ($entry->tool->isEnabled()) {
                $schemas[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $entry->tool->name(),
                        'description' => $entry->tool->description(),
                        'parameters' => $entry->tool->parameters(),
                    ],
                ];
            }
        }
        return $schemas;
    }

    public function getAvailableNames(): array
    {
        return array_keys(array_filter(
            $this->entries,
            fn(ToolEntry $e) => $e->tool->isEnabled(),
        ));
    }

    public function getAvailableDescriptions(): array
    {
        $descriptions = [];
        foreach ($this->entries as $name => $entry) {
            if ($entry->tool->isEnabled()) {
                $descriptions[$name] = $entry->tool->description();
            }
        }
        return $descriptions;
    }

    public function hasTools(): bool
    {
        return !empty($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
