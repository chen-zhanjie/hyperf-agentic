<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
