<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\FrontendToolValidator;

class FrontendToolValidatorTest extends TestCase
{
    private function makeValidator(array $overrides = []): FrontendToolValidator
    {
        return new FrontendToolValidator(
            reservedNames: $overrides['reservedNames'] ?? ['search', 'ask'],
            allowedNames: $overrides['allowedNames'] ?? [],
            maxCount: $overrides['maxCount'] ?? 10,
            maxDescLength: $overrides['maxDescLength'] ?? 500,
            maxParams: $overrides['maxParams'] ?? 20,
            maxDepth: $overrides['maxDepth'] ?? 3,
        );
    }

    private function validSchema(string $name = 'render_chart'): array
    {
        return [
            'name' => $name,
            'description' => 'Render a chart',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string'],
                ],
            ],
        ];
    }

    public function testValidSchemaPasses(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->validate([$this->validSchema()]);

        $this->assertCount(1, $result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testRejectsMissingFields(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->validate([['name' => 'test']]);

        $this->assertEmpty($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testRejectsInvalidNameFormat(): void
    {
        $validator = $this->makeValidator();
        $schema = $this->validSchema('123invalid');
        $result = $validator->validate([$schema]);

        $this->assertEmpty($result['valid']);
    }

    public function testRejectsReservedNameCollision(): void
    {
        $validator = $this->makeValidator();
        $schema = $this->validSchema('search'); // reserved
        $result = $validator->validate([$schema]);

        $this->assertEmpty($result['valid']);
    }

    public function testRejectsExceedsMaxCount(): void
    {
        $validator = $this->makeValidator(['maxCount' => 1]);
        $result = $validator->validate([$this->validSchema('a'), $this->validSchema('b')]);

        $this->assertEmpty($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testRejectsOversizedDescription(): void
    {
        $validator = $this->makeValidator(['maxDescLength' => 5]);
        $schema = $this->validSchema();
        $schema['description'] = str_repeat('x', 100);
        $result = $validator->validate([$schema]);

        $this->assertEmpty($result['valid']);
    }

    public function testRejectsNonObjectParameters(): void
    {
        $validator = $this->makeValidator();
        $schema = $this->validSchema();
        $schema['parameters']['type'] = 'array';
        $result = $validator->validate([$schema]);

        $this->assertEmpty($result['valid']);
    }

    public function testRejectsTooManyParams(): void
    {
        $validator = $this->makeValidator(['maxParams' => 2]);
        $schema = $this->validSchema();
        $schema['parameters']['properties'] = [
            'a' => ['type' => 'string'],
            'b' => ['type' => 'string'],
            'c' => ['type' => 'string'],
        ];
        $result = $validator->validate([$schema]);

        $this->assertEmpty($result['valid']);
    }

    public function testRejectsDeepNesting(): void
    {
        $validator = $this->makeValidator(['maxDepth' => 1]);
        $schema = $this->validSchema();
        $schema['parameters']['properties'] = [
            'nested' => [
                'type' => 'object',
                'properties' => [
                    'deep' => [
                        'type' => 'object',
                        'properties' => [
                            'too_deep' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
        $result = $validator->validate([$schema]);

        $this->assertEmpty($result['valid']);
    }

    public function testWhitelistEnforcement(): void
    {
        $validator = $this->makeValidator(['allowedNames' => ['render_chart']]);
        $result = $validator->validate([
            $this->validSchema('render_chart'),
            $this->validSchema('other_tool'),
        ]);

        $this->assertCount(1, $result['valid']);
        $this->assertSame('render_chart', $result['valid'][0]['name']);
    }

    public function testMultipleValidSchemasAllPass(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->validate([
            $this->validSchema('render_chart'),
            $this->validSchema('play_audio'),
        ]);

        $this->assertCount(2, $result['valid']);
    }
}
