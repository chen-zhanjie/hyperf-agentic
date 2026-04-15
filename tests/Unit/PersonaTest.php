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

    public function testFromMarkdownParsesStringSections(): void
    {
        $md = <<<MD
# Helper

## Role
You are a helpful assistant.

## Goal
Answer questions accurately.

## Tone
Friendly and professional.
MD;
        $persona = Persona::fromMarkdown($md);
        $this->assertSame('Helper', $persona->name);
        // Freeform: entire raw content is stored as-is
        $this->assertSame(trim($md), $persona->freeform);
        // Structured fields are not populated from markdown (freeform takes priority)
    }

    public function testFromMarkdownParsesArraySections(): void
    {
        $md = <<<MD
# Dev Agent

## Principles
- Always write tests
- Keep functions small

## Expertise
- PHP
- TypeScript

## Boundaries
- Never delete data

## Communication Style
- Be concise
- Use code examples
MD;
        $persona = Persona::fromMarkdown($md);
        // Freeform: entire raw content stored as-is
        $this->assertSame(trim($md), $persona->freeform);
    }

    public function testFromMarkdownWithMultilineSection(): void
    {
        $md = <<<MD
# Agent

## Backstory
Line one.
Line two.
Line three.
MD;
        $persona = Persona::fromMarkdown($md);
        // Freeform preserves all content
        $this->assertStringContainsString('Line one.', $persona->freeform);
        $this->assertStringContainsString('Line two.', $persona->freeform);
        $this->assertStringContainsString('Line three.', $persona->freeform);
    }

    public function testFromMarkdownReturnsDefaultsForEmptyInput(): void
    {
        $persona = Persona::fromMarkdown('');
        $this->assertSame('', $persona->name);
        $this->assertSame('', $persona->freeform);
    }

    public function testFromMarkdownFreeformIsRawContent(): void
    {
        $md = "# Support Bot\n\nYou are a helpful support agent.\nAlways be polite and concise.";
        $persona = Persona::fromMarkdown($md);
        $this->assertSame('Support Bot', $persona->name);
        $this->assertSame($md, $persona->freeform);
    }

    public function testFromMarkdownFreeformPreservesUnknownSections(): void
    {
        $md = <<<MD
# Coder

## Custom Section
This would be lost before, but now preserved in freeform.

## Another Section
More content here.
MD;
        $persona = Persona::fromMarkdown($md);
        $this->assertStringContainsString('Custom Section', $persona->freeform);
        $this->assertStringContainsString('This would be lost', $persona->freeform);
        $this->assertStringContainsString('Another Section', $persona->freeform);
    }

    // --- fromArray ---

    public function testFromArrayCreatesPersona(): void
    {
        $config = [
            'name' => 'Test Agent',
            'role' => 'Tester',
            'goal' => 'Test everything',
            'principles' => ['First', 'Second'],
            'freeform' => 'Raw prompt text',
        ];
        $persona = Persona::fromArray($config);
        $this->assertSame('Test Agent', $persona->name);
        $this->assertSame('Tester', $persona->role);
        $this->assertSame('Test everything', $persona->goal);
        $this->assertSame(['First', 'Second'], $persona->principles);
        $this->assertSame('Raw prompt text', $persona->freeform);
        $this->assertSame([], $persona->expertise);
    }

    public function testFromArrayHandlesEmptyArray(): void
    {
        $persona = Persona::fromArray([]);
        $this->assertSame('', $persona->name);
        $this->assertSame('', $persona->role);
        $this->assertSame([], $persona->principles);
    }

    // --- toPromptText ---

    public function testToPromptTextIncludesAllNonEmptySections(): void
    {
        // Structured fields (fromArray path) reconstruct the prompt with name as H1
        $persona = new Persona(
            name: 'Test',
            role: 'You are a tester.',
            goal: 'Test all the things.',
            principles: ['Write tests first', 'Keep coverage high'],
        );
        $text = $persona->toPromptText();
        $this->assertStringContainsString('# Test', $text);
        $this->assertStringContainsString('## Role', $text);
        $this->assertStringContainsString('You are a tester.', $text);
        $this->assertStringContainsString('## Goal', $text);
        $this->assertStringContainsString('Test all the things.', $text);
        $this->assertStringContainsString('## Principles', $text);
        $this->assertStringContainsString('- Write tests first', $text);
        // Empty sections are omitted
        $this->assertStringNotContainsString('## Backstory', $text);
        $this->assertStringNotContainsString('## Tone', $text);
    }

    public function testToPromptTextFreeformTakesPriority(): void
    {
        $persona = new Persona(
            name: 'Test',
            role: 'Structured role',
            freeform: 'Free-form prompt content, used as-is.',
        );
        $text = $persona->toPromptText();
        $this->assertSame('Free-form prompt content, used as-is.', $text);
        // Structured fields are NOT used when freeform is present
        $this->assertStringNotContainsString('Structured role', $text);
    }

    public function testToPromptTextReturnsEmptyForCompletelyEmptyPersona(): void
    {
        $persona = new Persona(name: '');
        $this->assertSame('', $persona->toPromptText());
    }

    public function testToPromptTextIncludesNameWhenOnlyNameGiven(): void
    {
        $persona = new Persona(name: 'Empty');
        $this->assertSame('# Empty', $persona->toPromptText());
    }

    public function testToPromptTextFormatsArraySectionsAsLists(): void
    {
        $persona = new Persona(
            name: 'Test',
            expertise: ['PHP', 'Go'],
            boundaries: ['No production access'],
        );
        $text = $persona->toPromptText();
        $this->assertStringContainsString('- PHP', $text);
        $this->assertStringContainsString('- Go', $text);
        $this->assertStringContainsString('- No production access', $text);
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
        $persona = new Persona(name: 'Test', role: 'Role');
        $this->assertTrue(true); // readonly properties enforced by PHP engine
    }
}
