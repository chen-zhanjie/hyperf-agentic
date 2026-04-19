<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

use ChenZhanjie\Agentic\Contract\MessageStoreInterface;
use ChenZhanjie\Agentic\Loader\AnnotationToolLoader;
use ChenZhanjie\Agentic\Loader\ConfigToolLoader;
use ChenZhanjie\Agentic\Session\MemoryMessageStore;
use ChenZhanjie\Agentic\Skill\SkillRegistry;
use ChenZhanjie\Agentic\Tool\Builtin\AskTool;
use ChenZhanjie\Agentic\Tool\Builtin\RecallTool;
use ChenZhanjie\Agentic\Tool\Builtin\SkillTool;
use Psr\Container\ContainerInterface;

/**
 * ToolRegistry factory — loads all tools at Hyperf startup.
 * Order: annotation tools → config tools → built-in tools (AskTool, SkillTool).
 */
class ToolRegistryFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function __invoke(): ToolRegistry
    {
        $registry = new ToolRegistry();

        // 1. Annotation-discovered tools
        $this->container->get(AnnotationToolLoader::class)->load($registry);

        // 2. Config-declared tools
        $this->container->get(ConfigToolLoader::class)->load($registry);

        // 3. Built-in tools (always included in agent tool whitelists)
        $registry->register($this->container->get(AskTool::class), builtin: true);

        // 4. RecallTool (system-level message recall)
        $messageStore = $this->container->get(MessageStoreInterface::class);
        $registry->register(new RecallTool($messageStore), builtin: true);

        // 5. SkillTool — only when SkillRegistry is available
        $skillRegistry = $this->container->get(SkillRegistry::class);
        if ($skillRegistry !== null) {
            $registry->register(new SkillTool($skillRegistry), builtin: true);
        }

        return $registry;
    }
}
