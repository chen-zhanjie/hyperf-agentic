<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Resolver\NullHumanInputResolver;

class NullHumanInputResolverTest extends TestCase
{
    private NullHumanInputResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NullHumanInputResolver();
    }

    public function testIsBlockingReturnsFalse(): void
    {
        $this->assertFalse($this->resolver->isBlocking());
    }

    public function testConfirmWithNoFieldsReturnsFalse(): void
    {
        $result = $this->resolver->ask('Are you sure?');
        $this->assertFalse($result['confirmed']);
        $this->assertSame([], $result['values']);
    }

    public function testConfirmWithEmptyFieldsReturnsFalse(): void
    {
        $result = $this->resolver->ask('Are you sure?', []);
        $this->assertFalse($result['confirmed']);
    }

    public function testFieldsReturnDefaults(): void
    {
        $result = $this->resolver->ask('Fill in', [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['name' => 'active', 'type' => 'confirm', 'label' => 'Active?'],
            ['name' => 'color', 'type' => 'select', 'label' => 'Color', 'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue'],
            ]],
            ['name' => 'tags', 'type' => 'multiselect', 'label' => 'Tags', 'options' => []],
        ]);

        // confirmed=false because 'active' confirm defaults to false
        $this->assertFalse($result['confirmed']);
        $this->assertSame('', $result['values']['name']);
        $this->assertFalse($result['values']['active']);
        $this->assertSame('red', $result['values']['color']);
        $this->assertSame([], $result['values']['tags']);
    }

    public function testConfirmedTrueWhenAllConfirmFieldsTrue(): void
    {
        $result = $this->resolver->ask('Fill in', [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['name' => 'active', 'type' => 'confirm', 'label' => 'Active?', 'default' => true],
        ]);

        $this->assertTrue($result['confirmed']);
        $this->assertTrue($result['values']['active']);
    }

    public function testFieldsUseExplicitDefaults(): void
    {
        $result = $this->resolver->ask('Fill in', [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'default' => 'John'],
            ['name' => 'active', 'type' => 'confirm', 'label' => 'Active?', 'default' => true],
        ]);

        $this->assertSame('John', $result['values']['name']);
        $this->assertTrue($result['values']['active']);
    }

    public function testSelectWithNoOptionsReturnsEmpty(): void
    {
        $result = $this->resolver->ask('Choose', [
            ['name' => 'color', 'type' => 'select', 'label' => 'Color', 'options' => []],
        ]);
        $this->assertSame('', $result['values']['color']);
    }
}
