<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->dependencies(),
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__ . '/../src',
                    ],
                ],
            ],
            'publish' => [
                [
                    'name' => 'agentic',
                    'source' => __DIR__ . '/../publish/agentic/',
                    'destination' => BASE_PATH . '/config/autoload/agentic/',
                ],
            ],
        ];
    }

    private function dependencies(): array
    {
        return [
            // Layer 1: Foundation interfaces → implementations
            Contract\ContextEngineInterface::class => NullContextEngine::class,
            Contract\MemoryProviderInterface::class => NullMemoryProvider::class,
            Contract\MessageStoreInterface::class => Session\MemoryMessageStore::class,
            Contract\TraceExporterInterface::class => Tracing\LogTraceExporter::class,
            Contract\ToolPermissionPolicyInterface::class => Policy\ConfigToolPermissionPolicy::class,
            Contract\GuardrailAuditLoggerInterface::class => GuardrailAuditLogger::class,
            Contract\PermissionApprovalStoreInterface::class => PermissionApprovalStore::class,

            // Layer 2: Subsystems
            Persona\PersonaLoader::class => Persona\PersonaLoader::class,
            Loader\AnnotationToolLoader::class => Loader\AnnotationToolLoader::class,
            Loader\ConfigToolLoader::class => Loader\ConfigToolLoader::class,
            Loader\SkillLoader::class => Loader\SkillLoader::class,
            Skill\SkillRegistry::class => SkillRegistryFactory::class,
            ToolRegistry::class => ToolRegistryFactory::class,

            // Layer 3a: LLM Layer
            LlmMiddlewarePipeline::class => LlmMiddlewarePipeline::class,
            LlmClient::class => LlmClientFactory::class,

            // Layer 3b: Agent Core
            PromptBuilder::class => PromptBuilder::class,
            GuardrailRunner::class => GuardrailRunner::class,
            ToolGuardrailRunner::class => ToolGuardrailRunner::class,
            AgentMiddlewarePipeline::class => AgentMiddlewarePipeline::class,
            ToolDispatcher::class => ToolDispatcher::class,
            AgentRunner::class => AgentRunner::class,

            // Layer 4: Facade
            Agentic::class => AgenticFactory::class,
        ];
    }
}
