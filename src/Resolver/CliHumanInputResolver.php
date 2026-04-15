<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Resolver;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI blocking resolver — uses SymfonyStyle for interactive prompts.
 */
class CliHumanInputResolver implements HumanInputResolverInterface
{
    public function __construct(
        private readonly SymfonyStyle $io,
    ) {}

    public function ask(string $message, array $fields = []): array
    {
        if (empty($fields)) {
            $confirmed = $this->io->confirm($message, false);
            return ['confirmed' => $confirmed, 'values' => []];
        }

        $values = [];
        $other = [];
        $allConfirmed = true;

        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';
            $values[$field['name']] = match ($type) {
                'confirm' => $this->resolveConfirm($field, $allConfirmed),
                'select'  => $this->resolveSelect($field, $other),
                'multiselect' => $this->resolveMultiselect($field, $other),
                default   => $this->io->ask($field['label'], $field['default'] ?? null),
            };
        }

        $result = ['confirmed' => $allConfirmed, 'values' => $values];
        if (!empty($other)) {
            $result['_other'] = $other;
        }
        return $result;
    }

    public function isBlocking(): bool
    {
        return true;
    }

    private function resolveConfirm(array $field, bool &$allConfirmed): bool
    {
        $value = $this->io->confirm($field['label'], $field['default'] ?? false);
        if (!$value) {
            $allConfirmed = false;
        }
        return $value;
    }

    private function resolveSelect(array $field, array &$other): string
    {
        // Build label→value map for robust lookup
        $labelToValue = [];
        $choices = [];
        foreach ($field['options'] as $opt) {
            $choices[] = $opt['label'];
            $labelToValue[$opt['label']] = $opt['value'];
        }

        if ($field['allow_other'] ?? false) {
            $choices[] = '<自定义输入>';
        }

        $selected = $this->io->choice($field['label'], $choices);

        if (($field['allow_other'] ?? false) && $selected === '<自定义输入>') {
            $custom = $this->io->ask('请输入自定义内容');
            $other[$field['name']] = $custom;
            return $custom;
        }

        return $labelToValue[$selected] ?? '';
    }

    private function resolveMultiselect(array $field, array &$other): array
    {
        $selected = [];
        $options = $field['options'];

        foreach ($options as $opt) {
            if ($this->io->confirm("  包含 {$opt['label']}?", false)) {
                $selected[] = $opt['value'];
            }
        }

        if (($field['allow_other'] ?? false) && $this->io->confirm('  添加自定义选项?', false)) {
            $custom = $this->io->ask('  请输入自定义内容');
            $selected[] = $custom;
            $other[$field['name']] = $custom;
        }

        return $selected;
    }
}
