# Getting Started

## Requirements

- PHP >= 8.1
- Hyperf framework
- Composer

## Installation

```bash
composer require chen-zhanjie/hyperf-agentic
```

Hyperf's `config-auto-scan` will automatically discover the `ConfigProvider` — no manual registration needed.

## Publish Config Files

```bash
php bin/hyperf.php vendor:publish chenzhanjie/hyperf-agentic
```

Config files are published to `config/autoload/agentic/`:

| File | Purpose |
|------|---------|
| `agentic.php` | Global defaults (max iterations, context engine, etc.) |
| `providers.php` | LLM provider config (API Key, model) |
| `agents.php` | Agent definitions (persona, tools, skill whitelist) |
| `tools.php` | Tool registration (for classes that can't use annotations) |
| `session.php` | Session store (Redis, etc.) |
| `cli.php` | CLI command config |

## Quick Usage

### 1. Configure an LLM Provider

Edit `config/autoload/agentic/providers.php`:

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

Or use a persona file:

```php
return [
    'chat' => [
        'persona' => BASE_PATH . '/config/autoload/agentic/souls/chat.md',
        'tools' => ['search', 'ask'],
        'skills' => ['search-guide'],
        'max_iterations' => 15,
    ],
];
```

### 3. Use in a Controller

```php
use ChenZhanjie\Agentic\Agentic;
use Hyperf\Di\Annotation\Inject;

class ChatController
{
    #[Inject]
    private Agentic $agentic;

    public function chat()
    {
        $result = $this->agentic->run('general', [
            ['role' => 'user', 'content' => 'Hello!'],
        ]);

        return ['response' => $result->content];
    }
}
```

### 4. Pure LLM Chat (No Tool Loop)

```php
$response = $this->agentic->chat([
    ['role' => 'user', 'content' => 'Translate to English: 你好世界'],
]);
// $response is a string
```

## Next Steps

- [Configuration](configuration.md) — Detailed config reference
- [API Reference](api.md) — Full Agentic facade method reference
- [Tools](tools.md) — Register and create custom tools
- [Skills](skills.md) — 3-level progressive disclosure
- [Database Agents](database-agents.md) — Create agents dynamically from database
