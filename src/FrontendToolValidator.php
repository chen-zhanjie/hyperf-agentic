<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Validates frontend tool schemas — security boundary.
 * Prevents: name collisions, oversized schemas, malicious inputs.
 */
class FrontendToolValidator
{
    /**
     * @param array<string> $reservedNames Backend tool names (frontend cannot override)
     * @param array<string> $allowedNames Whitelist (empty = allow all valid names)
     * @param int $maxCount Max frontend tools per request
     * @param int $maxDescLength Max description length
     * @param int $maxParams Max parameter count per tool
     * @param int $maxDepth Max schema nesting depth
     */
    public function __construct(
        private readonly array $reservedNames = [],
        private readonly array $allowedNames = [],
        private readonly int $maxCount = 10,
        private readonly int $maxDescLength = 500,
        private readonly int $maxParams = 20,
        private readonly int $maxDepth = 3,
    ) {}

    /**
     * @param array $schemas Array of frontend tool JSON schemas
     * @return array{valid: array, errors: array<string>}
     */
    public function validate(array $schemas): array
    {
        $valid = [];
        $errors = [];
        $seenNames = [];

        if (count($schemas) > $this->maxCount) {
            return ['valid' => [], 'errors' => ["前端工具数量超过限制（最多 {$this->maxCount} 个）"]];
        }

        foreach ($schemas as $schema) {
            // Required fields
            if (!isset($schema['name'], $schema['description'], $schema['parameters'])) {
                $errors[] = '工具缺少必要字段 (name/description/parameters)';
                continue;
            }

            $name = (string) $schema['name'];

            // Name format: letter start, alphanumeric + underscore/dash, max 64
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $name)) {
                $errors[] = "工具名 [{$name}] 格式无效";
                continue;
            }

            // Duplicate name check
            if (in_array($name, $seenNames, true)) {
                $errors[] = "工具名 [{$name}] 重复";
                continue;
            }
            $seenNames[] = $name;

            // No conflict with backend tools
            if (in_array($name, $this->reservedNames, true)) {
                $errors[] = "工具名 [{$name}] 与后端工具冲突";
                continue;
            }

            // Whitelist check
            if (!empty($this->allowedNames) && !in_array($name, $this->allowedNames, true)) {
                $errors[] = "工具名 [{$name}] 未在白名单中";
                continue;
            }

            // Description length
            if (mb_strlen((string) $schema['description']) > $this->maxDescLength) {
                $errors[] = "工具 [{$name}] 描述过长";
                continue;
            }

            // Parameters must be object type
            if (($schema['parameters']['type'] ?? '') !== 'object') {
                $errors[] = "工具 [{$name}] parameters.type 必须为 'object'";
                continue;
            }

            // Param count
            $propCount = count($schema['parameters']['properties'] ?? []);
            if ($propCount > $this->maxParams) {
                $errors[] = "工具 [{$name}] 参数过多";
                continue;
            }

            // Schema depth
            if ($this->measureDepth($schema['parameters']) > $this->maxDepth) {
                $errors[] = "工具 [{$name}] 参数 schema 嵌套过深";
                continue;
            }

            $valid[] = $schema;
        }

        return ['valid' => $valid, 'errors' => $errors];
    }

    private function measureDepth(array $schema, int $current = 0): int
    {
        $max = $current;
        foreach ($schema['properties'] ?? [] as $prop) {
            if (isset($prop['properties']) && is_array($prop['properties'])) {
                $max = max($max, $this->measureDepth($prop, $current + 1));
            }
        }
        if (isset($schema['items']['properties'])) {
            $max = max($max, $this->measureDepth($schema['items'], $current + 1));
        }
        return $max;
    }
}
