<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Support;

class DefaultPrompts
{
    private static ?string $basePrompt = null;
    private static ?string $toolBoundaryPrompt = null;

    public static function getBasePrompt(): string
    {
        if (self::$basePrompt === null) {
            $path = __DIR__ . '/../../resources/prompts/base.md';
            self::$basePrompt = file_exists($path) ? file_get_contents($path) : '';
        }
        return self::$basePrompt;
    }

    public static function getToolBoundaryPrompt(): string
    {
        if (self::$toolBoundaryPrompt === null) {
            $path = __DIR__ . '/../../resources/prompts/tool_boundary.md';
            self::$toolBoundaryPrompt = file_exists($path) ? file_get_contents($path) : '';
        }
        return self::$toolBoundaryPrompt;
    }

    public static function resetCache(): void
    {
        self::$basePrompt = null;
        self::$toolBoundaryPrompt = null;
    }
}
