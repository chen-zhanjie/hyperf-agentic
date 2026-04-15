# Database-Driven Agents（数据库驱动 Agent）

当 Agent 配置存储在数据库中（如管理后台创建的 Agent），使用 `runWithConfig()` 跳过配置文件查找，直接传入动态配置。

## 核心方法

```php
// 非流式
$agentic->runWithConfig(array $agentConfig, array $messages, array $options = []): AgentResult

// 流式
$agentic->runStreamWithConfig(array $agentConfig, array $messages, ?callable $onEvent = null, array $options = []): AgentResult
```

## Agent 配置结构

```php
$agentConfig = [
    // 人设（必需）
    'persona' => new Persona(name: 'Expert', content: 'You are an expert.'),

    // 工具白名单（可选，空数组 = 全部可用）
    'tools' => ['search', 'db_query'],

    // 技能白名单（可选，空数组 = 全部可用）
    'skills' => ['search-guide'],

    // 护栏白名单（可选，空数组 = 全局生效）
    'guardrails' => ['content_filter'],

    // 最大迭代次数（可选，覆盖全局默认值）
    'max_iterations' => 15,

    // 附加系统提示（可选）
    'system_prompt' => 'Always respond in Chinese.',
];
```

配置会与全局 `defaults`（来自 `agentic.php`）合并，`$agentConfig` 覆盖 `defaults`。

## 典型使用场景

### 数据库模型

假设有一个 Agent Eloquent 模型：

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

### 控制器中使用

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
        // 1. 从数据库读取 Agent 配置
        $agent = Agent::find($agentId);

        // 2. 构建动态配置
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

        // 3. 执行
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

### 结合对话持久化

```php
// 第一轮
$result1 = $agentic->runWithConfig(
    $agentConfig,
    [['role' => 'user', 'content' => '帮我分析这段代码']],
    ['conversation_id' => 'conv-abc123'],
);

// 第二轮 — 历史自动加载
$result2 = $agentic->runWithConfig(
    $agentConfig,
    [['role' => 'user', 'content' => '还有其他问题吗？']],
    ['conversation_id' => 'conv-abc123'],
);
```

### 流式响应（SSE）

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

## 与配置文件 Agent 的区别

| 特性 | `run()` | `runWithConfig()` |
|------|---------|-------------------|
| 配置来源 | `agents.php` 配置文件 | 动态数组参数 |
| 适用场景 | 预定义的固定 Agent | 数据库驱动的动态 Agent |
| 人设支持 | 文件路径或字符串 | `Persona` 对象 |
| 对话持久化 | 不支持 | 支持 `conversation_id` |
| 默认值合并 | `defaults` + agent def | `defaults` + 传入配置 |
| 护栏过滤 | 支持 | 支持 |
| 技能过滤 | 支持 | 支持 |

## 动态注册技能

从数据库加载技能并注册到 SkillRegistry：

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

// 在应用初始化时注册
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
