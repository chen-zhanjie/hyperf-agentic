<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tool\Builtin;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;

/**
 * Built-in interactive tool — ask user for input.
 *
 * Supports confirm, select, multiselect, text input, and batch forms.
 * Delegates actual UI to HumanInputResolverInterface (CLI / HTTP / Null).
 * isParallelAllowed() returns false to force serial execution.
 */
class AskTool implements ToolInterface
{
    private ?HumanInputResolverInterface $resolver = null;

    public function name(): string
    {
        return 'ask';
    }

    public function description(): string
    {
        return '向用户提问并等待回复。支持确认、选择、多选、文本输入和批量表单。当需要用户决策、信息补充或确认操作时使用此工具。';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => '向用户展示的消息/问题',
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => '问题字段列表。空数组或省略表示纯确认。',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['confirm', 'select', 'multiselect', 'text']],
                            'label' => ['type' => 'string'],
                            'options' => ['type' => 'array', 'description' => 'Options for select/multiselect fields'],
                            'default' => ['description' => 'Default value for the field'],
                            'required' => ['type' => 'boolean', 'default' => true],
                            'placeholder' => ['type' => 'string'],
                            'allow_other' => ['type' => 'boolean', 'default' => false],
                        ],
                        'required' => ['name', 'type', 'label'],
                    ],
                ],
            ],
            'required' => ['message'],
        ];
    }

    public function execute(array $arguments): string
    {
        $message = (string) ($arguments['message'] ?? '');
        $fields = (array) ($arguments['fields'] ?? []);

        if ($this->resolver === null) {
            return json_encode([
                'confirmed' => false,
                'values' => [],
                'error' => 'No resolver configured',
            ], JSON_UNESCAPED_UNICODE);
        }

        $result = $this->resolver->ask($message, $fields);
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isParallelAllowed(): bool
    {
        return false;
    }

    /**
     * Inject the resolver at runtime (called by AgentRunner before dispatch).
     */
    public function setResolver(HumanInputResolverInterface $resolver): void
    {
        $this->resolver = $resolver;
    }
}
