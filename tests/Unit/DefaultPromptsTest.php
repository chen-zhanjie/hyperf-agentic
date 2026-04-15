<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Support\DefaultPrompts;

class DefaultPromptsTest extends TestCase
{
    public function testGetBasePromptReturnsContent(): void
    {
        DefaultPrompts::resetCache();
        $prompt = DefaultPrompts::getBasePrompt();
        $this->assertNotEmpty($prompt);
        $this->assertIsString($prompt);
    }

    public function testGetToolBoundaryPromptReturnsContent(): void
    {
        DefaultPrompts::resetCache();
        $prompt = DefaultPrompts::getToolBoundaryPrompt();
        $this->assertNotEmpty($prompt);
        $this->assertIsString($prompt);
    }

    public function testResetCacheClearsCache(): void
    {
        // Load once to populate cache
        DefaultPrompts::getBasePrompt();
        DefaultPrompts::resetCache();
        // Loading again should still work
        $prompt = DefaultPrompts::getBasePrompt();
        $this->assertNotEmpty($prompt);
    }
}
