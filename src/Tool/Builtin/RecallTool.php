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
        return '撤回最近一条 assistant 消息。当需要更正错误回复、撤回不当内容时使用。不传 message_id 或传 "current" 表示撤回最近一条 assistant 消息。';
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
                    'description' => '要撤回的消息 ID。不传或传 "current" 表示撤回最近一条 assistant 消息。',
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
            'required' => ['reason'],
        ];
    }

    public function execute(array $arguments): string
    {
        $messageId = (string) ($arguments['message_id'] ?? '');
        $reason = (string) ($arguments['reason'] ?? '');
        $conversationId = (string) ($arguments['conversation_id'] ?? '');
        $replacement = $arguments['replacement'] ?? null;

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

        // Empty or "current" → recall last assistant message
        if ($messageId === '' || $messageId === 'current') {
            $recalledId = $this->messageStore->recallLast($conversationId, $reason);

            if ($recalledId === '') {
                return json_encode([
                    'recalled' => false,
                    'message_id' => 'current',
                    'error' => 'No assistant message found to recall',
                ], JSON_UNESCAPED_UNICODE);
            }

            return json_encode([
                'recalled' => true,
                'message_id' => $recalledId,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE);
        }

        // Specific message_id → recall by ID
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
