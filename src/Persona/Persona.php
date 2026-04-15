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
    ) {}

    public static function fromMarkdownFile(string $path): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("无法读取 SOUL.md: {$path}");
        }
        return self::fromMarkdown($content);
    }

    public static function fromMarkdown(string $content): self
    {
        $lines = explode("\n", $content);
        $name = '';
        $currentSection = '';
        $sections = [
            'role' => '', 'goal' => '', 'backstory' => '',
            'tone' => '', 'principles' => [], 'expertise' => [],
            'boundaries' => [], 'communication' => [],
        ];

        foreach ($lines as $line) {
            // H1 = agent name
            if (preg_match('/^#\s+(.+)$/', $line, $m)) {
                $name = trim($m[1]);
                continue;
            }
            // H2 = section
            if (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $currentSection = strtolower(trim($m[1]));
                // Normalize section names
                $currentSection = match ($currentSection) {
                    'communication style' => 'communication',
                    default => $currentSection,
                };
                continue;
            }
            // List items within array sections
            if ($currentSection !== '' && preg_match('/^-\s+(.+)$/', $line, $m)) {
                $value = trim($m[1]);
                if (in_array($currentSection, ['principles', 'expertise', 'boundaries', 'communication'])) {
                    $sections[$currentSection][] = $value;
                }
                continue;
            }
            // Text within string sections
            if ($currentSection !== '' && trim($line) !== '' && !str_starts_with($line, '#')) {
                if (in_array($currentSection, ['role', 'goal', 'backstory', 'tone'])) {
                    $sections[$currentSection] .= ($sections[$currentSection] !== '' ? "\n" : '') . trim($line);
                }
            }
        }

        return new self(
            name: $name,
            role: $sections['role'],
            goal: $sections['goal'],
            backstory: $sections['backstory'],
            tone: $sections['tone'],
            principles: $sections['principles'],
            expertise: $sections['expertise'],
            boundaries: $sections['boundaries'],
            communication: $sections['communication'],
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
        );
    }

    public function toPromptText(): string
    {
        $parts = [];

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
