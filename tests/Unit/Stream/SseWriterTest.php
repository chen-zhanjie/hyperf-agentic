<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Stream;

use ChenZhanjie\Agentic\Stream\SseWriter;
use PHPUnit\Framework\TestCase;

class SseWriterTest extends TestCase
{
    /**
     * Helper: create a SseWriter that captures SSE output into an object buffer.
     */
    private function createWriter(string $model = '', string $id = ''): array
    {
        $buf = (object) ['value' => ''];
        $write = function (string $line) use ($buf): void {
            $buf->value .= $line;
        };
        $writer = new SseWriter(write: $write, id: $id, model: $model);

        return [$writer, $buf];
    }

    /**
     * Helper: parse SSE buffer into individual data payloads.
     * @return array<int, array{type: string, data: string}>
     */
    private function parseSse(string $buffer): array
    {
        $chunks = [];
        $lines = explode("\n\n", $buffer);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line === 'data: [DONE]') {
                $chunks[] = ['type' => 'done', 'data' => ''];
                continue;
            }
            if (str_starts_with($line, 'data: ')) {
                $chunks[] = ['type' => 'chunk', 'data' => substr($line, 6)];
            }
        }

        return $chunks;
    }

    // ── Role delta ──

    public function testStartedEventEmitsRoleDelta(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);

        $chunks = $this->parseSse($buf->value);
        $this->assertCount(1, $chunks);
        $this->assertSame('chunk', $chunks[0]['type']);

        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('chat.completion.chunk', $data['object']);
        $this->assertSame('assistant', $data['choices'][0]['delta']['role']);
        $this->assertSame('', $data['choices'][0]['delta']['content']);
        $this->assertNull($data['choices'][0]['finish_reason']);
        $this->assertStringStartsWith('chatcmpl-', $data['id']);
    }

    // ── Content delta ──

    public function testTextDeltaEmitsContentDelta(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $buf->value = ''; // reset to isolate text_delta output

        $onEvent('text_delta', ['content' => 'Hello']);
        $chunks = $this->parseSse($buf->value);
        $this->assertCount(1, $chunks);

        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('Hello', $data['choices'][0]['delta']['content']);
        $this->assertNull($data['choices'][0]['finish_reason']);
    }

    public function testMultipleTextDeltasProduceSequentialChunks(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $buf->value = '';

        $onEvent('text_delta', ['content' => 'Hel']);
        $onEvent('text_delta', ['content' => 'lo']);

        $chunks = $this->parseSse($buf->value);
        $this->assertCount(2, $chunks);
        $this->assertSame('Hel', json_decode($chunks[0]['data'], true)['choices'][0]['delta']['content']);
        $this->assertSame('lo', json_decode($chunks[1]['data'], true)['choices'][0]['delta']['content']);
    }

    // ── Reasoning delta ──

    public function testReasoningDeltaEmitsReasoningContent(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $buf->value = '';

        $onEvent('reasoning_delta', ['content' => 'Let me think...']);
        $chunks = $this->parseSse($buf->value);
        $this->assertCount(1, $chunks);

        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('Let me think...', $data['choices'][0]['delta']['reasoning_content']);
    }

    public function testReasoningDeltaAfterTextDelta(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $buf->value = '';

        $onEvent('reasoning_delta', ['content' => 'thinking...']);
        $onEvent('text_delta', ['content' => 'Hello']);

        $chunks = $this->parseSse($buf->value);
        $this->assertCount(2, $chunks);

        $reasoning = json_decode($chunks[0]['data'], true);
        $this->assertSame('thinking...', $reasoning['choices'][0]['delta']['reasoning_content']);

        $text = json_decode($chunks[1]['data'], true);
        $this->assertSame('Hello', $text['choices'][0]['delta']['content']);
    }

    // ── Finish + [DONE] ──

    public function testCompleteEventEmitsFinishChunkAndDone(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $onEvent('text_delta', ['content' => 'Hi']);

        $onEvent('complete', [
            'iterations' => 2,
            'elapsed_ms' => 500,
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ]);

        $chunks = $this->parseSse($buf->value);

        // Last two should be: finish chunk + [DONE]
        $lastChunk = $chunks[count($chunks) - 2];
        $doneChunk = $chunks[count($chunks) - 1];

        $data = json_decode($lastChunk['data'], true);
        $this->assertSame('stop', $data['choices'][0]['finish_reason']);
        $this->assertSame([], $data['choices'][0]['delta']);
        $this->assertSame(100, $data['usage']['prompt_tokens']);
        $this->assertSame(50, $data['usage']['completion_tokens']);
        $this->assertSame(150, $data['usage']['total_tokens']);

        $this->assertSame('done', $doneChunk['type']);
    }

    public function testDoneSentinelIsWrittenExactlyOnce(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $onEvent('text_delta', ['content' => 'Hi']);
        $onEvent('complete', ['iterations' => 1, 'elapsed_ms' => 100, 'prompt_tokens' => 10, 'completion_tokens' => 5]);

        $this->assertSame(1, substr_count($buf->value, 'data: [DONE]'));
    }

    public function testDoneIsIdempotent(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $onEvent('complete', ['iterations' => 1, 'elapsed_ms' => 100, 'prompt_tokens' => 10, 'completion_tokens' => 5]);

        $writer->done(); // Should not produce another [DONE]
        $this->assertSame(1, substr_count($buf->value, 'data: [DONE]'));
    }

    // ── Tool calls ──

    public function testToolCallEventProducesToolCallsDelta(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $buf->value = '';

        $onEvent('tool_call', [
            'call_id' => 'call_abc123',
            'name' => 'search',
            'arguments' => ['q' => 'test'],
        ]);

        $chunks = $this->parseSse($buf->value);
        $this->assertCount(1, $chunks);

        $data = json_decode($chunks[0]['data'], true);
        $toolCalls = $data['choices'][0]['delta']['tool_calls'];
        $this->assertCount(1, $toolCalls);
        $this->assertSame(0, $toolCalls[0]['index']);
        $this->assertSame('call_abc123', $toolCalls[0]['id']);
        $this->assertSame('function', $toolCalls[0]['type']);
        $this->assertSame('search', $toolCalls[0]['function']['name']);
        $args = json_decode($toolCalls[0]['function']['arguments'], true);
        $this->assertSame(['q' => 'test'], $args);
    }

    public function testMultipleToolCallsGetIncrementingIndices(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $buf->value = '';

        $onEvent('tool_call', ['call_id' => 'c1', 'name' => 'search', 'arguments' => []]);
        $onEvent('tool_call', ['call_id' => 'c2', 'name' => 'calculate', 'arguments' => []]);

        $chunks = $this->parseSse($buf->value);
        $this->assertCount(2, $chunks);

        $tc1 = json_decode($chunks[0]['data'], true)['choices'][0]['delta']['tool_calls'][0];
        $tc2 = json_decode($chunks[1]['data'], true)['choices'][0]['delta']['tool_calls'][0];
        $this->assertSame(0, $tc1['index']);
        $this->assertSame(1, $tc2['index']);
    }

    // ── Terminal events ──

    public function testBudgetExceededProducesFinishReasonLength(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);

        $onEvent('budget_exceeded', ['iterations' => 15, 'max' => 15]);

        $chunks = $this->parseSse($buf->value);
        $lastChunk = $chunks[count($chunks) - 2]; // second-to-last = finish chunk
        $data = json_decode($lastChunk['data'], true);
        $this->assertSame('length', $data['choices'][0]['finish_reason']);

        $doneChunk = $chunks[count($chunks) - 1];
        $this->assertSame('done', $doneChunk['type']);
    }

    public function testGuardrailBlockedProducesFinishReasonContentFilter(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);

        $onEvent('guardrail_blocked', [
            'type' => 'output',
            'name' => 'content_filter',
            'reason' => 'unsafe content detected',
        ]);

        $chunks = $this->parseSse($buf->value);
        $lastChunk = $chunks[count($chunks) - 2];
        $data = json_decode($lastChunk['data'], true);
        $this->assertSame('content_filter', $data['choices'][0]['finish_reason']);

        $doneChunk = $chunks[count($chunks) - 1];
        $this->assertSame('done', $doneChunk['type']);
    }

    // ── Custom model and ID ──

    public function testModelCapturedFromStartedEvent(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();

        $onEvent('started', ['agent' => 'Test', 'model' => 'gpt-4o']);
        $buf->value = '';
        $onEvent('text_delta', ['content' => 'Hi']);

        $chunks = $this->parseSse($buf->value);
        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('gpt-4o', $data['model']);
    }

    public function testExplicitModelOverridesStartedEvent(): void
    {
        [$writer, $buf] = $this->createWriter(model: 'claude-3');
        $onEvent = $writer->asOnEvent();

        $onEvent('started', ['agent' => 'Test', 'model' => 'gpt-4o']);
        $buf->value = '';
        $onEvent('text_delta', ['content' => 'Hi']);

        $chunks = $this->parseSse($buf->value);
        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('claude-3', $data['model']);
    }

    public function testCustomModelNameIsIncludedInChunks(): void
    {
        [$writer, $buf] = $this->createWriter(model: 'gpt-4o');
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);

        $chunks = $this->parseSse($buf->value);
        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('gpt-4o', $data['model']);
    }

    public function testCustomIdIsUsedWhenProvided(): void
    {
        [$writer, $buf] = $this->createWriter(id: 'chatcmpl-custom');
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);

        $chunks = $this->parseSse($buf->value);
        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('chatcmpl-custom', $data['id']);
    }

    // ── asOnChunk path (chatStream) ──

    public function testAsOnChunkEmitsRoleThenContent(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onChunk = $writer->asOnChunk();

        $onChunk(['content' => 'World']);

        $chunks = $this->parseSse($buf->value);
        $this->assertCount(2, $chunks);

        // First: role delta
        $roleData = json_decode($chunks[0]['data'], true);
        $this->assertSame('assistant', $roleData['choices'][0]['delta']['role']);

        // Second: content delta
        $contentData = json_decode($chunks[1]['data'], true);
        $this->assertSame('World', $contentData['choices'][0]['delta']['content']);
    }

    public function testAsOnChunkWithFinishProducesCompleteSequence(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onChunk = $writer->asOnChunk();

        $onChunk(['content' => 'Hi']);
        $writer->finish(['prompt_tokens' => 20, 'completion_tokens' => 10]);

        $chunks = $this->parseSse($buf->value);
        // role + content + finish + done
        $this->assertSame(4, count($chunks));
        $this->assertSame('chunk', $chunks[0]['type']);
        $this->assertSame('chunk', $chunks[1]['type']);
        $this->assertSame('chunk', $chunks[2]['type']);
        $this->assertSame('done', $chunks[3]['type']);

        $finishData = json_decode($chunks[2]['data'], true);
        $this->assertSame('stop', $finishData['choices'][0]['finish_reason']);
        $this->assertSame(20, $finishData['usage']['prompt_tokens']);
        $this->assertSame(10, $finishData['usage']['completion_tokens']);
    }

    // ── finish() with explicit finish_reason ──

    public function testFinishWithExplicitFinishReason(): void
    {
        [$writer, $buf] = $this->createWriter();
        $onEvent = $writer->asOnEvent();
        $onEvent('started', ['agent' => 'Test']);
        $buf->value = '';

        $writer->finish([], 'tool_calls');

        $chunks = $this->parseSse($buf->value);
        $data = json_decode($chunks[0]['data'], true);
        $this->assertSame('tool_calls', $data['choices'][0]['finish_reason']);
    }
}
