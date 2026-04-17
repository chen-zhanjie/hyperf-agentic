<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\LlmClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class LlmClientFactoryTest extends TestCase
{
    private function createConfigStub(array $map): object
    {
        return new class($map) {
            public function __construct(private array $map) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->map[$key] ?? $default;
            }
        };
    }

    private function createContainer(object $config, ?LoggerInterface $logger = null, bool $loggerThrows = false): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($config, $logger, $loggerThrows) {
                return match ($id) {
                    \Hyperf\Contract\ConfigInterface::class => $config,
                    LoggerInterface::class => $loggerThrows
                        ? throw new \RuntimeException('not found')
                        : $logger,
                    default => throw new \RuntimeException("Unexpected container get: {$id}"),
                };
            },
        );

        return $container;
    }

    public function testFactoryCreatesLlmClientWithConfig(): void
    {
        $config = $this->createConfigStub([
            'agentic.providers' => [
                'default' => 'deepseek',
                'providers' => [
                    'deepseek' => ['protocol' => 'openai', 'model' => 'deepseek-chat', 'api_key' => 'test'],
                ],
            ],
        ]);
        $container = $this->createContainer($config);

        $factory = new LlmClientFactory();
        $client = $factory($container);

        $this->assertInstanceOf(LlmClient::class, $client);
        $this->assertSame('deepseek', $client->getDefaultProvider());
        $this->assertTrue($client->hasProvider('deepseek'));
    }

    public function testFactoryHandlesMissingConfig(): void
    {
        $config = $this->createConfigStub([]);
        $container = $this->createContainer($config);

        $factory = new LlmClientFactory();
        $client = $factory($container);

        $this->assertInstanceOf(LlmClient::class, $client);
        $this->assertSame('openai', $client->getDefaultProvider());
        $this->assertFalse($client->hasProvider('openai'));
    }

    public function testFactoryPassesLoggerWhenAvailable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createConfigStub([]);
        $container = $this->createContainer($config, $logger);

        $factory = new LlmClientFactory();
        $client = $factory($container);

        $this->assertInstanceOf(LlmClient::class, $client);
    }

    public function testFactoryHandlesMissingLogger(): void
    {
        $config = $this->createConfigStub([]);
        $container = $this->createContainer($config, loggerThrows: true);

        $factory = new LlmClientFactory();
        $client = $factory($container);

        $this->assertInstanceOf(LlmClient::class, $client);
    }
}
