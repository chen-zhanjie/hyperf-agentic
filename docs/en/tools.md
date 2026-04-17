# Tools

Tools are capability units that agents can invoke. The LLM decides which tool to call during the agent loop.

## Registering Tools

### Method 1: Annotation Auto-Discovery (Recommended)

Use the `#[AsTool]` attribute — no manual registration needed:

```php
use ChenZhanjie\Agentic\Attributes\AsTool;
use ChenZhanjie\Agentic\Contract\ToolInterface;

#[AsTool]
class SearchTool implements ToolInterface
{
    public function name(): string { return 'search'; }
    public function description(): string { return 'Search for information'; }
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search keyword'],
            ],
            'required' => ['query'],
        ];
    }
    public function execute(array $arguments): string
    {
        return json_encode(['results' => []]);
    }
    public function isEnabled(): bool { return true; }
    public function isParallelAllowed(): bool { return true; }
}
```

### Method 2: Config File Registration

Register in `config/autoload/agentic/tools.php`:

```php
return [
    'classes' => [
        \App\Tool\SearchTool::class,
    ],
];
```

### Method 3: Runtime Manual Registration

```php
$toolRegistry->register(new SearchTool());
```

## ToolInterface

All tools must implement `ChenZhanjie\Agentic\Contract\ToolInterface`:

```php
interface ToolInterface
{
    public function name(): string;           // Unique tool name
    public function description(): string;    // Description (LLM uses this to decide when to call)
    public function parameters(): array;      // JSON Schema parameter definition
    public function execute(array $arguments): string;  // Execution logic
    public function isEnabled(): bool;        // Whether the tool is enabled
    public function isParallelAllowed(): bool; // Whether parallel invocation is allowed
}
```

### Parameter Definition Format

Use JSON Schema to describe tool parameters, compatible with OpenAI function calling:

```php
public function parameters(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Search keyword',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return',
                'default' => 10,
            ],
        ],
        'required' => ['query'],
    ];
}
```

### Return Values

`execute()` must return a `string`. Return JSON-encoded data for structured results.

## Per-Agent Tool Filtering

Whitelist tools via `agents.php`:

```php
return [
    'chat' => [
        'tools' => ['search', 'ask'],  // Only these two tools
    ],
    'admin' => [
        'tools' => [],                  // Empty = all tools available
    ],
];
```

Dynamic config via `runWithConfig()`:

```php
$agentic->runWithConfig(
    ['tools' => ['search']],
    $messages,
);
```

Uses `ToolRegistry::only()` internally — immutable filter returning a new instance, safe for concurrent requests.

## Built-in Tools

### AskTool

Ask the user for input and wait for a response.

- **Name:** `ask`
- **Supported types:** `confirm`, `select`, `multiselect`, `text`, batch forms
- **Parallel invocation not allowed**

```php
// Example LLM invocation
$arguments = [
    'message' => 'Please confirm to continue',
    'fields' => [
        ['name' => 'confirmed', 'type' => 'confirm', 'label' => 'Confirm'],
    ],
];
```

Requires a `HumanInputResolverInterface` implementation injected via `setHumanInputResolver()`. The SDK provides three built-in resolvers:

| Resolver | Environment | Behavior |
|----------|------------|----------|
| `CliHumanInputResolver` | CLI (Symfony Console) | Blocking interactive prompts via `SymfonyStyle` |
| `HttpHumanInputResolver` | HTTP (Hyperf) | Returns a suspended result for frontend resolution |
| `NullHumanInputResolver` | Testing / non-interactive | Returns default values without prompting |

```php
use ChenZhanjie\Agentic\Resolver\CliHumanInputResolver;

$runner->setHumanInputResolver(new CliHumanInputResolver($io));
```

### SkillTool

Load full skill instructions and resource files.

- **Name:** `skill`
- **Parallel invocation allowed**

```php
// Load full skill instructions
$arguments = ['name' => 'search-guide'];

// Load a skill resource file
$arguments = ['name' => 'search-guide', 'resource' => 'references/query_templates.md'];
```

## ToolRegistry API

```php
$registry->register($tool);                        // Register a tool
$registry->register($tool, 'api', 50000);          // With group and max result size
$registry->resolve('search');                       // Get tool instance
$registry->execute('search', ['query' => 'php']);   // Execute a tool
$registry->only(['search', 'ask']);                 // Immutable filter, returns new instance
$registry->has('search');                           // Check if tool exists
$registry->hasTools();                              // Check if any tools registered
$registry->getAvailableSchemas();                   // OpenAI function calling format
$registry->getAvailableNames();                     // Enabled tool names
$registry->getAvailableDescriptions();              // name => description map
$registry->count();                                 // Total tool count
```

## Custom Tool Example

```php
use ChenZhanjie\Agentic\Contract\ToolInterface;

class DatabaseQueryTool implements ToolInterface
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function name(): string { return 'db_query'; }

    public function description(): string
    {
        return 'Execute a read-only SQL query. Only SELECT statements are allowed.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query statement (SELECT only)',
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function execute(array $arguments): string
    {
        $sql = $arguments['sql'] ?? '';

        // Safety check: only allow SELECT
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            return 'Error: Only SELECT queries are allowed';
        }

        $stmt = $this->pdo->query($sql . ' LIMIT 100');
        return json_encode($stmt->fetchAll(\PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    }

    public function isEnabled(): bool { return true; }
    public function isParallelAllowed(): bool { return false; }
}
```
