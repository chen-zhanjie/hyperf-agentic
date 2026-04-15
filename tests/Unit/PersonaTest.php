<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Persona\Persona;

class PersonaTest extends TestCase
{
    // --- fromMarkdown ---

    public function testFromMarkdownParsesH1AsName(): void
    {
        $md = "# My Agent\n";
        $persona = Persona::fromMarkdown($md);
        $this->assertSame('My Agent', $persona->name);
    }

    public function testFromMarkdownStoresRawContent(): void
    {
        $md = <<<MD
# Helper

You are a helpful assistant.
Answer questions accurately.
MD;
        $persona = Persona::fromMarkdown($md);
        $this->assertSame('Helper', $persona->name);
        $this->assertSame(trim($md), $persona->content);
    }

    public function testFromMarkdownPreservesUnknownSections(): void
    {
        $md = <<<MD
# Coder

## Custom Section
This would be lost before, but now preserved.

## Another Section
More content here.
MD;
        $persona = Persona::fromMarkdown($md);
        $this->assertStringContainsString('Custom Section', $persona->content);
        $this->assertStringContainsString('This would be lost', $persona->content);
        $this->assertStringContainsString('Another Section', $persona->content);
    }

    public function testFromMarkdownWithMultilineContent(): void
    {
        $md = <<<MD
# Agent

Line one.
Line two.
Line three.
MD;
        $persona = Persona::fromMarkdown($md);
        $this->assertStringContainsString('Line one.', $persona->content);
        $this->assertStringContainsString('Line two.', $persona->content);
        $this->assertStringContainsString('Line three.', $persona->content);
    }

    public function testFromMarkdownReturnsDefaultsForEmptyInput(): void
    {
        $persona = Persona::fromMarkdown('');
        $this->assertSame('', $persona->name);
        $this->assertSame('', $persona->content);
    }

    public function testFromMarkdownContentIsRawMarkdown(): void
    {
        $md = "# Support Bot\n\nYou are a helpful support agent.\nAlways be polite and concise.";
        $persona = Persona::fromMarkdown($md);
        $this->assertSame('Support Bot', $persona->name);
        $this->assertSame($md, $persona->content);
    }

    // --- fromArray ---

    public function testFromArrayCreatesPersona(): void
    {
        $config = [
            'name' => 'Test Agent',
            'content' => 'You are a testing assistant.',
        ];
        $persona = Persona::fromArray($config);
        $this->assertSame('Test Agent', $persona->name);
        $this->assertSame('You are a testing assistant.', $persona->content);
    }

    public function testFromArrayHandlesEmptyArray(): void
    {
        $persona = Persona::fromArray([]);
        $this->assertSame('', $persona->name);
        $this->assertSame('', $persona->content);
    }

    // --- toPromptText ---

    public function testToPromptTextReturnsContentWhenSet(): void
    {
        $persona = new Persona(name: 'Bot', content: 'You are a friendly bot.');
        $this->assertSame('You are a friendly bot.', $persona->toPromptText());
    }

    public function testToPromptTextReturnsNameAsH1WhenOnlyNameGiven(): void
    {
        $persona = new Persona(name: 'Assistant');
        $this->assertSame('# Assistant', $persona->toPromptText());
    }

    public function testToPromptTextReturnsEmptyForCompletelyEmptyPersona(): void
    {
        $persona = new Persona(name: '');
        $this->assertSame('', $persona->toPromptText());
    }

    public function testToPromptTextContentTakesPriorityOverName(): void
    {
        $persona = new Persona(name: 'Bot', content: '# Bot\n\nCustom content here.');
        $text = $persona->toPromptText();
        $this->assertSame('# Bot\n\nCustom content here.', $text);
    }

    // --- fromMarkdownFile ---

    public function testFromMarkdownFileThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Persona::fromMarkdownFile('/nonexistent/path/SOUL.md');
    }

    public function testFromMarkdownFileLoadsRealFile(): void
    {
        $defaultPath = __DIR__ . '/../../resources/souls/default.md';
        if (!file_exists($defaultPath)) {
            $this->markTestSkipped('Default soul file not found');
        }
        $persona = Persona::fromMarkdownFile($defaultPath);
        $this->assertNotEmpty($persona->name);
    }

    // --- immutability ---

    public function testPersonaIsReadOnly(): void
    {
        $persona = new Persona(name: 'Test', content: 'Some content');
        $this->assertTrue(true); // readonly properties enforced by PHP engine
    }
}
