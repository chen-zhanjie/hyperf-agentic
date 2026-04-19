<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\Event\AgentEventType;

/**
 * Executes a single turn in the agent loop.
 *
 * Flow: build ephemeral → call LLM → record usage →
 *       if text: guardrail → emit complete → return result
 *       if tools: process → return null
 */
class TurnExecutor
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly PromptBuilder $promptBuilder,
        private readonly ToolDispatcher $toolDispatcher,
    ) {}

    /**
     * Execute a single agent loop turn.
     */
    public function execute(
        array &$fullMessages,
        string $systemMessage,
        array $toolSchemas,
        array $options,
        ?callable $onEvent,
        LoopState $loop,
        AgentRunContext $context,
        bool $stream = false,
        bool $grace = false,
    ): ?AgentResult {
        if ($grace) {
            ++$loop->iterations;
        }

        // Build ephemeral prompt for this turn
        $ephemeral = $this->promptBuilder->buildEphemeralPrompt(
            runtimeContext: $options['runtime_context'] ?? [],
            budget: $loop->budget,
            costBudget: $grace ? null : $loop->costBudget,
        );

        $fullMessages[0]['content'] = $systemMessage . "\n\n---\n\n" . $ephemeral;

        $llmOptions = ['tools' => $toolSchemas];

        $this->emitEvent($onEvent, AgentEventType::THINKING, [
            'iteration' => $loop->iterations,
        ]);

        // Call LLM (sync or streaming) — LLM middleware runs inside LlmClient
        if ($stream) {
            $llmResponse = $this->callLlmStream($fullMessages, $llmOptions, $onEvent);
        } else {
            $llmResponse = $this->callLlmSync($fullMessages, $llmOptions, $onEvent);
        }

        // Track token usage from structured LlmResponse
        $loop->recordUsage($llmResponse->promptTokens(), $llmResponse->completionTokens());

        // No tool calls → text response → done
        if (empty($llmResponse->toolCalls)) {
            return $this->handleTextResponse(
                $llmResponse->content,
                $llmResponse->reasoningContent,
                $onEvent,
                $loop,
                $context,
            );
        }

        // Tool calls — process each one
        $loop->recordToolCalls(count($llmResponse->toolCalls));

        $fullMessages[] = [
            'role' => 'assistant',
            'content' => $llmResponse->content,
            'tool_calls' => $llmResponse->toolCalls,
        ];

        $callIdPrefix = $grace ? 'grace' : (string) $loop->iterations;
        $enforceParallel = !$grace;

        $this->toolDispatcher->processToolCalls(
            $llmResponse->toolCalls, $fullMessages, $onEvent,
            $callIdPrefix, $enforceParallel, $context,
        );

        return null; // Tool calls processed — continue loop
    }

    private function callLlmSync(array $fullMessages, array $llmOptions, ?callable $onEvent): LlmResponse
    {
        $llmResponse = $this->llmClient->chat($fullMessages, $llmOptions);

        if ($llmResponse->content !== '') {
            $this->emitEvent($onEvent, AgentEventType::TEXT_DELTA, [
                'content' => $llmResponse->content,
            ]);
        }

        if ($llmResponse->reasoningContent !== null && $llmResponse->reasoningContent !== '') {
            $this->emitEvent($onEvent, AgentEventType::REASONING_DELTA, [
                'content' => $llmResponse->reasoningContent,
            ]);
        }

        return $llmResponse;
    }

    private function callLlmStream(array $fullMessages, array $llmOptions, ?callable $onEvent): LlmResponse
    {
        $textBuffer = '';
        $reasoningBuffer = '';

        $llmResponse = $this->llmClient->chatStream(
            $fullMessages,
            $llmOptions,
            function (array $chunk) use ($onEvent, &$textBuffer, &$reasoningBuffer): void {
                if (isset($chunk['content']) && $chunk['content'] !== '') {
                    $textBuffer .= $chunk['content'];
                    $this->emitEvent($onEvent, AgentEventType::TEXT_DELTA, ['content' => $chunk['content']]);
                }
                if (isset($chunk['reasoning_content']) && $chunk['reasoning_content'] !== '') {
                    $reasoningBuffer .= $chunk['reasoning_content'];
                    $this->emitEvent($onEvent, AgentEventType::REASONING_DELTA, [
                        'content' => $chunk['reasoning_content'],
                    ]);
                }
            },
        );

        // Override content with accumulated buffers for streaming
        return new LlmResponse(
            content: $textBuffer !== '' ? $textBuffer : $llmResponse->content,
            usage: $llmResponse->usage,
            provider: $llmResponse->provider,
            model: $llmResponse->model,
            reasoningContent: $reasoningBuffer ?: $llmResponse->reasoningContent,
            toolCalls: $llmResponse->toolCalls,
            latencyMs: $llmResponse->latencyMs,
        );
    }

    /**
     * Handle a text-only response: guardrail check → emit complete → return result.
     * afterLoop is NOT called here — it is called by AgentRunner after the loop ends.
     */
    private function handleTextResponse(
        string $content,
        ?string $reasoningContent,
        ?callable $onEvent,
        LoopState $loop,
        AgentRunContext $context,
    ): AgentResult {
        // Output guardrail check (async-aware)
        $outputGuardContext = $context->guardrails->checkOutputAsync($content);

        // Sync guardrail blocked immediately
        if ($outputGuardContext->isBlocked() && !$outputGuardContext->hasAsyncGuardrails()) {
            $blockResult = $outputGuardContext->getBlockResult();
            $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_BLOCKED, [
                'type' => 'output',
                'name' => $outputGuardContext->getBlockName() ?? 'output_guard',
                'reason' => $blockResult->reason,
            ]);
            return AgentResult::guardrailBlocked('output', $blockResult->reason, $loop->elapsedMs());
        }

        $result = AgentResult::complete(
            content: $content,
            reasoningContent: $reasoningContent,
            iterations: $loop->iterations,
            elapsedMs: $loop->elapsedMs(),
            promptTokens: $loop->totalPromptTokens,
            completionTokens: $loop->totalCompletionTokens,
            toolCalls: $loop->totalToolCalls,
        );

        $this->emitEvent($onEvent, AgentEventType::COMPLETE, [
            'iterations' => $loop->iterations,
            'elapsed_ms' => $result->elapsedMs,
            'prompt_tokens' => $loop->totalPromptTokens,
            'completion_tokens' => $loop->totalCompletionTokens,
        ]);

        // Wait for async guardrails
        if ($outputGuardContext->hasAsyncGuardrails() && !$outputGuardContext->allCompleted()) {
            $outputGuardContext->await($loop->asyncGuardrailTimeout);
        }

        // Async guardrail blocked after output → recall
        if ($outputGuardContext->isBlocked()) {
            $blockResult = $outputGuardContext->getBlockResult();
            $this->emitEvent($onEvent, AgentEventType::GUARDRAIL_RECALLED, [
                'type' => 'output',
                'name' => $outputGuardContext->getBlockName() ?? 'output_async',
                'reason' => $blockResult->reason,
            ]);
            return AgentResult::recalled(
                content: $content,
                reason: $blockResult->reason,
                elapsedMs: $loop->elapsedMs(),
            );
        }

        return $result;
    }

    private function emitEvent(?callable $onEvent, AgentEventType $type, array $payload = []): void
    {
        if ($onEvent !== null) {
            $onEvent($type->value, $payload);
        }
    }
}
