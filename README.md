# Hyperf Agentic SDK

[简体中文](README.zh-CN.md) | English

A config-driven AI Agent SDK for Hyperf applications. Define agents as configuration, not classes.

## Installation

```bash
composer require chenzhanjie/hyperf-agentic
```

Publish the config files:

```bash
php bin/hyperf.php vendor:publish chenzhanjie/hyperf-agentic
```

This creates `config/autoload/agentic/` with:

```
config/autoload/agentic/
├── agentic.php      # Global defaults (iterations, budget, grace)
├── agents.php       # Agent definitions
├── providers.php    # LLM provider configs (OpenAI, etc.)
├── tools.php        # Tool class registration
├── session.php      # Session store config
├── cli.php          # CLI command registration
└── souls/           # Persona markdown files
    └── chat.md
```

## Quick Start

### 1. Configure an LLM Provider

Edit `config/autoload/agentic/providers.php`:

```php
return [
    'default' => 'openai',
    'providers' => [
        'openai' => [
            'driver'    => 'openai',
            'api_key'   => env('OPENAI_API_KEY'),
            'base_url'  => 'https://api.openai.com/v1',
            'model'     => 'gpt-4o',
        ],
    ],
];
```

### 2. Define an Agent

Edit `config/autoload/agentic/agents.php`:

```php
return [
    'default' => [
        'persona'        => 'default.md',
        'tools'          => ['*'],
        'max_iterations' => 10,
    ],
];
```

### 3. Use the Agent

```php
use ChenZhanjie\Agentic\Agentic;

// Inject via Hyperf DI
$agentic = $this->container->get(Agentic::class);

// Run an agent
$result = $agentic->run('default', [
    ['role' => 'user', 'content' => 'Hello!'],
]);

echo $result->content;
```

## Core API

### Agent Execution

```php
// Execute a named agent (full loop with tools)
$result = $agentic->run('default', $messages, $options);

// Execute with SSE streaming
$result = $agentic->runStream('default', $messages, function (string $type, array $payload) {
    echo "event: {$type}\ndata: " . json_encode($payload) . "\n\n";
});

// Pure LLM chat (no agent loop, no tools)
$response = $agentic->chat($messages);

// Pure LLM streaming chat
$agentic->chatStream($messages, function (string $chunk) {
    echo $chunk;
});
```

### Resume Suspended Agent

```php
// Resume a previously suspended session (e.g. after human input)
$result = $agentic->resume($sessionId);
```

### Agent Introspection

```php
$agentic->agents();          // List all defined agent names
$agentic->tools();           // List all registered tool names
$agentic->has('default');    // Check if an agent exists
$agentic->persona('default'); // Get the persona for an agent
```

## AgentResult

Every `run()` and `runStream()` call returns an `AgentResult`:

```php
$result->content;            // The agent's text response
$result->iterations;         // Number of iterations used
$result->elapsedMs;          // Execution time in milliseconds
$result->promptTokens;       // Total prompt tokens consumed
$result->completionTokens;   // Total completion tokens consumed
$result->toolCalls;          // Total tool calls made
$result->toArray();          // Serialize to array
```

## Custom Tools

### Annotation-based (Recommended)

```php
use ChenZhanjie\Agentic\Attribute\AsTool;
use ChenZhanjie\Agentic\Contract\ToolInterface;

#[AsTool]
class SearchTool implements ToolInterface
{
    public function name(): string { return 'search'; }
    public function description(): string { return 'Search the knowledge base'; }
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
            ],
            'required' => ['query'],
        ];
    }
    public function isEnabled(): bool { return true; }
    public function isParallelAllowed(): bool { return true; }

    public function execute(array $arguments): array|string
    {
        return "Results for: {$arguments['query']}";
    }
}
```

Annotation scanning is automatic via Hyperf DI.

### Config-based

Register in `config/autoload/agentic/tools.php`:

```php
return [
    'classes' => [
        \App\AgentTools\SearchTool::class,
    ],
];
```

## Personas (SOUL.md)

Personas define agent personality and behavior. Place markdown files in `config/autoload/agentic/souls/`.

**The markdown file IS the system prompt** — write whatever you want, no format restrictions.

```markdown
# Support Agent

You are a customer support specialist for our SaaS platform.
Your goal is to resolve user issues quickly and accurately.

## Guidelines

- Always greet the user before answering
- Ask clarifying questions when the request is ambiguous
- Escalate to a human if you're unsure

## Tone

Professional but warm. Use the user's first name if provided.
```

**Best practices:**
- Start with `# Agent Name` as the H1 — this is extracted as the agent's display name
- Use `##` headings to organize sections — helps both you and the LLM navigate the prompt
- Keep it concise: long prompts increase token cost and reduce instruction-following accuracy
- Be specific: "respond in under 100 words" > "be brief"

Reference in agent config:

```php
'default' => [
    'persona' => 'support.md', // matches souls/support.md
],
```

## Skills (SKILL.md)

Skills provide progressive disclosure of operational guidelines. Create a skill directory:

```
config/autoload/agentic/skills/
└── query-templates/
    ├── SKILL.md          # Instructions + YAML frontmatter
    ├── references/       # Reference documents
    │   └── templates.md
    └── scripts/          # Executable scripts
```

SKILL.md format:

```markdown
---
name: query-templates
description: SQL query template library
tools:
  - search
---

# Query Templates

Guidelines for generating SQL queries...
```

## Middleware

Built-in middleware hooks:

| Hook | Description |
|------|-------------|
| `beforeLoop` | Before the agent loop starts |
| `afterLoop` | After the agent loop completes |
| `beforeLlmCall` | Before each LLM API call |
| `afterLlmCall` | After each LLM API call |
| `beforeToolCall` | Before tool dispatch (can intercept) |
| `afterToolCall` | After tool execution |

Custom middleware:

```php
use ChenZhanjie\Agentic\Contract\MiddlewareInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    public function beforeToolCall(string $name, array $arguments): ?string
    {
        logger()->info("Tool called: {$name}");
        return null; // return a string to intercept
    }
    // ... implement other methods
}
```

## Guardrails

Input/output safety checks:

```php
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\GuardrailResult;

class SafetyGuard implements GuardrailInterface
{
    public function checkInput(array $messages): GuardrailResult
    {
        foreach ($messages as $msg) {
            if (str_contains($msg['content'] ?? '', 'dangerous')) {
                return GuardrailResult::tripwire('Unsafe content detected');
            }
        }
        return GuardrailResult::ok();
    }

    public function checkOutput(string $content): GuardrailResult
    {
        return GuardrailResult::ok();
    }
}
```

## Human Input (Ask Tool)

### CLI Mode

```php
use ChenZhanjie\Agentic\Resolver\CliHumanInputResolver;

$resolver = new CliHumanInputResolver($symfonyStyle);
$agentic->setHumanInputResolver($resolver);
```

### HTTP Mode (Non-blocking)

```php
use ChenZhanjie\Agentic\Resolver\HttpHumanInputResolver;

$resolver = new HttpHumanInputResolver($sessionStore, 'session-123');
$agentic->setHumanInputResolver($resolver);

// Agent throws AgentSuspendedException when it needs input
// Resume later with the session ID
$result = $agentic->resume('session-123');
```

## Budget Control

### Iteration Budget

```php
// In agent config
'max_iterations' => 10,  // Max LLM call rounds
```

Includes a **grace turn** — one extra round after budget exhaustion for the LLM to wrap up cleanly.

### Token Budget

```php
// In agent config
'max_cost_tokens' => 100000,  // Max total tokens (prompt + completion)
```

Warns at 80% usage, stops at 100%.

## CLI Command

Interactive agent chat:

```bash
# Chat with default agent
php bin/hyperf.php agent:chat

# Specify agent
php bin/hyperf.php agent:chat support

# Unattended mode
php bin/hyperf.php agent:chat --no-input

# Override model
php bin/hyperf.php agent:chat -m gpt-4o-mini
```

## Architecture

5-layer vertical architecture with strict one-way dependency:

```
Layer 5: Entry Points (Controller / Command / CLI)
    ↓
Layer 4: Agentic Facade (Agentic.php — config-driven entry point)
    ↓
Layer 3: Agent Core (AgentRunner + GuardrailRunner + MiddlewarePipeline)
    ↓
Layer 2: Subsystems (ToolRegistry / PromptBuilder / LlmClient / PersonaLoader / SkillRegistry)
    ↓
Layer 1: Foundation (Contract/ — interfaces, zero upstream dependencies)
```

### Prompt Builder (7-Layer)

The system prompt is assembled from 7 layers:

| Layer | Type | Content |
|-------|------|---------|
| 1 | Cached | Persona (SOUL.md) |
| 2 | Cached | SDK base prompt |
| 3 | Cached | Agent custom system prompt |
| 4 | Cached | Tool boundary declarations |
| 5 | Cached | Scene + skills + memory |
| 6 | Ephemeral | Runtime context (timestamp, capabilities) |
| 7 | Ephemeral | Budget warnings / Grace message |

Cached layers are built once per session. Ephemeral layers are rebuilt each turn.

## Testing

```bash
composer install
vendor/bin/phpunit
```

341 tests, 596 assertions — all passing.

## License

MIT
