<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\AgenticFactory;
use ChenZhanjie\Agentic\AgentRunner;
use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\SessionStoreInterface;
use ChenZhanjie\Agentic\LlmClient;
use ChenZhanjie\Agentic\PromptBuilder;
use ChenZhanjie\Agentic\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class AgenticFactoryTest extends TestCase
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

    private function createContainer(object $config, array $services = [], array $optionalThrow = []): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($config, $services, $optionalThrow) {
                if ($id === \Hyperf\Contract\ConfigInterface::class) {
                    return $config;
                }
                if (in_array($id, $optionalThrow, true)) {
                    throw new \RuntimeException("not found: {$id}");
                }
                if (isset($services[$id])) {
                    return $services[$id];
                }
                throw new \RuntimeException("Unexpected container get: {$id}");
            },
        );

        return $container;
    }

    public function testFactoryCreatesAgenticWithConfig(): void
    {
        $config = $this->createConfigStub([
            'agentic.agents' => [
                'chat' => ['persona' => 'default.md'],
                'coder' => ['persona' => 'coder.md'],
            ],
            'agentic.agentic' => [
                'default_agent' => 'chat',
                'max_iterations' => 15,
            ],
        ]);

        $services = [
            LlmClient::class => new LlmClient(providerConfigs: ['test' => ['model' => 'test']], defaultProvider: 'test'),
            AgentRunner::class => $this->createMock(AgentRunner::class),
            ToolRegistry::class => new ToolRegistry(),
            PromptBuilder::class => new PromptBuilder(),
            SessionStoreInterface::class => null,
            MessageStoreInterface::class => null,
            PermissionApprovalStoreInterface::class => null,
        ];

        $container = $this->createContainer($config, $services);

        $factory = new AgenticFactory();
        $agentic = $factory($container);

        $this->assertInstanceOf(Agentic::class, $agentic);
        $this->assertTrue($agentic->has('chat'));
        $this->assertTrue($agentic->has('coder'));
        $this->assertSame(['chat', 'coder'], $agentic->agents());
    }

    public function testFactoryHandlesMissingConfig(): void
    {
        $config = $this->createConfigStub([]);

        $services = [
            LlmClient::class => new LlmClient(providerConfigs: ['test' => ['model' => 'test']], defaultProvider: 'test'),
            AgentRunner::class => $this->createMock(AgentRunner::class),
            ToolRegistry::class => new ToolRegistry(),
            PromptBuilder::class => new PromptBuilder(),
            SessionStoreInterface::class => null,
            MessageStoreInterface::class => null,
            PermissionApprovalStoreInterface::class => null,
        ];

        $container = $this->createContainer($config, $services);

        $factory = new AgenticFactory();
        $agentic = $factory($container);

        $this->assertInstanceOf(Agentic::class, $agentic);
        $this->assertSame([], $agentic->agents());
    }

    public function testFactoryHandlesMissingOptionalServices(): void
    {
        $config = $this->createConfigStub([
            'agentic.agents' => ['helper' => ['persona' => 'helper.md']],
        ]);

        $services = [
            LlmClient::class => new LlmClient(providerConfigs: ['test' => ['model' => 'test']], defaultProvider: 'test'),
            AgentRunner::class => $this->createMock(AgentRunner::class),
            ToolRegistry::class => new ToolRegistry(),
            PromptBuilder::class => new PromptBuilder(),
        ];

        $container = $this->createContainer($config, $services, [
            SessionStoreInterface::class,
            MessageStoreInterface::class,
            PermissionApprovalStoreInterface::class,
        ]);

        $factory = new AgenticFactory();
        $agentic = $factory($container);

        $this->assertInstanceOf(Agentic::class, $agentic);
        $this->assertSame(['helper'], $agentic->agents());
    }
}
