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
            'protocol' => 'openai',
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
| `approveTool(tool, sessionId)` | Approve a tool/pattern globally or per-session |
| `approveAll(sessionId)` | Approve all tools globally or per-session |
| `revokeTool(tool, sessionId)` | Revoke a tool approval |
| `revokeAll(sessionId)` | Revoke all approvals |

## Documentation

| Document | Description |
|----------|-------------|
| [Getting Started](docs/en/getting-started.md) | Installation and quick start |
| [Configuration](docs/en/configuration.md) | Full configuration reference |
| [API Reference](docs/en/api.md) | Agentic facade method reference |
| [Tools](docs/en/tools.md) | Tool system: registration, custom tools, built-ins |
| [Skills](docs/en/skills.md) | 3-level progressive disclosure skill system |
| [Guardrails](docs/en/guardrails.md) | Input/output safety checks |
| [Conversations](docs/en/conversations.md) | Multi-turn conversation persistence |
| [Database Agents](docs/en/database-agents.md) | Database-driven dynamic agent creation |
| [Architecture](docs/en/architecture.md) | 5-layer architecture overview |
| [Changelog](docs/en/changelog.md) | Version history |

> 简体中文文档：[docs/zh-CN/](docs/zh-CN/)

## Architecture

```
Layer 5: Entry Points (Controller / Command / CLI)
    ↓
Layer 4: Agentic Facade (config-driven entry point)
    ↓
Layer 3: Agent Core (AgentRunner + ToolDispatcher + LoopState + GuardrailRunner + MiddlewarePipeline)
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

575 tests, 1146 assertions — all passing.

> **Note:** Run `vendor/bin/phpunit` after install. Test count reflects latest version.

## License

MIT
