<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Skill;

use ChenZhanjie\Agentic\Contract\SkillInterface;
use Symfony\Component\Yaml\Yaml;

class Skill implements SkillInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $directory,
        private readonly string $filePath,
        private readonly array $tools = [],
        private readonly bool $autoInvoke = true,
        private readonly bool $userInvocable = true,
    ) {}

    public function name(): string { return $this->name; }
    public function description(): string { return $this->description; }
    public function tools(): array { return $this->tools; }
    public function autoInvoke(): bool { return $this->autoInvoke; }
    public function userInvocable(): bool { return $this->userInvocable; }

    public static function fromMarkdownFile(string $filePath): self
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read SKILL.md: {$filePath}");
        }

        $directory = dirname($filePath);

        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $matches)) {
            throw new \RuntimeException("SKILL.md missing frontmatter: {$filePath}");
        }

        $frontmatter = Yaml::parse($matches[1]);

        $name = $frontmatter['name'] ?? '';
        $description = $frontmatter['description'] ?? '';

        if (empty($name) || empty($description)) {
            throw new \RuntimeException("SKILL.md frontmatter must contain name and description: {$filePath}");
        }

        // Normalize name: underscores/spaces → hyphens
        $name = str_replace(['_', ' '], '-', strtolower($name));
        $dirName = basename($directory);
        if ($name !== $dirName && $name !== str_replace(['_', ' '], '-', $dirName)) {
            throw new \RuntimeException(
                "SKILL.md name [{$name}] does not match directory name [{$dirName}]: {$filePath}"
            );
        }

        return new self(
            name: $name,
            description: $description,
            directory: $directory,
            filePath: $filePath,
            tools: $frontmatter['tools'] ?? [],
            autoInvoke: !($frontmatter['disable-auto-invoke'] ?? false),
            userInvocable: $frontmatter['user-invocable'] ?? true,
        );
    }

    /** Level 1: description line for cached prompt */
    public function toDescriptionLine(): string
    {
        return "- {$this->name}: {$this->description}";
    }

    /** Level 2: full instructions for SkillTool */
    public function toFullInstructions(): string
    {
        $content = file_get_contents($this->filePath);
        $structure = $this->getDirectoryListing();
        if (!empty($structure)) {
            $content .= "\n\n## 可用资源文件\n以下文件可通过 skill 工具的 resource 参数加载：\n{$structure}";
        }
        return $content;
    }

    /** Level 3: load bundled resource */
    public function loadResource(string $relativePath): ?string
    {
        $realPath = realpath($this->directory . '/' . $relativePath);
        if ($realPath === false || !str_starts_with($realPath, realpath($this->directory))) {
            return null;
        }
        if (!is_file($realPath) || !is_readable($realPath)) {
            return null;
        }
        return file_get_contents($realPath);
    }

    public function getDirectoryListing(): string
    {
        $allowedDirs = ['scripts', 'references', 'assets'];
        $lines = [];

        foreach ($allowedDirs as $subDir) {
            $path = $this->directory . '/' . $subDir;
            if (!is_dir($path)) {
                continue;
            }
            $lines[] = "\n### {$subDir}/";
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
                $iterator->setMaxDepth(3);
                foreach ($iterator as $fileInfo) {
                    if ($fileInfo->isFile()) {
                        $rel = str_replace($path . '/', '', $fileInfo->getPathname());
                        $lines[] = "  - {$subDir}/{$rel} (" . $this->formatSize($fileInfo->getSize()) . ")";
                    }
                }
            } catch (\Throwable $e) {
                $lines[] = "  (无法读取目录: {$e->getMessage()})";
            }
        }

        return implode("\n", $lines);
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . 'B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . 'KB';
        return round($bytes / 1048576, 1) . 'MB';
    }
}
