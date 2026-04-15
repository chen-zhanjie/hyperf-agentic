<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Persona;

class PersonaLoader
{
    /** @var string[] Allowed directories for SOUL.md files */
    private readonly array $allowedDirs;

    public function __construct()
    {
        $this->allowedDirs = array_filter([
            realpath(BASE_PATH . '/config/autoload/agentic/souls') ?: null,
            realpath(__DIR__ . '/../../resources/souls') ?: null,
        ]);
    }

    public function load(mixed $personaConfig): Persona
    {
        // 1. File path (.md ending)
        if (is_string($personaConfig) && str_ends_with($personaConfig, '.md')) {
            return $this->loadFromMarkdownFile($personaConfig);
        }

        // 2. Plain string — use as content
        if (is_string($personaConfig)) {
            return new Persona(name: 'assistant', content: $personaConfig);
        }

        // 3. Array config
        if (is_array($personaConfig)) {
            return Persona::fromArray($personaConfig);
        }

        // 4. null — default persona
        return $this->loadDefault();
    }

    private function loadFromMarkdownFile(string $path): Persona
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new \InvalidArgumentException("SOUL.md file not found: {$path}");
        }

        $isAllowed = false;
        foreach ($this->allowedDirs as $dir) {
            if (str_starts_with($realPath, $dir)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            throw new \InvalidArgumentException(
                "SOUL.md file must be in an allowed directory. Path: {$path}, allowed: " . implode(', ', $this->allowedDirs)
            );
        }

        return Persona::fromMarkdownFile($realPath);
    }

    private function loadDefault(): Persona
    {
        $defaultPath = __DIR__ . '/../../resources/souls/default.md';
        if (file_exists($defaultPath)) {
            return Persona::fromMarkdownFile($defaultPath);
        }
        return new Persona(name: 'assistant', content: 'You are a helpful AI assistant.');
    }
}
