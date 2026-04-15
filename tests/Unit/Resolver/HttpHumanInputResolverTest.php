<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Contract\SessionStoreInterface;
use ChenZhanjie\Agentic\Exception\AgentSuspendedException;
use ChenZhanjie\Agentic\Resolver\HttpHumanInputResolver;

class HttpHumanInputResolverTest extends TestCase
{
    private SessionStoreInterface $sessionStore;
    private HttpHumanInputResolver $resolver;

    protected function setUp(): void
    {
        $this->sessionStore = $this->createMock(SessionStoreInterface::class);
        $this->resolver = new HttpHumanInputResolver($this->sessionStore, 'sess_123');
    }

    public function testIsBlockingReturnsFalse(): void
    {
        $this->assertFalse($this->resolver->isBlocking());
    }

    public function testAskWithNoFieldsThrowsSuspended(): void
    {
        $this->sessionStore->expects($this->once())
            ->method('set')
            ->with('sess_123', 'pending_ask', $this->callback(function (array $data) {
                return $data['message'] === 'Are you sure?'
                    && $data['fields'] === [];
            }));

        $this->expectException(AgentSuspendedException::class);
        $this->expectExceptionMessage('waiting_for_human_input');

        $this->resolver->ask('Are you sure?');
    }

    public function testAskWithFieldsThrowsSuspended(): void
    {
        $fields = [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
        ];

        $this->sessionStore->expects($this->once())
            ->method('set')
            ->with('sess_123', 'pending_ask', $this->callback(function (array $data) use ($fields) {
                return $data['message'] === 'Fill in'
                    && $data['fields'] === $fields
                    && isset($data['asked_at']);
            }));

        $this->expectException(AgentSuspendedException::class);

        $this->resolver->ask('Fill in', $fields);
    }

    public function testSuspendedExceptionContainsFieldData(): void
    {
        $fields = [
            ['name' => 'confirm', 'type' => 'confirm', 'label' => 'OK?'],
        ];

        $this->sessionStore->method('set');

        try {
            $this->resolver->ask('Confirm?', $fields);
            $this->fail('Expected AgentSuspendedException');
        } catch (AgentSuspendedException $e) {
            $this->assertSame('waiting_for_human_input', $e->getMessage());
        }
    }

    public function testAskStoresPendingAskInSession(): void
    {
        $capturedData = null;

        $this->sessionStore->method('set')
            ->willReturnCallback(function (string $sessionId, string $key, mixed $value) use (&$capturedData) {
                if ($key === 'pending_ask') {
                    $capturedData = $value;
                }
            });

        try {
            $this->resolver->ask('Question?', [
                ['name' => 'q', 'type' => 'text', 'label' => 'Q'],
            ]);
        } catch (AgentSuspendedException) {
            // expected
        }

        $this->assertNotNull($capturedData);
        $this->assertSame('Question?', $capturedData['message']);
        $this->assertCount(1, $capturedData['fields']);
        $this->assertArrayHasKey('asked_at', $capturedData);
    }

    public function testInvalidSessionIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid session ID format');

        new HttpHumanInputResolver($this->sessionStore, '../etc/passwd');
    }

    public function testEmptySessionIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HttpHumanInputResolver($this->sessionStore, '');
    }
}
