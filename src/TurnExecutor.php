<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\HumanInputResolverInterface;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\ToolPermissionPolicyInterface;
use ChenZhanjie\Agentic\Event\AgentEventType;
use ChenZhanjie\Agentic\ToolDispatcher;

/**
 * Executes a single turn in the agent loop.
 *
 * Unifies the 4 previously duplicated methods (executeTurn, executeStreamTurn,
 * runGraceTurn, runStreamGraceTurn) into a single parameterized class.
 *
 * Flow: build ephemeral → middleware → call LLM → record usage →
 *       if text: guardrail → emit complete → return result
 *       if tools: process → return null
 */
class TurnExecutor
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly PromptBuilder $promptBuilder,
        private readonly MiddlewarePipeline $middleware,
        private readonly ToolDispatcher $toolDispatcher,
    ) {}

    /**
     * Execute a single agent loop turn.
     *
     * @param array              $fullMessages  Conversation messages (modified by reference)
     * @param string             $systemMessage Base system prompt
     * @param array              $toolSchemas   Tool definitions for LLM
     * @param array              $options       Runtime options
     * @param callable|null      $onEvent       Event callback
     * @param LoopState          $loop          Loop state tracker
     * @param AgentRunContext    $context       Per-run context
     * @param bool               $stream        Use streaming LLM call
     * @param bool               $grace         Grace turn (budget exhausted, one last chance)
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
        // Grace turns increment iteration count (normal turns are incremented by runLoop)
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

        // Middleware — before LLM call
        $llmOptions = $this->middleware->beforeLlmCall($fullMessages, [
            'tools' => $toolSchemas,
        ]);

        $this->emitEvent($onEvent, AgentEventType::THINKING, [
            'iteration' => $loop->iterations,
        ]);

        // Call LLM (sync or streaming)
        if ($stream) {
            [$content, $toolCalls, $usage, $reasoningContent] = $this->callLlmStream(
                $fullMessages, $llmOptions, $onEvent,
            );
        } else {
            [$content, $toolCalls, $usage, $reasoningContent] = $this->callLlmSync(
                $fullMessages, $llmOptions, $onEvent,
            );
        }

        // Track token usage
        $loop->recordUsage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        );

        $response = [
            'content' => $content,
            'tool_calls' => $toolCalls,
            'usage' => $usage,
            'reasoning_content' => $reasoningContent,
        ];

        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $this->middleware->afterLlmCall($response, new LlmCallMeta(
            provider: $options['provider'] ?? '',
            model: $options['model'] ?? '',
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
        ));

        // No tool calls → text response → done
        if (empty($toolCalls)) {
            return $this->handleTextResponse(
                $content,
                $reasoningContent,
                $onEvent,
                $loop,
                $context,
            );
        }

        // Tool calls — process each one
        $loop->recordToolCalls(count($toolCalls));

        $fullMessages[] = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];

        $callIdPrefix = $grace ? 'grace' : (string) $loop->iterations;
        $enforceParallel = !$grace;

        $this->toolDispatcher->processToolCalls(
            $toolCalls, $fullMessages, $onEvent,
            $callIdPrefix, $enforceParallel, $context,
        );

        return null; // Tool calls processed — continue loop
    }

    /**
     * Call LLM synchronously and return normalized parts.
     *
     * @return array{0: string, 1: array, 2: array, 3: string|null}
     */
    private function callLlmSync(array $fullMessages, array $llmOptions, ?callable $onEvent): array
    {
        $response = $this->llmClient->chat($fullMessages, $llmOptions);

        $content = is_string($response['content'] ?? null) ? $response['content'] : (string) ($response['content'] ?? '');

        if ($content !== '') {
            $this->emitEvent($onEvent, AgentEventType::TEXT_DELTA, [
                'content' => $content,
            ]);
        }

        $reasoningContent = $response['reasoning_content'] ?? null;
        if ($reasoningContent !== null && $reasoningContent !== '') {
            $this->emitEvent($onEvent, AgentEventType::REASONING_DELTA, [
                'content' => $reasoningContent,
            ]);
        }

        return [
            $content,
            $response['tool_calls'] ?? [],
            $response['usage'] ?? [],
            $reasoningContent,
        ];
    }

    /**
     * Call LLM with streaming and return normalized parts.
     * Text content is accumulated from stream chunks, not from the response array.
     *
     * @return array{0: string, 1: array, 2: array, 3: string|null}
     */
    private function callLlmStream(array $fullMessages, array $llmOptions, ?callable $onEvent): array
    {
        $textBuffer = '';
        $reasoningBuffer = '';

        $response = $this->llmClient->chatStream(
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

        // Use accumulated textBuffer (not response content which is empty for streaming)
        $content = $textBuffer !== '' ? $textBuffer : (string) ($response['content'] ?? '');
        $toolCalls = $response['tool_calls'] ?? [];
        $usage = $response['usage'] ?? [];
        $reasoningContent = $reasoningBuffer ?: ($response['reasoning_content'] ?? null);

        return [$content, $toolCalls, $usage, $reasoningContent];
    }

    /**
     * Handle a text-only response: guardrail check → emit complete → return result.
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

        $result = $this->middleware->afterLoop($result);

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
