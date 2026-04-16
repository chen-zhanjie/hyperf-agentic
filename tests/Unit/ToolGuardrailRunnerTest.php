<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\ToolGuardrailInterface;
use ChenZhanjie\Agentic\ToolGuardrailResult;
use ChenZhanjie\Agentic\ToolGuardrailRunner;

class ToolGuardrailRunnerTest extends TestCase
{
    // ── no guardrails ──

    public function testCheckToolInputWithNoGuardrailsReturnsNull(): void
    {
        $runner = new ToolGuardrailRunner();
        $args = ['query' => 'test'];
        $result = $runner->checkToolInput('search', $args);

        $this->assertNull($result);
        $this->assertSame(['query' => 'test'], $args);
    }

    public function testCheckToolOutputWithNoGuardrailsReturnsNull(): void
    {
        $runner = new ToolGuardrailRunner();
        $output = 'original output';
        $result = $runner->checkToolOutput('search', ['q' => 'x'], $output);

        $this->assertNull($result);
        $this->assertSame('original output', $output);
    }

    // ── checkToolInput pass ──

    public function testCheckToolInputPassesWhenNoGuardrailTrips(): void
    {
        $guardrail = $this->createStubToolGuardrail('validator', false);
        $runner = new ToolGuardrailRunner();
        $runner->register($guardrail);

        $args = ['query' => 'safe'];
        $result = $runner->checkToolInput('search', $args);

        $this->assertNull($result);
    }

    // ── checkToolInput blocked ──

    public function testCheckToolInputReturnsBlockedWhenGuardrailTrips(): void
    {
        $guardrail = $this->createStubToolGuardrail('validator', true, 'Missing required field');
        $runner = new ToolGuardrailRunner();
        $runner->register($guardrail);

        $args = [];
        $result = $runner->checkToolInput('search', $args);

        $this->assertNotNull($result);
        $this->assertTrue($result->blocked);
        $this->assertSame('Missing required field', $result->reason);
    }

    // ── checkToolInput sanitize ──

    public function testCheckToolInputSanitizesArguments(): void
    {
        $guardrail = $this->createSanitizeToolGuardrail('pii_filter', ['query' => '***REDACTED***']);
        $runner = new ToolGuardrailRunner();
        $runner->register($guardrail);

        $args = ['query' => 'my email is test@example.com'];
        $result = $runner->checkToolInput('search', $args);

        // Sanitize modifies args by reference, returns null (no block)
        $this->assertNull($result);
        $this->assertSame(['query' => '***REDACTED***'], $args);
    }

    // ── checkToolOutput pass ──

    public function testCheckToolOutputPassesWhenNoGuardrailTrips(): void
    {
        $guardrail = $this->createStubToolGuardrail('output_filter', false);
        $runner = new ToolGuardrailRunner();
        $runner->register($guardrail);

        $output = 'safe result';
        $result = $runner->checkToolOutput('search', ['q' => 'x'], $output);

        $this->assertNull($result);
    }

    // ── checkToolOutput blocked ──

    public function testCheckToolOutputReturnsBlockedWhenGuardrailTrips(): void
    {
        $guardrail = $this->createStubToolGuardrail('output_filter', true, 'Contains sensitive data');
        $runner = new ToolGuardrailRunner();
        $runner->register($guardrail);

        $output = 'sensitive data leaked';
        $result = $runner->checkToolOutput('search', ['q' => 'x'], $output);

        $this->assertNotNull($result);
        $this->assertTrue($result->blocked);
        $this->assertSame('Contains sensitive data', $result->reason);
    }

    // ── checkToolOutput transform ──

    public function testCheckToolOutputTransformsOutput(): void
    {
        $guardrail = $this->createTransformToolGuardrail('redactor', 'redacted output');
        $runner = new ToolGuardrailRunner();
        $runner->register($guardrail);

        $output = 'secret: abc123';
        $result = $runner->checkToolOutput('search', ['q' => 'x'], $output);

        // Transform modifies output by reference, returns null (no block)
        $this->assertNull($result);
        $this->assertSame('redacted output', $output);
    }

    // ── stops at first blocked ──

    public function testCheckToolInputStopsAtFirstBlocked(): void
    {
        $first = $this->createStubToolGuardrail('a', true, 'A blocked');
        $second = $this->createStubToolGuardrail('b', true, 'B blocked');

        $runner = new ToolGuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $args = [];
        $result = $runner->checkToolInput('tool', $args);

        $this->assertSame('A blocked', $result->reason);
    }

    // ── multiple guardrails, only one trips ──

    public function testCheckToolInputWithMultipleGuardrailsOneTrips(): void
    {
        $first = $this->createStubToolGuardrail('a', false);
        $second = $this->createStubToolGuardrail('b', true, 'B blocked');

        $runner = new ToolGuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $args = [];
        $result = $runner->checkToolInput('tool', $args);

        $this->assertSame('B blocked', $result->reason);
    }

    // ── M3: sanitize should continue running subsequent guardrails ──

    public function testCheckToolInputSanitizeRunsSubsequentGuardrails(): void
    {
        // First guardrail sanitizes, second guardrail should still run on sanitized args
        $tracker = new \stdClass();
        $tracker->secondCalled = false;
        $first = $this->createSanitizeToolGuardrail('pii_filter', ['query' => '***REDACTED***']);
        $second = new class($tracker) implements ToolGuardrailInterface {
            public function __construct(private readonly \stdClass $tracker) {}
            public function name(): string { return 'schema_check'; }
            public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
            {
                $this->tracker->secondCalled = true;
                return ToolGuardrailResult::ok();
            }
            public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
            {
                return ToolGuardrailResult::ok();
            }
        };

        $runner = new ToolGuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $args = ['query' => 'my email is test@example.com'];
        $result = $runner->checkToolInput('search', $args);

        // Second guardrail should have been called
        $this->assertTrue($tracker->secondCalled, 'Second guardrail should run after first sanitizes');
        // Arguments should be sanitized
        $this->assertSame(['query' => '***REDACTED***'], $args);
    }

    public function testCheckToolInputSanitizeThenBlockedBySubsequent(): void
    {
        // First guardrail sanitizes, second guardrail blocks the sanitized result
        $first = $this->createSanitizeToolGuardrail('pii_filter', ['query' => '']);
        $second = $this->createStubToolGuardrail('empty_check', true, 'Query cannot be empty after sanitization');

        $runner = new ToolGuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $args = ['query' => 'test@example.com'];
        $result = $runner->checkToolInput('search', $args);

        // Should be blocked by second guardrail
        $this->assertNotNull($result);
        $this->assertTrue($result->blocked);
        $this->assertSame('Query cannot be empty after sanitization', $result->reason);
    }

    public function testCheckToolOutputTransformRunsSubsequentGuardrails(): void
    {
        $tracker = new \stdClass();
        $tracker->secondCalled = false;
        $first = $this->createTransformToolGuardrail('redactor', 'redacted output');
        $second = new class($tracker) implements ToolGuardrailInterface {
            public function __construct(private readonly \stdClass $tracker) {}
            public function name(): string { return 'length_check'; }
            public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
            {
                return ToolGuardrailResult::ok();
            }
            public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
            {
                $this->tracker->secondCalled = true;
                return ToolGuardrailResult::ok();
            }
        };

        $runner = new ToolGuardrailRunner();
        $runner->register($first);
        $runner->register($second);

        $output = 'secret: abc123';
        $result = $runner->checkToolOutput('search', ['q' => 'x'], $output);

        $this->assertTrue($tracker->secondCalled, 'Second guardrail should run after first transforms output');
        $this->assertSame('redacted output', $output);
    }

    // ── helpers ──

    private function createStubToolGuardrail(
        string $name,
        bool $blocked,
        string $reason = '',
    ): ToolGuardrailInterface {
        return new class($name, $blocked, $reason) implements ToolGuardrailInterface {
            public function __construct(
                private readonly string $name,
                private readonly bool $blocked,
                private readonly string $reason,
            ) {}
            public function name(): string { return $this->name; }
            public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
            {
                if ($this->blocked) {
                    return ToolGuardrailResult::blocked($this->reason);
                }
                return ToolGuardrailResult::ok();
            }
            public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
            {
                if ($this->blocked) {
                    return ToolGuardrailResult::blocked($this->reason);
                }
                return ToolGuardrailResult::ok();
            }
        };
    }

    private function createSanitizeToolGuardrail(
        string $name,
        array $modifiedArgs,
    ): ToolGuardrailInterface {
        return new class($name, $modifiedArgs) implements ToolGuardrailInterface {
            public function __construct(
                private readonly string $name,
                private readonly array $modifiedArgs,
            ) {}
            public function name(): string { return $this->name; }
            public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
            {
                return ToolGuardrailResult::sanitize($this->modifiedArgs, 'PII removed');
            }
            public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
            {
                return ToolGuardrailResult::ok();
            }
        };
    }

    private function createTransformToolGuardrail(
        string $name,
        string $modifiedOutput,
    ): ToolGuardrailInterface {
        return new class($name, $modifiedOutput) implements ToolGuardrailInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $modifiedOutput,
            ) {}
            public function name(): string { return $this->name; }
            public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
            {
                return ToolGuardrailResult::ok();
            }
            public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
            {
                return ToolGuardrailResult::transformOutput($this->modifiedOutput, 'Secrets redacted');
            }
        };
    }
}
