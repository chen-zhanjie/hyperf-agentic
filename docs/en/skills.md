# Skills

Skills are structured operational guidelines that instruct the LLM to follow specific standards when performing tasks. Skills use a **3-level progressive disclosure** model — loaded on demand to save tokens.

## 3-Level Progressive Disclosure

| Level | Content | When Loaded | Token Cost |
|-------|---------|-------------|------------|
| Level 1 | Description index (1-2 lines) | Always included in cached system prompt | Low |
| Level 2 | Full SKILL.md instructions | On-demand via SkillTool call from LLM | Medium |
| Level 3 | Resource files (references/, scripts/, assets/) | On-demand via SkillTool's resource parameter | High |

## Filesystem Skills

### Directory Structure

```
skills/
└── search-guide/
    ├── SKILL.md              # Skill definition file
    ├── references/           # Reference documents
    │   └── query_templates.md
    ├── scripts/              # Scripts
    │   └── validate.php
    └── assets/               # Static assets
        └── schema.json
```

### SKILL.md Format

Uses YAML frontmatter + Markdown body:

```markdown
---
name: search-guide
description: Search query construction guide
tools:
  - search
  - db_query
disable-auto-invoke: false
user-invocable: true
---

# Search Query Construction Guide

## Rules

1. Always use parameterized queries
2. Limit results to no more than 100 rows
3. ...
```

**Frontmatter fields:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `name` | string | Directory name | Unique skill identifier |
| `description` | string | `''` | Brief description (for Level 1 index) |
| `tools` | string[] | `[]` | Associated tool names |
| `disable-auto-invoke` | bool | `false` | Prevent LLM from auto-invoking |
| `user-invocable` | bool | `true` | Whether users can explicitly invoke |

### Auto-Discovery

`SkillLoader` scans the `skills/` directory at startup and auto-registers subdirectories containing `SKILL.md`:

```php
// Already configured in ConfigProvider
// Loader\SkillLoader::class => Loader\SkillLoader::class
```

## SkillInterface

All skills must implement `ChenZhanjie\Agentic\Contract\SkillInterface`:

```php
interface SkillInterface
{
    public function name(): string;               // Skill name
    public function description(): string;        // Brief description
    public function toDescriptionLine(): string;  // Level 1 description line
    public function toFullInstructions(): string;  // Level 2 full instructions
    public function loadResource(string $relativePath): ?string;  // Level 3 resource
    public function tools(): array;               // Associated tool names
    public function autoInvoke(): bool;           // Whether LLM can auto-invoke
    public function userInvocable(): bool;        // Whether users can explicitly invoke
}
```

## Custom SkillInterface Implementation

For loading skills from a database or other sources:

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

    public function toFullInstructions(): string
    {
        return $this->content;
    }

    public function loadResource(string $relativePath): ?string
    {
        return null; // Database skills have no file resources
    }

    public function tools(): array { return $this->tools; }
    public function autoInvoke(): bool { return true; }
    public function userInvocable(): bool { return true; }
}
```

Register with SkillRegistry:

```php
$skillRegistry->register(new DatabaseSkill(
    name: 'product-search',
    description: 'Product search best practices',
    content: $skill->instructions,  // Read from database
    tools: ['search', 'db_query'],
));
```

## Per-Agent Skill Filtering

Whitelist skills via `agents.php`:

```php
return [
    'chat' => [
        'skills' => ['search-guide', 'product-search'],
    ],
];
```

Dynamic config via `runWithConfig()`:

```php
$agentic->runWithConfig(
    ['skills' => ['search-guide']],
    $messages,
);
```

## SkillRegistry API

```php
$registry->register($skill);                                // Register a skill
$registry->loadFromDirectory('/path/to/skills');            // Batch load from directory
$registry->get('search-guide');                              // Get skill instance
$registry->allNames();                                       // All skill names
$registry->count();                                          // Skill count
$registry->getAutoInvocable(['search-guide']);              // Auto-invocable skills
$registry->getUserInvocable();                               // User-invocable skills
$registry->buildDescriptionIndex(['search-guide']);         // Level 1 description index text
$registry->getSkillTools(['search-guide']);                  // Associated tool names
```
