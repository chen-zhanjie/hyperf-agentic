# Database-Driven Agents

When agent configurations are stored in a database (e.g., agents created via an admin panel), use `runWithConfig()` to bypass config file lookup and pass dynamic configurations directly.

## Core Methods

```php
// Non-streaming
$agentic->runWithConfig(array $agentConfig, array $messages, array $options = []): AgentResult

// Streaming
$agentic->runStreamWithConfig(array $agentConfig, array $messages, ?callable $onEvent = null, array $options = []): AgentResult
```

## Agent Config Structure

```php
$agentConfig = [
    // Persona (required)
    'persona' => new Persona(name: 'Expert', content: 'You are an expert.'),

    // Tool whitelist (optional, empty = all available)
    'tools' => ['search', 'db_query'],

    // Skill whitelist (optional, empty = all available)
    'skills' => ['search-guide'],

    // Guardrail whitelist (optional, empty = all active)
    'guardrails' => ['content_filter'],

    // Max iterations (optional, overrides global default)
    'max_iterations' => 15,

    // Additional system prompt (optional)
    'system_prompt' => 'Always respond in Chinese.',
];
```

The config is merged with global `defaults` (from `agentic.php`), with `$agentConfig` taking precedence.

## Typical Usage

### Database Model

Assuming an Agent Eloquent model:

```php
// app/Model/Agent.php
class Agent extends Model
{
    protected $fillable = [
        'name', 'persona_text', 'system_prompt',
        'tools', 'skills', 'max_iterations',
    ];

    protected $casts = [
        'tools' => 'json',
        'skills' => 'json',
    ];
}
```

### Controller Usage

```php
use ChenZhanjie\Agentic\Agentic;
use ChenZhanjie\Agentic\Persona\Persona;
use Hyperf\Di\Annotation\Inject;

class AgentChatController
{
    #[Inject]
    private Agentic $agentic;

    public function chat(int $agentId, string $message)
    {
        // 1. Load agent config from database
        $agent = Agent::find($agentId);

        // 2. Build dynamic config
        $agentConfig = [
            'persona' => new Persona(
                name: $agent->name,
                content: $agent->persona_text,
            ),
            'tools' => $agent->tools ?? [],
            'skills' => $agent->skills ?? [],
            'max_iterations' => $agent->max_iterations ?? 15,
            'system_prompt' => $agent->system_prompt ?? '',
        ];

        // 3. Execute
        $result = $this->agentic->runWithConfig(
            $agentConfig,
            [['role' => 'user', 'content' => $message]],
            ['conversation_id' => "agent-{$agentId}-user-{$userId}"],
        );

        return [
            'response' => $result->content,
            'iterations' => $result->iterations,
            'tokens' => $result->promptTokens + $result->completionTokens,
        ];
    }
}
```

### With Conversation Persistence

```php
// First turn
$result1 = $agentic->runWithConfig(
    $agentConfig,
    [['role' => 'user', 'content' => 'Help me analyze this code']],
    ['conversation_id' => 'conv-abc123'],
);

// Second turn — history auto-loaded
$result2 = $agentic->runWithConfig(
    $agentConfig,
    [['role' => 'user', 'content' => 'Any other issues?']],
    ['conversation_id' => 'conv-abc123'],
);
```

### Streaming Response (SSE)

```php
public function stream(int $agentId)
{
    return $this->response->withHeader('Content-Type', 'text/event-stream')->stream(function () use ($agentId) {
        $agent = Agent::find($agentId);
        $agentConfig = $this->buildConfig($agent);

        $this->agentic->runStreamWithConfig(
            $agentConfig,
            [['role' => 'user', 'content' => $request->input('message')]],
            function (string $event, array $data) {
                echo "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            },
            ['conversation_id' => "conv-{$agentId}"],
        );
    });
}
```

## Comparison: Config File vs. Dynamic Agents

| Feature | `run()` | `runWithConfig()` |
|---------|---------|-------------------|
| Config source | `agents.php` config file | Dynamic array parameter |
| Use case | Predefined static agents | Database-driven dynamic agents |
| Persona support | File path or string | `Persona` object |
| Conversation persistence | Not supported | Via `conversation_id` |
| Default merging | `defaults` + agent def | `defaults` + passed config |
| Guardrail filtering | Supported | Supported |
| Skill filtering | Supported | Supported |

## Dynamic Skill Registration

Load skills from database and register with SkillRegistry:

```php
use ChenZhanjie\Agentic\Contract\SkillInterface;

class DatabaseSkill implements SkillInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $content,
        private readonly array $tools = [],
    ) {}

    public function name(): string { return $this->name; }
    public function description(): string { return $this->description; }
    public function toDescriptionLine(): string
    {
        return "- **{$this->name}**: {$this->description}";
    }
    public function toFullInstructions(): string { return $this->content; }
    public function loadResource(string $relativePath): ?string { return null; }
    public function tools(): array { return $this->tools; }
    public function autoInvoke(): bool { return true; }
    public function userInvocable(): bool { return true; }
}

// Register during app initialization
$skillRegistry = $container->get(SkillRegistry::class);
foreach (Skill::all() as $skill) {
    $skillRegistry->register(new DatabaseSkill(
        name: $skill->name,
        description: $skill->description,
        content: $skill->instructions,
        tools: $skill->tools ?? [],
    ));
}
```
