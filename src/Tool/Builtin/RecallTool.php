<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tool\Builtin;

use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolInterface;

/**
 * System-level message recall tool.
 *
 * Used for retracting messages that were already sent — triggered by
 * async guardrails, LLM self-correction, or external policy enforcement.
 * When a MessageStoreInterface is injected, recall automatically persists.
 */
class RecallTool implements ToolInterface
{
    public function __construct(
        private readonly ?MessageStoreInterface $messageStore = null,
    ) {}

    public function name(): string
    {
        return 'recall';
    }

    public function description(): string
    {
        return '撤回已发送的消息。当你发现自己之前的回复包含错误、不当内容或需要更正时，使用此工具撤回该消息。';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conversation_id' => [
                    'type' => 'string',
                    'description' => '会话 ID',
                ],
                'message_id' => [
                    'type' => 'string',
                    'description' => '要撤回的消息 ID',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => '撤回原因',
                ],
                'replacement' => [
                    'type' => 'string',
                    'description' => '可选：替换为的安全内容',
                ],
            ],
            'required' => ['message_id', 'reason'],
        ];
    }

    public function execute(array $arguments): string
    {
        $messageId = (string) ($arguments['message_id'] ?? '');
        $reason = (string) ($arguments['reason'] ?? '');
        $conversationId = (string) ($arguments['conversation_id'] ?? '');
        $replacement = $arguments['replacement'] ?? null;

        if ($this->messageStore !== null && $conversationId !== '' && $messageId !== '') {
            $this->messageStore->recall($conversationId, $messageId, $reason);
        }

        $result = [
            'recalled' => true,
            'message_id' => $messageId,
            'reason' => $reason,
        ];

        if ($replacement !== null) {
            $result['replacement'] = (string) $replacement;
        }

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
}
