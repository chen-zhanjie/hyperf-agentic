<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Resolver\CliHumanInputResolver;
use Symfony\Component\Console\Style\SymfonyStyle;

class CliHumanInputResolverTest extends TestCase
{
    private SymfonyStyle $io;
    private CliHumanInputResolver $resolver;

    protected function setUp(): void
    {
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->resolver = new CliHumanInputResolver($this->io);
    }

    public function testIsBlockingReturnsTrue(): void
    {
        $this->assertTrue($this->resolver->isBlocking());
    }

    public function testConfirmWithNoFields(): void
    {
        $this->io->method('confirm')->with('Are you sure?', false)->willReturn(true);

        $result = $this->resolver->ask('Are you sure?');
        $this->assertTrue($result['confirmed']);
        $this->assertSame([], $result['values']);
    }

    public function testConfirmWithNoFieldsReturnsFalse(): void
    {
        $this->io->method('confirm')->with('Are you sure?', false)->willReturn(false);

        $result = $this->resolver->ask('Are you sure?');
        $this->assertFalse($result['confirmed']);
    }

    public function testTextFieldCallsAsk(): void
    {
        $this->io->method('ask')->with('Name', null)->willReturn('John');

        $result = $this->resolver->ask('Fill in', [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
        ]);

        $this->assertTrue($result['confirmed']);
        $this->assertSame('John', $result['values']['name']);
    }

    public function testTextFieldUsesDefault(): void
    {
        $this->io->method('ask')->with('Name', 'Default')->willReturn('Default');

        $result = $this->resolver->ask('Fill in', [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'default' => 'Default'],
        ]);

        $this->assertSame('Default', $result['values']['name']);
    }

    public function testConfirmFieldReturnsBool(): void
    {
        $this->io->method('confirm')->with('Active?', true)->willReturn(true);

        $result = $this->resolver->ask('Fill in', [
            ['name' => 'active', 'type' => 'confirm', 'label' => 'Active?', 'default' => true],
        ]);

        $this->assertTrue($result['confirmed']);
        $this->assertTrue($result['values']['active']);
    }

    public function testSelectFieldReturnsValue(): void
    {
        $this->io->method('choice')
            ->with('Color', ['Red', 'Blue'])
            ->willReturn('Blue');

        $result = $this->resolver->ask('Choose', [
            ['name' => 'color', 'type' => 'select', 'label' => 'Color', 'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue'],
            ]],
        ]);

        $this->assertSame('blue', $result['values']['color']);
    }

    public function testSelectFieldWithAllowOtherChoosesCustom(): void
    {
        // choice returns '<Custom input>', then ask for custom value
        $this->io->method('choice')
            ->with('Color', ['Red', 'Blue', '<Custom input>'])
            ->willReturn('<Custom input>');
        $this->io->method('ask')
            ->with('Enter custom value')
            ->willReturn('green');

        $result = $this->resolver->ask('Choose', [
            ['name' => 'color', 'type' => 'select', 'label' => 'Color',
             'options' => [
                 ['value' => 'red', 'label' => 'Red'],
                 ['value' => 'blue', 'label' => 'Blue'],
             ],
             'allow_other' => true],
        ]);

        $this->assertSame('green', $result['values']['color']);
        $this->assertSame('green', $result['_other']['color']);
    }

    public function testMultiselectFieldReturnsArrayOfValues(): void
    {
        // For each option, confirm is called
        $confirmMap = [
            ['  Include Red?', false, false],
            ['  Include Blue?', false, true],
        ];
        $this->io->method('confirm')
            ->willReturnCallback(function (string $question, bool $default) use (&$confirmMap) {
                foreach ($confirmMap as $i => $map) {
                    if ($map[0] === $question) {
                        return $map[2];
                    }
                }
                return $default;
            });

        $result = $this->resolver->ask('Select tags', [
            ['name' => 'tags', 'type' => 'multiselect', 'label' => 'Tags', 'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue'],
            ]],
        ]);

        $this->assertSame(['blue'], $result['values']['tags']);
    }

    public function testMultipleFieldsAllResolved(): void
    {
        $this->io->method('ask')->with('Name', null)->willReturn('Alice');
        $this->io->method('confirm')->with('Active?', false)->willReturn(true);

        $result = $this->resolver->ask('Fill in', [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['name' => 'active', 'type' => 'confirm', 'label' => 'Active?'],
        ]);

        $this->assertTrue($result['confirmed']);
        $this->assertSame('Alice', $result['values']['name']);
        $this->assertTrue($result['values']['active']);
    }

    public function testConfirmedFalseWhenConfirmFieldReturnsFalse(): void
    {
        $this->io->method('confirm')->with('Active?', false)->willReturn(false);
        $this->io->method('ask')->with('Name', null)->willReturn('Alice');

        $result = $this->resolver->ask('Fill in', [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['name' => 'active', 'type' => 'confirm', 'label' => 'Active?'],
        ]);

        $this->assertFalse($result['confirmed']);
    }
}
