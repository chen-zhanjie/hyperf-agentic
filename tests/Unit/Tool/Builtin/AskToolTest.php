<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Tool\Builtin;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Tool\Builtin\AskTool;

class AskToolTest extends TestCase
{
    public function testNameIsAsk(): void
    {
        $tool = new AskTool();
        $this->assertSame('ask', $tool->name());
    }

    public function testDescriptionContainsQuestion(): void
    {
        $tool = new AskTool();
        $this->assertStringContainsString('用户', $tool->description());
    }

    public function testParametersHasMessageField(): void
    {
        $tool = new AskTool();
        $params = $tool->parameters();
        $this->assertSame('object', $params['type']);
        $this->assertArrayHasKey('message', $params['properties']);
        $this->assertContains('message', $params['required']);
    }

    public function testParametersHasFieldsArray(): void
    {
        $tool = new AskTool();
        $params = $tool->parameters();
        $this->assertArrayHasKey('fields', $params['properties']);
        $fieldsType = $params['properties']['fields']['type'] ?? '';
        $this->assertSame('array', $fieldsType);
    }

    public function testIsEnabledByDefault(): void
    {
        $tool = new AskTool();
        $this->assertTrue($tool->isEnabled());
    }

    public function testIsParallelDisallowed(): void
    {
        $tool = new AskTool();
        $this->assertFalse($tool->isParallelAllowed());
    }

    public function testExecuteWithoutResolverReturnsError(): void
    {
        $tool = new AskTool();
        $result = $tool->execute(['message' => 'Continue?']);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['confirmed']);
    }

    public function testExecuteWithResolverDelegates(): void
    {
        $resolver = new class implements HumanInputResolverInterface {
            public bool $askCalled = false;
            public function ask(string $message, array $fields = []): array
            {
                $this->askCalled = true;
                return ['confirmed' => true, 'values' => []];
            }
            public function isBlocking(): bool { return true; }
        };

        $tool = new AskTool();
        $tool->setResolver($resolver);

        $result = $tool->execute(['message' => 'Confirm?']);
        $decoded = json_decode($result, true);

        $this->assertTrue($resolver->askCalled);
        $this->assertTrue($decoded['confirmed']);
    }

    public function testExecutePassesFieldsToResolver(): void
    {
        $resolver = new class implements HumanInputResolverInterface {
            public array $receivedFields = [];
            public function ask(string $message, array $fields = []): array
            {
                $this->receivedFields = $fields;
                return ['confirmed' => true, 'values' => []];
            }
            public function isBlocking(): bool { return true; }
        };

        $fields = [
            ['name' => 'env', 'type' => 'select', 'label' => 'Environment',
             'options' => [['value' => 'prod', 'label' => 'Production']]],
        ];

        $tool = new AskTool();
        $tool->setResolver($resolver);

        $tool->execute(['message' => 'Choose env', 'fields' => $fields]);

        $this->assertSame($fields, $resolver->receivedFields);
    }

    public function testExecuteWithResolverThatReturnsValues(): void
    {
        $resolver = new class implements HumanInputResolverInterface {
            public function ask(string $message, array $fields = []): array
            {
                return [
                    'confirmed' => true,
                    'values' => ['env' => 'prod', 'force' => true],
                ];
            }
            public function isBlocking(): bool { return true; }
        };

        $tool = new AskTool();
        $tool->setResolver($resolver);

        $result = $tool->execute(['message' => 'Deploy?']);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['confirmed']);
        $this->assertSame('prod', $decoded['values']['env']);
        $this->assertTrue($decoded['values']['force']);
    }

    public function testExecuteWithCancelledResolver(): void
    {
        $resolver = new class implements HumanInputResolverInterface {
            public function ask(string $message, array $fields = []): array
            {
                return ['confirmed' => false, 'values' => []];
            }
            public function isBlocking(): bool { return true; }
        };

        $tool = new AskTool();
        $tool->setResolver($resolver);

        $result = $tool->execute(['message' => 'Continue?']);
        $decoded = json_decode($result, true);

        $this->assertFalse($decoded['confirmed']);
    }
}
