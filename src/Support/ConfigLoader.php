<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Support;

use Symfony\Component\Yaml\Yaml;

class ConfigLoader
{
    /**
     * Load config from a PHP file (returns array) or YAML file.
     */
    public static function loadFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'php' => (array) require($path),
            'yaml', 'yml' => (array) Yaml::parse(file_get_contents($path)),
            default => [],
        };
    }

    /**
     * Load all config files from a directory, keyed by filename.
     */
    public static function loadDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $config = [];
        foreach (glob($dir . '/*.{php,yaml,yml}', GLOB_BRACE) ?: [] as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $config[$key] = self::loadFile($file);
        }

        return $config;
    }

    /**
     * Deep merge two config arrays. $override takes precedence.
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
