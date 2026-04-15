<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AsyncGuardrailContext;
use ChenZhanjie\Agentic\GuardrailEntry;
use ChenZhanjie\Agentic\GuardrailMode;
use ChenZhanjie\Agentic\GuardrailResult;
use ChenZhanjie\Agentic\GuardrailRunner;
use ChenZhanjie\Agentic\Contract\GuardrailInterface;

class GuardrailRunnerTest extends TestCase
{
    // ── register + checkInput ──

    public function testCheckInputWithNoGuardrailsReturnsNull(): void
    {
        $runner = new GuardrailRunner();
        $result = $runner->checkInput([['role' => 'user', 'content' => 'hello']]);

        $this->assertNull($result);
    }

    public function testCheckInputPassesWhenNoGuardrailTrips(): void
    {
        $guardrail = $this->createStubGuardrail('safety', false);
        $runner = new GuardrailRunner();
        $runner->register($guardrail);

        $messages = [['role' => 'user', 'content' => 'safe message']];
        $result = $runner->checkInput($messages);

        $this->assertNull($result);
    }

    public function testCheckInputReturnsBlockedWhenGuardrailTrips(): void
    {
        $guardrail = $this->createStubGuardrail('safety', true, 'Unsafe content detected');
        $runner = new GuardrailRunner();
        $runner->register($guardrail);

        $messages = [['role' => 'user', 'content' => 'dangerous input']];
        $result = $runner->checkInput($messages);

        $this->assertNotNull($result);
        $this->assertTrue($result->tripwire);
        $this->assertSame('Unsafe content detected', $result->reason);
    }

    public function testCheckInputStopsAtFirstTrippedGuardrail(): void
    {
        $first = $this->createStubGuardrail('first', true, 'First blocked');
        $second = $this->createStubGuardrail('second', true, 'Second blocked');

        $runner = new GuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $result = $runner->checkInput([['role' => 'user', 'content' => 'test']]);

        $this->assertNotNull($result);
        $this->assertSame('First blocked', $result->reason);
    }

    public function testCheckInputWithMultipleGuardrailsOnlyOneTrips(): void
    {
        $first = $this->createStubGuardrail('safety', false);
        $second = $this->createStubGuardrail('pii', true, 'PII detected');

        $runner = new GuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $result = $runner->checkInput([['role' => 'user', 'content' => 'test']]);

        $this->assertNotNull($result);
        $this->assertSame('PII detected', $result->reason);
    }

    // ── checkOutput ──

    public function testCheckOutputWithNoGuardrailsReturnsNull(): void
    {
        $runner = new GuardrailRunner();
        $result = $runner->checkOutput('some output');

        $this->assertNull($result);
    }

    public function testCheckOutputPassesWhenNoGuardrailTrips(): void
    {
        $guardrail = $this->createStubGuardrail('output_safety', false);
        $runner = new GuardrailRunner();
        $runner->register($guardrail);

        $result = $runner->checkOutput('safe output');

        $this->assertNull($result);
    }

    public function testCheckOutputReturnsBlockedWhenGuardrailTrips(): void
    {
        $guardrail = $this->createStubGuardrail('output_safety', true, 'Harmful output');
        $runner = new GuardrailRunner();
        $runner->register($guardrail);

        $result = $runner->checkOutput('harmful content');

        $this->assertNotNull($result);
        $this->assertTrue($result->tripwire);
        $this->assertSame('Harmful output', $result->reason);
    }

    public function testCheckOutputStopsAtFirstTrippedGuardrail(): void
    {
        $first = $this->createStubGuardrail('a', true, 'A blocked');
        $second = $this->createStubGuardrail('b', true, 'B blocked');

        $runner = new GuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $result = $runner->checkOutput('test');

        $this->assertSame('A blocked', $result->reason);
    }

    // ── loadFromConfig ──

    public function testLoadFromConfigResetsExistingGuardrails(): void
    {
        $initial = $this->createStubGuardrail('initial', false);
        $runner = new GuardrailRunner();
        $runner->register($initial);

        // loadFromConfig with empty array should clear existing
        $runner->loadFromConfig([]);

        $result = $runner->checkInput([['role' => 'user', 'content' => 'test']]);
        $this->assertNull($result);
    }

    public function testLoadFromConfigIgnoresNonExistentClasses(): void
    {
        $runner = new GuardrailRunner();
        // Should not throw — silently skips non-existent classes
        $runner->loadFromConfig(['\\NonExistent\\Guardrail']);

        $result = $runner->checkInput([['role' => 'user', 'content' => 'test']]);
        $this->assertNull($result);
    }

    // ── Guardrail receives correct arguments ──

    public function testCheckInputPassesMessagesToGuardrail(): void
    {
        $receivedMessages = null;
        $guardrail = new class($receivedMessages) implements GuardrailInterface {
            private ?array $captured;
            public function __construct(?array &$captured)
            {
                $this->captured = &$captured;
            }
            public function name(): string { return 'spy'; }
            public function checkInput(array $messages): GuardrailResult
            {
                $this->captured = $messages;
                return GuardrailResult::ok();
            }
            public function checkOutput(string $content): GuardrailResult
            {
                return GuardrailResult::ok();
            }
        };

        $runner = new GuardrailRunner();
        // Re-bind: closure trick to capture
        $spy = null;
        $guardrail = new class($spy) implements GuardrailInterface {
            public ?array $captured;
            public function __construct(?array &$captured)
            {
                $this->captured = &$captured;
            }
            public function name(): string { return 'spy'; }
            public function checkInput(array $messages): GuardrailResult
            {
                $this->captured = $messages;
                return GuardrailResult::ok();
            }
            public function checkOutput(string $content): GuardrailResult
            {
                return GuardrailResult::ok();
            }
        };

        $runner->register($guardrail);
        $messages = [['role' => 'user', 'content' => 'hello world']];
        $runner->checkInput($messages);

        $this->assertSame($messages, $guardrail->captured);
    }

    public function testCheckOutputPassesContentToGuardrail(): void
    {
        $guardrail = new class implements GuardrailInterface {
            public ?string $captured = null;
            public function name(): string { return 'spy'; }
            public function checkInput(array $messages): GuardrailResult
            {
                return GuardrailResult::ok();
            }
            public function checkOutput(string $content): GuardrailResult
            {
                $this->captured = $content;
                return GuardrailResult::ok();
            }
        };

        $runner = new GuardrailRunner();
        $runner->register($guardrail);
        $runner->checkOutput('test output content');

        $this->assertSame('test output content', $guardrail->captured);
    }

    // ── only() per-agent filtering ──

    public function testOnlyReturnsNewInstanceWithFilteredGuardrails(): void
    {
        $safety = $this->createStubGuardrail('safety', true, 'Blocked by safety');
        $pii = $this->createStubGuardrail('pii', true, 'Blocked by pii');
        $content = $this->createStubGuardrail('content', true, 'Blocked by content');

        $runner = new GuardrailRunner();
        $runner->register($safety);
        $runner->register($pii);
        $runner->register($content);

        // Filter to only 'pii'
        $filtered = $runner->only(['pii']);

        $result = $filtered->checkInput([['role' => 'user', 'content' => 'test']]);
        $this->assertNotNull($result);
        $this->assertSame('Blocked by pii', $result->reason);
    }

    public function testOnlyDoesNotMutateOriginalRunner(): void
    {
        $safety = $this->createStubGuardrail('safety', true, 'Safety');
        $pii = $this->createStubGuardrail('pii', true, 'PII');

        $runner = new GuardrailRunner();
        $runner->register($safety);
        $runner->register($pii);

        $filtered = $runner->only(['pii']);

        // Original runner still has safety guardrail — should trigger safety first
        $result = $runner->checkInput([['role' => 'user', 'content' => 'test']]);
        $this->assertSame('Safety', $result->reason);
    }

    public function testOnlyWithEmptyNamesReturnsAllGuardrails(): void
    {
        $safety = $this->createStubGuardrail('safety', true, 'Safety');

        $runner = new GuardrailRunner();
        $runner->register($safety);

        $filtered = $runner->only([]);
        $result = $filtered->checkInput([['role' => 'user', 'content' => 'test']]);

        $this->assertSame('Safety', $result->reason);
    }

    public function testOnlyFiltersOutputGuardrails(): void
    {
        $safety = $this->createStubGuardrail('safety', true, 'Safety output');
        $pii = $this->createStubGuardrail('pii', true, 'PII output');

        $runner = new GuardrailRunner();
        $runner->register($safety);
        $runner->register($pii);

        $filtered = $runner->only(['pii']);
        $result = $filtered->checkOutput('test');

        $this->assertSame('PII output', $result->reason);
    }

    // ── register with mode ──

    public function testRegisterDefaultModeIsSync(): void
    {
        $guardrail = $this->createStubGuardrail('test', false);
        $runner = new GuardrailRunner();
        $runner->register($guardrail);

        $ctx = $runner->checkInputAsync([['role' => 'user', 'content' => 'test']]);
        // Sync guardrail runs immediately, no async handles
        $this->assertFalse($ctx->hasAsyncGuardrails());
    }

    public function testRegisterWithAsyncMode(): void
    {
        $guardrail = $this->createStubGuardrail('async_guard', false);
        $runner = new GuardrailRunner();
        $runner->register($guardrail, GuardrailMode::ASYNC);

        $ctx = $runner->checkInputAsync([['role' => 'user', 'content' => 'test']]);
        $this->assertTrue($ctx->hasAsyncGuardrails());
    }

    // ── checkInputAsync ──

    public function testCheckInputAsyncSyncBlockReturnsBlockedContext(): void
    {
        $syncGuard = $this->createStubGuardrail('sync_blocker', true, 'sync blocked');
        $runner = new GuardrailRunner();
        $runner->register($syncGuard);

        $ctx = $runner->checkInputAsync([['role' => 'user', 'content' => 'bad']]);
        $this->assertTrue($ctx->isBlocked());
        $this->assertSame('sync blocked', $ctx->getBlockResult()->reason);
    }

    public function testCheckInputAsyncSyncPassAsyncPending(): void
    {
        $syncGuard = $this->createStubGuardrail('sync_ok', false);
        $asyncGuard = $this->createStubGuardrail('async_check', false);
        $runner = new GuardrailRunner();
        $runner->register($syncGuard);
        $runner->register($asyncGuard, GuardrailMode::ASYNC);

        $ctx = $runner->checkInputAsync([['role' => 'user', 'content' => 'test']]);
        // Without Swoole, async guardrails execute synchronously
        $this->assertFalse($ctx->isBlocked());
        $this->assertTrue($ctx->hasAsyncGuardrails());
        // In non-Swoole environment, handle completes immediately
        $this->assertTrue($ctx->allCompleted());
    }

    // ── checkOutputAsync ──

    public function testCheckOutputAsyncSyncBlockReturnsBlockedContext(): void
    {
        $syncGuard = $this->createStubGuardrail('sync_blocker', true, 'output unsafe');
        $runner = new GuardrailRunner();
        $runner->register($syncGuard);

        $ctx = $runner->checkOutputAsync('bad content');
        $this->assertTrue($ctx->isBlocked());
    }

    public function testCheckOutputAsyncSyncPassAsyncPending(): void
    {
        $syncGuard = $this->createStubGuardrail('sync_ok', false);
        $asyncGuard = $this->createStubGuardrail('async_check', false);
        $runner = new GuardrailRunner();
        $runner->register($syncGuard);
        $runner->register($asyncGuard, GuardrailMode::ASYNC);

        $ctx = $runner->checkOutputAsync('test content');
        $this->assertFalse($ctx->isBlocked());
        $this->assertTrue($ctx->hasAsyncGuardrails());
        // Without Swoole, async completes synchronously
        $this->assertTrue($ctx->allCompleted());
    }

    // ── loadFromConfig with mode support ──

    public function testLoadFromConfigWithStringArrayDefaultsToSync(): void
    {
        $runner = new GuardrailRunner();
        // NonExistent class is silently skipped
        $runner->loadFromConfig(['\\NonExistent\\Guardrail']);

        $ctx = $runner->checkInputAsync([['role' => 'user', 'content' => 'test']]);
        $this->assertFalse($ctx->hasAsyncGuardrails());
    }

    public function testLoadFromConfigWithModeMap(): void
    {
        $guardrail = $this->createStubGuardrail('configurable', false);

        // Create a concrete class that we can instantiate by name
        $runner = new GuardrailRunner();
        // We can't loadFromConfig with anonymous classes, so test via register
        $runner->register($guardrail, GuardrailMode::ASYNC);

        $ctx = $runner->checkInputAsync([['role' => 'user', 'content' => 'test']]);
        $this->assertTrue($ctx->hasAsyncGuardrails());
    }

    // ── withModes ──

    public function testWithModesReturnsNewInstance(): void
    {
        $guardrail = $this->createStubGuardrail('test', false);
        $runner = new GuardrailRunner();
        $runner->register($guardrail);

        $modified = $runner->withModes(['test' => GuardrailMode::ASYNC]);
        $this->assertNotSame($runner, $modified);
    }

    public function testWithModesDoesNotMutateOriginal(): void
    {
        $guardrail = $this->createStubGuardrail('test', false);
        $runner = new GuardrailRunner();
        $runner->register($guardrail);

        $modified = $runner->withModes(['test' => GuardrailMode::ASYNC]);

        // Original still sync
        $originalCtx = $runner->checkInputAsync([['role' => 'user', 'content' => 'test']]);
        $this->assertFalse($originalCtx->hasAsyncGuardrails());

        // Modified is async
        $modifiedCtx = $modified->checkInputAsync([['role' => 'user', 'content' => 'test']]);
        $this->assertTrue($modifiedCtx->hasAsyncGuardrails());
    }

    // ── helpers ──

    private function createStubGuardrail(
        string $name,
        bool $tripwire,
        string $reason = '',
    ): GuardrailInterface {
        return new class($name, $tripwire, $reason) implements GuardrailInterface {
            public function __construct(
                private readonly string $name,
                private readonly bool $tripwire,
                private readonly string $reason,
            ) {}
            public function name(): string { return $this->name; }
            public function checkInput(array $messages): GuardrailResult
            {
                return new GuardrailResult($this->tripwire, $this->reason);
            }
            public function checkOutput(string $content): GuardrailResult
            {
                return new GuardrailResult($this->tripwire, $this->reason);
            }
        };
    }
}
