# Hyperf Agentic SDK

[简体中文](README.zh-CN.md) | English

A config-driven AI Agent SDK for Hyperf applications. **Agent is a config, not a class.**

## Installation

```bash
composer require chen-zhanjie/hyperf-agentic
```

Publish config files:

```bash
php bin/hyperf.php vendor:publish chenzhanjie/hyperf-agentic
```

## Quick Start

### 1. Configure LLM Provider

Edit `config/autoload/agentic/providers.php`:

```php
return [
    'default' => 'openai',
    'providers' => [
        'openai' => [
            'driver'   => 'openai',
            'api_key'  => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
            'model'    => 'gpt-4o',
        ],
    ],
];
```

### 2. Define an Agent

Edit `config/autoload/agentic/agents.php`:

```php
return [
    'general' => [
        'persona' => 'You are a helpful assistant.',
        'max_iterations' => 10,
    ],
];
```

### 3. Run the Agent

```php
use ChenZhanjie\Agentic\Agentic;

$agentic = $this->container->get(Agentic::class);

$result = $agentic->run('general', [
    ['role' => 'user', 'content' => 'Hello!'],
]);

echo $result->content;
```

### Database-Driven Agents (v0.4.0)

Create agents dynamically from database config:

```php
use ChenZhanjie\Agentic\Persona\Persona;

$result = $agentic->runWithConfig(
    [
        'persona' => new Persona(name: 'Expert', content: 'You are an expert.'),
        'tools' => ['search'],
        'skills' => ['search-guide'],
        'max_iterations' => 15,
    ],
    [['role' => 'user', 'content' => 'Hello']],
    ['conversation_id' => 'conv-123'],  // auto history management
);
```

## Core API

| Method | Description |
|--------|-------------|
| `run(name, messages)` | Execute a named agent |
| `runStream(name, messages, onEvent)` | Execute with SSE streaming |
| `runWithConfig(config, messages, options)` | Execute with dynamic config |
| `runStreamWithConfig(config, messages, onEvent, options)` | Dynamic config + streaming |
| `chat(messages)` | Pure LLM chat (no tools) |
| `chatStream(messages, onChunk)` | Pure LLM streaming |
| `resume(sessionId)` | Resume a suspended session |

## Documentation

| Document | Description |
|----------|-------------|
| [Getting Started](docs/getting-started.md) | Installation and quick start |
| [Configuration](docs/configuration.md) | Full configuration reference |
| [API Reference](docs/api.md) | Agentic facade method reference |
| [Tools](docs/tools.md) | Tool system: registration, custom tools, built-ins |
| [Skills](docs/skills.md) | 3-level progressive disclosure skill system |
| [Guardrails](docs/guardrails.md) | Input/output safety checks |
| [Conversations](docs/conversations.md) | Multi-turn conversation persistence |
| [Database Agents](docs/database-agents.md) | Database-driven dynamic agent creation |
| [Architecture](docs/architecture.md) | 5-layer architecture overview |
| [Changelog](docs/changelog.md) | Version history |

## Architecture

```
Layer 5: Entry Points (Controller / Command / CLI)
    ↓
Layer 4: Agentic Facade (config-driven entry point)
    ↓
Layer 3: Agent Core (AgentRunner + GuardrailRunner + MiddlewarePipeline)
    ↓
Layer 2: Subsystems (ToolRegistry / PromptBuilder / LlmClient / SkillRegistry)
    ↓
Layer 1: Foundation (Contract/ — interfaces, zero upstream dependencies)
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

374 tests, 637 assertions — all passing.

## License

MIT
