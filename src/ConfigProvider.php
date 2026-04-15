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
            // Core
            Persona\PersonaLoader::class => Persona\PersonaLoader::class,

            // Subsystems (Null implementations)
            Contract\ContextEngineInterface::class => NullContextEngine::class,
            Contract\MemoryProviderInterface::class => NullMemoryProvider::class,
        ];
    }
}
