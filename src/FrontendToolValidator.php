<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

/**
 * Validates frontend tool schemas — security boundary.
 * Prevents: name collisions, oversized schemas, malicious inputs.
 */
class FrontendToolValidator
{
    public const ERR_TOOL_COUNT_EXCEEDED = 'Frontend tool count exceeds limit (max %d)';
    public const ERR_MISSING_FIELDS = 'Tool missing required fields (name/description/parameters)';
    public const ERR_INVALID_NAME_FORMAT = 'Tool name [%s] has invalid format';
    public const ERR_DUPLICATE_NAME = 'Tool name [%s] is duplicated';
    public const ERR_RESERVED_NAME = 'Tool name [%s] conflicts with a backend tool';
    public const ERR_NOT_IN_WHITELIST = 'Tool name [%s] is not in the whitelist';
    public const ERR_DESCRIPTION_TOO_LONG = 'Tool [%s] description exceeds max length';
    public const ERR_PARAMS_NOT_OBJECT = "Tool [%s] parameters.type must be 'object'";
    public const ERR_TOO_MANY_PARAMS = 'Tool [%s] has too many parameters';
    public const ERR_SCHEMA_TOO_DEEP = 'Tool [%s] parameter schema nesting is too deep';

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
            return ['valid' => [], 'errors' => [sprintf(self::ERR_TOOL_COUNT_EXCEEDED, $this->maxCount)]];
        }

        foreach ($schemas as $schema) {
            // Required fields
            if (!isset($schema['name'], $schema['description'], $schema['parameters'])) {
                $errors[] = self::ERR_MISSING_FIELDS;
                continue;
            }

            $name = (string) $schema['name'];

            // Name format: letter start, alphanumeric + underscore/dash, max 64
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $name)) {
                $errors[] = sprintf(self::ERR_INVALID_NAME_FORMAT, $name);
                continue;
            }

            // Duplicate name check
            if (in_array($name, $seenNames, true)) {
                $errors[] = sprintf(self::ERR_DUPLICATE_NAME, $name);
                continue;
            }
            $seenNames[] = $name;

            // No conflict with backend tools
            if (in_array($name, $this->reservedNames, true)) {
                $errors[] = sprintf(self::ERR_RESERVED_NAME, $name);
                continue;
            }

            // Whitelist check
            if (!empty($this->allowedNames) && !in_array($name, $this->allowedNames, true)) {
                $errors[] = sprintf(self::ERR_NOT_IN_WHITELIST, $name);
                continue;
            }

            // Description length
            if (mb_strlen((string) $schema['description']) > $this->maxDescLength) {
                $errors[] = sprintf(self::ERR_DESCRIPTION_TOO_LONG, $name);
                continue;
            }

            // Parameters must be object type
            if (($schema['parameters']['type'] ?? '') !== 'object') {
                $errors[] = sprintf(self::ERR_PARAMS_NOT_OBJECT, $name);
                continue;
            }

            // Param count
            $propCount = count($schema['parameters']['properties'] ?? []);
            if ($propCount > $this->maxParams) {
                $errors[] = sprintf(self::ERR_TOO_MANY_PARAMS, $name);
                continue;
            }

            // Schema depth
            if ($this->measureDepth($schema['parameters']) > $this->maxDepth) {
                $errors[] = sprintf(self::ERR_SCHEMA_TOO_DEEP, $name);
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
