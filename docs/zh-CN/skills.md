# Skills（技能系统）

技能（Skill）是一组结构化操作指南，指导 LLM 在特定任务中遵循规范。技能采用 **3 级渐进式披露**，按需加载以节省 token。

## 3 级渐进式披露

| 级别 | 内容 | 加载时机 | token 成本 |
|------|------|----------|------------|
| Level 1 | 描述索引（1-2 行） | 始终包含在系统提示缓存中 | 低 |
| Level 2 | 完整 SKILL.md 指令 | LLM 通过 SkillTool 按需调用 | 中 |
| Level 3 | 资源文件（references/、scripts/、assets/） | LLM 通过 SkillTool 的 resource 参数按需加载 | 高 |

## 文件系统技能

### 目录结构

```
skills/
└── search-guide/
    ├── SKILL.md              # 技能定义文件
    ├── references/           # 参考文档
    │   └── query_templates.md
    ├── scripts/              # 脚本
    │   └── validate.php
    └── assets/               # 静态资源
        └── schema.json
```

### SKILL.md 格式

使用 YAML frontmatter + Markdown 正文：

```markdown
---
name: search-guide
description: 搜索查询构建指南
tools:
  - search
  - db_query
disable-auto-invoke: false
user-invocable: true
---

# 搜索查询构建指南

## 规则

1. 始终使用参数化查询
2. 限制结果数量不超过 100 条
3. ...
```

**Frontmatter 字段：**

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `name` | string | 目录名 | 技能唯一标识 |
| `description` | string | `''` | 简短描述（用于 Level 1 索引） |
| `tools` | string[] | `[]` | 该技能关联的工具名称列表 |
| `disable-auto-invoke` | bool | `false` | 禁止 LLM 自动调用 |
| `user-invocable` | bool | `true` | 用户是否可显式调用 |

### 自动发现

`SkillLoader` 在启动时扫描 `skills/` 目录，自动注册包含 `SKILL.md` 的子目录：

```php
// ConfigProvider 中已配置
// Loader\SkillLoader::class => Loader\SkillLoader::class
```

## SkillInterface

所有技能必须实现 `ChenZhanjie\Agentic\Contract\SkillInterface`：

```php
interface SkillInterface
{
    public function name(): string;               // 技能名称
    public function description(): string;        // 简短描述
    public function toDescriptionLine(): string;  // Level 1 描述行
    public function toFullInstructions(): string;  // Level 2 完整指令
    public function loadResource(string $relativePath): ?string;  // Level 3 资源
    public function tools(): array;               // 关联的工具名列表
    public function autoInvoke(): bool;           // LLM 是否可自动调用
    public function userInvocable(): bool;        // 用户是否可显式调用
}
```

## 自定义 SkillInterface 实现

适用于从数据库或其他来源加载技能：

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
        return null; // 数据库技能无文件资源
    }

    public function tools(): array { return $this->tools; }
    public function autoInvoke(): bool { return true; }
    public function userInvocable(): bool { return true; }
}
```

注册到 SkillRegistry：

```php
$skillRegistry->register(new DatabaseSkill(
    name: 'product-search',
    description: '商品搜索最佳实践',
    content: $skill->instructions,  // 从数据库读取
    tools: ['search', 'db_query'],
));
```

## Per-Agent 技能过滤

通过 `agents.php` 的 `skills` 配置白名单：

```php
return [
    'chat' => [
        'skills' => ['search-guide', 'product-search'],
    ],
];
```

通过 `runWithConfig()` 动态配置：

```php
$agentic->runWithConfig(
    ['skills' => ['search-guide']],
    $messages,
);
```

## SkillRegistry API

```php
$registry->register($skill);                                // 注册技能
$registry->loadFromDirectory('/path/to/skills');            // 从目录批量加载
$registry->get('search-guide');                              // 获取技能实例
$registry->allNames();                                       // 所有技能名称
$registry->count();                                          // 技能总数
$registry->getAutoInvocable(['search-guide']);              // 可自动调用的技能
$registry->getUserInvocable();                               // 用户可调用的技能
$registry->buildDescriptionIndex(['search-guide']);         // Level 1 描述索引文本
$registry->getSkillTools(['search-guide']);                  // 关联的工具名列表
```
