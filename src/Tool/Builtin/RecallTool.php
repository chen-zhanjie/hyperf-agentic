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
        return '撤回已发送的消息。当你发现自己之前的回复包含错误、不当内容或需要更正时，使用此工具撤回该消息。必须提供有效的 message_id（消息的唯一标识符）和 conversation_id（会话标识符）。如果不确定 message_id，请先询问用户。';
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

        if ($messageId === '') {
            return json_encode(['recalled' => false, 'error' => 'message_id is required'], JSON_UNESCAPED_UNICODE);
        }

        if ($this->messageStore === null) {
            return json_encode([
                'recalled' => false,
                'message_id' => $messageId,
                'error' => 'No message store configured',
            ], JSON_UNESCAPED_UNICODE);
        }

        if ($conversationId === '') {
            return json_encode([
                'recalled' => false,
                'message_id' => $messageId,
                'error' => 'conversation_id is required for recall',
            ], JSON_UNESCAPED_UNICODE);
        }

        $success = $this->messageStore->recall($conversationId, $messageId, $reason);

        if (!$success) {
            return json_encode([
                'recalled' => false,
                'message_id' => $messageId,
                'error' => 'Message not found in conversation',
            ], JSON_UNESCAPED_UNICODE);
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
