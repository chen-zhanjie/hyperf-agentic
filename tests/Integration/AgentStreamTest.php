<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Integration;

use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\MiddlewarePipeline;
use ChenZhanjie\Agentic\Persona\Persona;
use ChenZhanjie\Agentic\Policy\ConfigToolPermissionPolicy;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolGuardrailRunner;
use ChenZhanjie\Agentic\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class AgentStreamTest extends TestCase
{
    private function createRunner(): AgentRunner
    {
        return new AgentRunner(
            llmClient: IntegrationTestConfig::createOpenAiLlmClient(),
            promptBuilder: new PromptBuilder(),
            toolRegistry: new ToolRegistry(),
            guardrailRunner: new GuardrailRunner(),
            middleware: new MiddlewarePipeline(),
            toolGuardrailRunner: new ToolGuardrailRunner(),
            permissionPolicy: new ConfigToolPermissionPolicy(),
        );
    }

    public function testRunStreamEmitsTextDeltaAndComplete(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();
        $runner = $this->createRunner();

        $events = [];
        $textChunks = [];
        $onEvent = function (string $type, array $payload) use (&$events, &$textChunks): void {
            $events[] = $type;
            if ($type === 'text_delta' && isset($payload['content'])) {
                $textChunks[] = $payload['content'];
            }
        };

        $result = $runner->runStream(
            [['role' => 'user', 'content' => 'Say exactly "Streaming works" and nothing else.']],
            [
                'max_iterations' => 2,
                'persona' => new Persona(name: 'TestAgent', content: 'You are a test assistant.'),
                'system_prompt' => '',
                'tools' => [],
                'scene' => 'test',
            ],
            [],
            $onEvent,
        );

        $this->assertTrue($result->isComplete());
        $this->assertNotEmpty($result->content);
        $this->assertContains('started', $events);
        $this->assertContains('thinking', $events);
        $this->assertContains('text_delta', $events);
        $this->assertContains('complete', $events);
        $this->assertNotEmpty($textChunks);

        // Reassembled text from deltas should match the final content
        $this->assertSame($result->content, implode('', $textChunks));
    }

    public function testChatStreamReturnsNormalizedArray(): void
    {
        IntegrationTestConfig::skipIfNoOpenAIKey();
        $runner = $this->createRunner();

        $chunks = [];
        $onChunk = function (array $chunk) use (&$chunks): void {
            if (isset($chunk['content'])) {
                $chunks[] = $chunk['content'];
            }
        };

        $result = $runner->chatStream(
            [['role' => 'user', 'content' => 'Say exactly "pong" and nothing else.']],
            [],
            $onChunk,
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertNotEmpty($result['content']);
        $this->assertNotEmpty($chunks);
        $this->assertSame($result['content'], implode('', $chunks));
    }
}
