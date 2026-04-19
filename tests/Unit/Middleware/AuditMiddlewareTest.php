<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\AgentResult;
use ChenZhanjie\Agentic\Middleware\AuditMiddleware;

class AuditMiddlewareTest extends TestCase
{
    public function testBeforeLoopCapturesSessionContext(): void
    {
        $log = [];
        $middleware = new AuditMiddleware(function (string $event, array $ctx) use (&$log): void {
            $log[] = ['event' => $event, 'ctx' => $ctx];
        });

        $middleware->beforeLoop([], ['session_id' => 's1', 'agent_name' => 'test']);

        // No log on beforeLoop, just captures context
        $this->assertEmpty($log);
    }

    public function testBeforeToolCallLogsAndPassesThrough(): void
    {
        $log = [];
        $middleware = new AuditMiddleware(function (string $event, array $ctx) use (&$log): void {
            $log[] = ['event' => $event, 'ctx' => $ctx];
        });

        $middleware->beforeLoop([], ['session_id' => 's1', 'agent_name' => 'bot']);
        $result = $middleware->beforeToolCall('search', ['query' => 'test']);

        $this->assertNull($result); // pass through
        $this->assertCount(1, $log);
        $this->assertSame('tool.call', $log[0]['event']);
        $this->assertSame('search', $log[0]['ctx']['tool']);
    }

    public function testAfterToolCallLogsResult(): void
    {
        $log = [];
        $middleware = new AuditMiddleware(function (string $event, array $ctx) use (&$log): void {
            $log[] = ['event' => $event, 'ctx' => $ctx];
        });

        $middleware->beforeLoop([], ['session_id' => 's1', 'agent_name' => 'bot']);
        $middleware->beforeToolCall('search', ['query' => 'test']);
        $middleware->afterToolCall('search', ['query' => 'test'], 'found results');

        $this->assertCount(2, $log);
        $this->assertSame('tool.result', $log[1]['event']);
        $this->assertTrue($log[1]['ctx']['success']);
    }

    public function testRedactsSensitiveFields(): void
    {
        $log = [];
        $middleware = new AuditMiddleware(function (string $event, array $ctx) use (&$log): void {
            $log[] = ['event' => $event, 'ctx' => $ctx];
        });

        $middleware->beforeLoop([], []);
        $middleware->beforeToolCall('auth', [
            'username' => 'admin',
            'password' => 'secret123',
            'api_key' => 'sk-abc',
            'normal_field' => 'visible',
        ]);

        $args = $log[0]['ctx']['arguments'];
        $this->assertSame('***REDACTED***', $args['password']);
        $this->assertSame('***REDACTED***', $args['api_key']);
        $this->assertSame('visible', $args['normal_field']);
    }

    public function testDetectsFailedToolResult(): void
    {
        $log = [];
        $middleware = new AuditMiddleware(function (string $event, array $ctx) use (&$log): void {
            $log[] = ['event' => $event, 'ctx' => $ctx];
        });

        $middleware->beforeLoop([], []);
        $middleware->beforeToolCall('test', []);
        $middleware->afterToolCall('test', [], 'Tool execution error [test]: something broke');

        $this->assertFalse($log[1]['ctx']['success']);
    }

    public function testAfterLoopPassesThrough(): void
    {
        $middleware = new AuditMiddleware();
        $result = AgentResult::complete(content: 'done');
        $returned = $middleware->afterLoop($result);

        $this->assertSame($result, $returned);
    }
}
