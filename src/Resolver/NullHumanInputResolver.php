<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Resolver;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;

/**
 * Null implementation — unattended mode.
 * Returns defaults for all field types. Confirm defaults to false (safe).
 */
class NullHumanInputResolver implements HumanInputResolverInterface
{
    public function ask(string $message, array $fields = []): array
    {
        if (empty($fields)) {
            return ['confirmed' => false, 'values' => []];
        }

        $values = [];
        $allConfirmed = true;
        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';
            $default = $field['default'] ?? match ($type) {
                'confirm' => false,
                'select' => $field['options'][0]['value'] ?? '',
                'multiselect' => [],
                'text' => '',
            };
            $values[$field['name']] = $default;
            if ($type === 'confirm' && !$default) {
                $allConfirmed = false;
            }
        }
        return ['confirmed' => $allConfirmed, 'values' => $values];
    }

    public function isBlocking(): bool
    {
        return false;
    }
}
