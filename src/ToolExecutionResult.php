<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class ToolExecutionResult
{
    private function __construct(
        public readonly string $toolName,
        public readonly bool $success,
        public readonly string $content,
        public readonly bool $truncated = false,
        public readonly ?\Throwable $exception = null,
    ) {}

    public static function success(string $name, string $content, bool $truncated = false): self
    {
        return new self($name, true, $content, $truncated);
    }

    public static function error(string $name, string $message, ?\Throwable $e = null): self
    {
        return new self($name, false, "工具执行错误 [{$name}]: {$message}", exception: $e);
    }

    public function toText(): string
    {
        return $this->content;
    }
}
