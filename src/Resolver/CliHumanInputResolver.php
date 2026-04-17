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
        foreach ($this->normalizeOptions($field['options'] ?? []) as $label => $value) {
            $choices[] = $label;
            $labelToValue[$label] = $value;
        }

        if ($field['allow_other'] ?? false) {
            $choices[] = '<Custom input>';
        }

        $selected = $this->io->choice($field['label'], $choices);

        if (($field['allow_other'] ?? false) && $selected === '<Custom input>') {
            $custom = $this->io->ask('Enter custom value');
            $other[$field['name']] = $custom;
            return $custom;
        }

        return $labelToValue[$selected] ?? '';
    }

    private function resolveMultiselect(array $field, array &$other): array
    {
        $selected = [];

        foreach ($this->normalizeOptions($field['options'] ?? []) as $label => $value) {
            if ($this->io->confirm("  Include {$label}?", false)) {
                $selected[] = $value;
            }
        }

        if (($field['allow_other'] ?? false) && $this->io->confirm('  Add a custom option?', false)) {
            $custom = $this->io->ask('  Enter custom value');
            $selected[] = $custom;
            $other[$field['name']] = $custom;
        }

        return $selected;
    }

    /**
     * Normalize options to label→value map.
     * Handles both string items ["美式","拿铁"] and object items [{"label":"美式","value":"americano"}].
     *
     * @return array<string, string> label → value
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $opt) {
            if (is_string($opt)) {
                $normalized[$opt] = $opt;
            } elseif (is_array($opt)) {
                $label = $opt['label'] ?? (string) ($opt['value'] ?? '');
                $value = $opt['value'] ?? $opt['label'] ?? '';
                $normalized[$label] = $value;
            }
        }
        return $normalized;
    }
}
