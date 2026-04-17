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
            'protocol' => 'openai',
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

## Local Debug CLI

The SDK includes a standalone `debug.php` script for local debugging without the Hyperf framework. It supports interactive tool calls, ask-tool prompts (confirm / select / multiselect), and streaming output.

### Setup

Copy `.env.test.example` to `.env.test` and fill in your API credentials:

```bash
cp .env.test.example .env.test
# Edit .env.test with your API keys
```

### Start a Session

```bash
php debug.php                         # OpenAI protocol, default model
php debug.php --protocol anthropic    # Anthropic protocol
php debug.php --model gpt-4o-mini     # Override model
php debug.php --stream                # Streaming mode
```

### Session Commands

| Command | Description |
|---------|-------------|
| `/quit`, `/exit` | Exit the session |
| `/reset` | Clear conversation history |
| `/stream` | Toggle streaming mode |
| `/model <name>` | Switch model mid-session |

### Built-in Tools

The debug CLI registers 3 tools for testing:

| Tool | Description |
|------|-------------|
| `get_time` | Get current time in any timezone |
| `calculate` | Evaluate math expressions |
| `ask` | Interactive user input (confirm / select / multiselect / text) |

### Example Prompts

Try these prompts to exercise different interaction patterns:

| Scenario | Example Prompt | Expected Behavior |
|----------|---------------|-------------------|
| Tool call | `What time is it in Tokyo?` | Calls `get_time` with `Asia/Tokyo` |
| Math | `Calculate 123 * 456 + 789` | Calls `calculate` |
| Confirm | `Confirm deletion of all temporary files` | Asks for confirmation via `ask` (confirm) |
| Select | `Help me choose one coffee from Americano, Latte, or Cappuccino` | Shows a single-choice menu via `ask` (select) |
| Multiselect | `I want some fruit, pick a few from Apple, Banana, Orange, and Grape` | Shows a multi-choice menu via `ask` (multiselect) |
| Multi-tool | `Calculate (15 + 27) * 3, then tell me what time it is now` | Calls `calculate` then `get_time` |
| Streaming | Type `/stream` first, then chat | See real-time token output with reasoning |

> **Note:** The LLM decides which tool to call and how to structure the `ask` fields. Different models may produce slightly different tool call patterns. The prompts above are designed to reliably trigger each interaction type.

## Next Steps

- [Configuration](configuration.md) — Detailed config reference
- [API Reference](api.md) — Full Agentic facade method reference
- [Tools](tools.md) — Register and create custom tools
- [Skills](skills.md) — 3-level progressive disclosure
- [Database Agents](database-agents.md) — Create agents dynamically from database
