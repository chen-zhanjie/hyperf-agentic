<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Persona;

class Persona
{
    public function __construct(
        public readonly string $name,
        public readonly string $content = '',
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
     * Extracts name from first H1, stores the entire raw content.
     */
    public static function fromMarkdown(string $content): self
    {
        $name = '';
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $name = trim($m[1]);
        }

        return new self(
            name: $name,
            content: trim($content),
        );
    }

    public static function fromArray(array $config): self
    {
        return new self(
            name: $config['name'] ?? '',
            content: $config['content'] ?? '',
        );
    }

    public function toPromptText(): string
    {
        if ($this->content !== '') {
            return $this->content;
        }

        if ($this->name !== '') {
            return "# {$this->name}";
        }

        return '';
    }
}