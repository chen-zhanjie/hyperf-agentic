<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Guardrail;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\ToolInterface;
use ChenZhanjie\Agentic\Guardrail\SchemaValidationToolGuardrail;
use ChenZhanjie\Agentic\ToolGuardrailResult;
use ChenZhanjie\Agentic\ToolRegistry;

class SchemaValidationToolGuardrailTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    // ── name ──

    public function testNameReturnsSchemaValidation(): void
    {
        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $this->assertSame('schema_validation', $guardrail->name());
    }

    // ── checkToolInput — missing required fields ──

    public function testCheckToolInputBlocksMissingRequiredField(): void
    {
        $tool = $this->createToolWithSchema('search', [
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'query' => ['type' => 'string'],
            ],
        ]);
        $this->registry->register($tool);

        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolInput('search', []);

        $this->assertTrue($result->blocked);
        $this->assertStringContainsString('query', $result->reason);
    }

    public function testCheckToolInputPassesWhenRequiredFieldsPresent(): void
    {
        $tool = $this->createToolWithSchema('search', [
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'query' => ['type' => 'string'],
            ],
        ]);
        $this->registry->register($tool);

        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolInput('search', ['query' => 'hello']);

        $this->assertFalse($result->blocked);
    }

    // ── checkToolInput — type validation ──

    public function testCheckToolInputBlocksWrongType(): void
    {
        $tool = $this->createToolWithSchema('search', [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer'],
            ],
        ]);
        $this->registry->register($tool);

        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolInput('search', ['limit' => 'not_a_number']);

        $this->assertTrue($result->blocked);
        $this->assertStringContainsString('limit', $result->reason);
    }

    public function testCheckToolInputPassesCorrectType(): void
    {
        $tool = $this->createToolWithSchema('search', [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer'],
            ],
        ]);
        $this->registry->register($tool);

        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolInput('search', ['limit' => 10]);

        $this->assertFalse($result->blocked);
    }

    // ── checkToolInput — unregistered tool ──

    public function testCheckToolInputPassesForUnregisteredTool(): void
    {
        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolInput('unknown_tool', ['foo' => 'bar']);

        $this->assertFalse($result->blocked);
    }

    // ── checkToolInput — no schema ──

    public function testCheckToolInputPassesWhenSchemaIsEmpty(): void
    {
        $tool = $this->createToolWithSchema('simple', []);
        $this->registry->register($tool);

        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolInput('simple', ['anything' => 'goes']);

        $this->assertFalse($result->blocked);
    }

    // ── checkToolInput — multiple required fields ──

    public function testCheckToolInputReportsFirstMissingRequired(): void
    {
        $tool = $this->createToolWithSchema('create', [
            'type' => 'object',
            'required' => ['name', 'email', 'age'],
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ]);
        $this->registry->register($tool);

        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolInput('create', ['name' => 'Alice']);

        $this->assertTrue($result->blocked);
        $this->assertStringContainsString('email', $result->reason);
    }

    // ── checkToolInput — type coercion ──

    public function testCheckToolInputPassesIntegerAsStringWhenSchemaIsString(): void
    {
        $tool = $this->createToolWithSchema('search', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
            ],
        ]);
        $this->registry->register($tool);

        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        // LLM sometimes sends numbers as strings and vice versa
        $result = $guardrail->checkToolInput('search', ['query' => 123]);

        // string schema should accept scalar values that can be coerced
        $this->assertFalse($result->blocked);
    }

    // ── checkToolOutput ──

    public function testCheckToolOutputAlwaysPasses(): void
    {
        $guardrail = new SchemaValidationToolGuardrail($this->registry);
        $result = $guardrail->checkToolOutput('any_tool', [], 'any output');

        $this->assertFalse($result->blocked);
    }

    // ── helpers ──

    private function createToolWithSchema(string $name, array $schema): ToolInterface
    {
        return new class($name, $schema) implements ToolInterface {
            public function __construct(
                private readonly string $name,
                private readonly array $schema,
            ) {}
            public function name(): string { return $this->name; }
            public function description(): string { return "Tool {$this->name}"; }
            public function parameters(): array { return $this->schema; }
            public function execute(array $arguments): string|array { return 'ok'; }
            public function isEnabled(): bool { return true; }
            public function isParallelAllowed(): bool { return true; }
        };
    }
}
