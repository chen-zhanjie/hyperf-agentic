# Tools

工具是 Agent 可以调用的能力单元。LLM 在循环中决定何时调用哪个工具。

## 注册工具

### 方式一：注解自动发现（推荐）

使用 `#[AsTool]` 注解，无需手动注册：

```php
use ChenZhanjie\Agentic\Attributes\AsTool;
use ChenZhanjie\Agentic\Contract\ToolInterface;

#[AsTool]
class SearchTool implements ToolInterface
{
    public function name(): string { return 'search'; }
    public function description(): string { return '搜索信息'; }
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => '搜索关键词'],
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

### 方式二：配置文件注册

在 `config/autoload/agentic/tools.php` 中注册：

```php
return [
    'classes' => [
        \App\Tool\SearchTool::class,
    ],
];
```

### 方式三：运行时手动注册

```php
$toolRegistry->register(new SearchTool());
```

## ToolInterface

所有工具必须实现 `ChenZhanjie\Agentic\Contract\ToolInterface`：

```php
interface ToolInterface
{
    public function name(): string;           // 工具唯一名称
    public function description(): string;    // 描述（LLM 根据此决定是否调用）
    public function parameters(): array;      // JSON Schema 格式的参数定义
    public function execute(array $arguments): string;  // 执行逻辑
    public function isEnabled(): bool;        // 是否启用
    public function isParallelAllowed(): bool; // 是否允许并行调用
}
```

### 参数定义格式

使用 JSON Schema 描述工具参数，兼容 OpenAI function calling：

```php
public function parameters(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => '搜索关键词',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => '返回结果数量',
                'default' => 10,
            ],
        ],
        'required' => ['query'],
    ];
}
```

### 返回值

`execute()` 必须返回 `string`。需要返回结构化数据时，使用 `json_encode()` 编码后返回。

## Per-Agent 工具过滤

通过 `agents.php` 的 `tools` 配置白名单：

```php
return [
    'chat' => [
        'tools' => ['search', 'ask'],  // 仅允许这两个工具
    ],
    'admin' => [
        'tools' => [],                  // 空数组 = 所有工具可用
    ],
];
```

通过 `runWithConfig()` 动态配置：

```php
$agentic->runWithConfig(
    ['tools' => ['search']],
    $messages,
);
```

底层使用 `ToolRegistry::only()` — 不可变过滤，返回新实例，不影响其他请求。

## 内置工具

### AskTool

向用户提问并等待回复。

- **名称：** `ask`
- **支持类型：** `confirm`（确认）、`select`（单选）、`multiselect`（多选）、`text`（文本输入）、批量表单
- **不支持并行调用**

```php
// LLM 调用示例
$arguments = [
    'message' => '请确认是否继续',
    'fields' => [
        ['name' => 'confirmed', 'type' => 'confirm', 'label' => '确认'],
    ],
];
```

需要通过 `setHumanInputResolver()` 注入 `HumanInputResolverInterface` 实现。SDK 提供三个内置解析器：

| 解析器 | 适用环境 | 行为 |
|--------|---------|------|
| `CliHumanInputResolver` | CLI（Symfony Console） | 通过 `SymfonyStyle` 阻塞式交互提示 |
| `HttpHumanInputResolver` | HTTP（Hyperf） | 返回挂起结果，由前端解析后恢复 |
| `NullHumanInputResolver` | 测试 / 非交互 | 返回默认值，不进行提示 |

```php
use ChenZhanjie\Agentic\Resolver\CliHumanInputResolver;

$runner->setHumanInputResolver(new CliHumanInputResolver($io));
```

### SkillTool

加载技能的完整操作指南和资源文件。

- **名称：** `skill`
- **支持并行调用**

```php
// 加载完整技能指令
$arguments = ['name' => 'search-guide'];

// 加载技能资源文件
$arguments = ['name' => 'search-guide', 'resource' => 'references/query_templates.md'];
```

## ToolRegistry API

```php
$registry->register($tool);                        // 注册工具
$registry->register($tool, 'api', 50000);          // 指定分组和最大结果大小
$registry->resolve('search');                       // 获取工具实例
$registry->execute('search', ['query' => 'php']);   // 执行工具
$registry->only(['search', 'ask']);                 // 不可变过滤，返回新实例
$registry->has('search');                           // 工具是否存在
$registry->hasTools();                              // 是否有工具
$registry->getAvailableSchemas();                   // OpenAI function calling 格式
$registry->getAvailableNames();                     // 已启用工具名称列表
$registry->getAvailableDescriptions();              // name => description 映射
$registry->count();                                 // 工具总数
```

## 自定义工具示例

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
        return '执行只读 SQL 查询并返回结果。仅允许 SELECT 语句。';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL 查询语句（仅 SELECT）',
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function execute(array $arguments): string
    {
        $sql = $arguments['sql'] ?? '';

        // 安全检查：仅允许 SELECT
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            return '错误：仅允许 SELECT 查询';
        }

        $stmt = $this->pdo->query($sql . ' LIMIT 100');
        return json_encode($stmt->fetchAll(\PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    }

    public function isEnabled(): bool { return true; }
    public function isParallelAllowed(): bool { return false; }
}
```
