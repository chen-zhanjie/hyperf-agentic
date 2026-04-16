<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Guardrail;

use ChenZhanjie\Agentic\Contract\ToolGuardrailInterface;
use ChenZhanjie\Agentic\ToolGuardrailResult;
use ChenZhanjie\Agentic\ToolRegistry;

/**
 * Validates tool call arguments against their declared JSON Schema.
 *
 * Checks:
 *   - Required fields are present
 *   - Basic type matching (string, integer, number, boolean, array, object)
 */
class SchemaValidationToolGuardrail implements ToolGuardrailInterface
{
    public function __construct(
        private readonly ToolRegistry $registry,
    ) {}

    public function name(): string
    {
        return 'schema_validation';
    }

    public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
    {
        if (!$this->registry->has($toolName)) {
            return ToolGuardrailResult::ok();
        }

        $tool = $this->registry->resolve($toolName);
        $schema = $tool->parameters();

        if (empty($schema)) {
            return ToolGuardrailResult::ok();
        }

        // Check required fields
        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $arguments)) {
                return ToolGuardrailResult::blocked("Missing required field: {$field}");
            }
        }

        // Check types
        $properties = $schema['properties'] ?? [];
        foreach ($properties as $field => $fieldSchema) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }

            $expectedType = $fieldSchema['type'] ?? null;
            if ($expectedType === null) {
                continue;
            }

            $value = $arguments[$field];
            if (!$this->matchesType($value, $expectedType)) {
                return ToolGuardrailResult::blocked("Field '{$field}' expects type {$expectedType}, got " . gettype($value));
            }
        }

        return ToolGuardrailResult::ok();
    }

    public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
    {
        return ToolGuardrailResult::ok();
    }

    /**
     * Check if a value matches the expected JSON Schema type.
     */
    private function matchesType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string'  => is_string($value) || is_numeric($value),
            'integer' => is_int($value),
            'number'  => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array'   => is_array($value) && array_is_list($value),
            'object'  => is_array($value) && !array_is_list($value),
            default   => true,
        };
    }
}
