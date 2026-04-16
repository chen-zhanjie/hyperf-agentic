# Configuration Reference

All config files are located at `config/autoload/agentic/`.

## agentic.php — Global Config

```php
return [
    'default_agent' => 'general',
    'max_iterations' => 15,
    'max_total_tokens' => 200000,
    'grace_turn' => true,
    'context_engine' => null,     // null | 'sliding_window' | 'summary'
    'memory_provider' => null,    // null | 'redis'
];
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `default_agent` | string | `'general'` | Default agent name when none specified |
| `max_iterations` | int | `15` | Max iteration rounds in the agent loop |
| `max_total_tokens` | int | `200000` | Max total token budget per run |
| `grace_turn` | bool | `true` | Allow a final "grace" turn after budget exhaustion |
| `context_engine` | string\|null | `null` | Context compression strategy |
| `memory_provider` | string\|null | `null` | Memory provider |

## providers.php — LLM Providers

```php
return [
    'default' => 'openai',
    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ],
        'deepseek' => [
            'driver' => 'openai',       // OpenAI-compatible protocol
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => 'https://api.deepseek.com/v1',
            'model' => 'deepseek-chat',
            'max_tokens' => 4096,
        ],
    ],
];
```

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `default` | string | yes | Default provider name |
| `providers` | array | yes | Provider config map |
| `providers.{name}.driver` | string | yes | Driver type: `openai` |
| `providers.{name}.api_key` | string | yes | API key |
| `providers.{name}.base_url` | string | yes | API base URL |
| `providers.{name}.model` | string | yes | Model name |
| `providers.{name}.max_tokens` | int | no | Max tokens per request |
| `providers.{name}.temperature` | float | no | Generation temperature (0.0 - 2.0) |

## agents.php — Agent Definitions

```php
return [
    'chat' => [
        'persona' => BASE_PATH . '/config/autoload/agentic/souls/chat.md',
        'tools' => ['search', 'ask'],
        'skills' => ['search-guide'],
        'guardrails' => [],
        'max_iterations' => 15,
        'system_prompt' => '',
    ],
    'coder' => [
        'persona' => 'You are an expert programmer.',
        'tools' => [],
        'max_iterations' => 20,
    ],
];
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `persona` | string\|null | `null` | Persona file path or inline text |
| `tools` | string[] | `[]` | Tool whitelist (empty = all available) |
| `skills` | string[] | `[]` | Skill whitelist (empty = all available) |
| `guardrails` | string[] | `[]` | Guardrail whitelist (empty = all active) |
| `guardrail_modes` | array | `[]` | Guardrail mode overrides: `['name' => 'async']` |
| `tool_permissions` | array | `[]` | Permission rules: `['allow' => [...], 'ask' => [...], 'deny' => [...]]` |
| `max_iterations` | int\|null | `null` | Override global max_iterations |
| `system_prompt` | string | `''` | Additional system prompt text |
| `cancellation_timeout_ms` | int | `0` | Auto-cancel after N ms (0 = disabled) |
| `async_guardrail_timeout` | int | `5000` | Timeout for async guardrail completion (ms) |

## tools.php — Tool Registration

```php
return [
    'classes' => [
        \App\Tool\SearchTool::class,
        \App\Tool\CalculatorTool::class,
    ],
];
```

Tools using the `#[AsTool]` annotation are auto-discovered and don't need manual registration here.

## session.php — Session Store

```php
return [
    'driver' => \ChenZhanjie\Agentic\Session\RedisSessionStore::class,
    'ttl' => 3600,
    'prefix' => 'agentic:session:',
];
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `driver` | string | RedisSessionStore | SessionStoreInterface implementation |
| `ttl` | int | `3600` | Session TTL in seconds |
| `prefix` | string | `'agentic:session:'` | Redis key prefix |

## cli.php — CLI Commands

```php
return [
    'commands' => [],
    'default_agent' => 'general',
];
```
