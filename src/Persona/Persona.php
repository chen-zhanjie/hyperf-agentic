<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Persona;

class Persona
{
    public function __construct(
        public readonly string $name,
        public readonly string $role = '',
        public readonly string $goal = '',
        public readonly string $backstory = '',
        public readonly string $tone = '',
        public readonly array $principles = [],
        public readonly array $expertise = [],
        public readonly array $boundaries = [],
        public readonly array $communication = [],
        public readonly string $freeform = '',
    ) {}

    public static function fromMarkdownFile(string $path): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read SOUL.md: {$path}");
        }
        return self::fromMarkdown($content);
    }

    /**
     * Parse markdown into Persona.
     * Extracts name from H1, stores the entire raw content as freeform.
     * Structured fields are populated for backward compatibility with fromArray().
     */
    public static function fromMarkdown(string $content): self
    {
        // Extract name from first H1
        $name = '';
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $name = trim($m[1]);
        }

        return new self(
            name: $name,
            freeform: trim($content),
        );
    }

    public static function fromArray(array $config): self
    {
        return new self(
            name: $config['name'] ?? '',
            role: $config['role'] ?? '',
            goal: $config['goal'] ?? '',
            backstory: $config['backstory'] ?? '',
            tone: $config['tone'] ?? '',
            principles: $config['principles'] ?? [],
            expertise: $config['expertise'] ?? [],
            boundaries: $config['boundaries'] ?? [],
            communication: $config['communication'] ?? [],
            freeform: $config['freeform'] ?? '',
        );
    }

    public function toPromptText(): string
    {
        // Freeform: raw markdown, used as-is (already includes H1)
        if ($this->freeform !== '') {
            return $this->freeform;
        }

        // Structured: reconstruct from fields with agent name as H1
        $parts = [];

        if ($this->name !== '') {
            $parts[] = "# {$this->name}";
        }

        if ($this->role !== '') {
            $parts[] = "## Role\n{$this->role}";
        }
        if ($this->goal !== '') {
            $parts[] = "## Goal\n{$this->goal}";
        }
        if ($this->backstory !== '') {
            $parts[] = "## Backstory\n{$this->backstory}";
        }
        if ($this->tone !== '') {
            $parts[] = "## Tone\n{$this->tone}";
        }
        if (!empty($this->principles)) {
            $parts[] = "## Principles\n" . implode("\n", array_map(fn($p) => "- {$p}", $this->principles));
        }
        if (!empty($this->expertise)) {
            $parts[] = "## Expertise\n" . implode("\n", array_map(fn($e) => "- {$e}", $this->expertise));
        }
        if (!empty($this->boundaries)) {
            $parts[] = "## Boundaries\n" . implode("\n", array_map(fn($b) => "- {$b}", $this->boundaries));
        }
        if (!empty($this->communication)) {
            $parts[] = "## Communication Style\n" . implode("\n", array_map(fn($c) => "- {$c}", $this->communication));
        }

        return implode("\n\n", $parts);
    }
}
