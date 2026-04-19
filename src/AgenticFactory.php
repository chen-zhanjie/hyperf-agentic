<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Contract\PermissionApprovalStoreInterface;
use ChenZhanjie\Agentic\Contract\SessionStoreInterface;
use Psr\Container\ContainerInterface;

class AgenticFactory
{
    public function __invoke(ContainerInterface $container): Agentic
    {
        $config = $container->get(\Hyperf\Contract\ConfigInterface::class);

        $agentDefs = $config->get('agentic.agents', []);
        $defaults = $config->get('agentic.agentic', []);

        $llmClient = $container->get(LlmClient::class);
        $runner = $container->get(AgentRunner::class);
        $toolRegistry = $container->get(ToolRegistry::class);
        $promptBuilder = $container->get(PromptBuilder::class);

        $sessionStore = $this->optionalGet($container, SessionStoreInterface::class);
        $messageStore = $this->optionalGet($container, MessageStoreInterface::class);
        $approvalStore = $this->optionalGet($container, PermissionApprovalStoreInterface::class);

        return new Agentic(
            llmClient: $llmClient,
            runner: $runner,
            toolRegistry: $toolRegistry,
            promptBuilder: $promptBuilder,
            sessionStore: $sessionStore,
            messageStore: $messageStore,
            approvalStore: $approvalStore,
            agentDefs: $agentDefs,
            defaults: $defaults,
        );
    }

    private function optionalGet(ContainerInterface $container, string $id): mixed
    {
        try {
            return $container->get($id);
        } catch (\Throwable) {
            return null;
        }
    }
}
