# Hyperf Agentic SDK

简体中文 | [English](README.md)

一个配置驱动的 AI Agent SDK，为 Hyperf 应用而生。Agent 是配置，不是类。

## 安装

```bash
composer require chenzhanjie/hyperf-agentic
```

发布配置文件：

```bash
php bin/hyperf.php vendor:publish chenzhanjie/hyperf-agentic
```

会在 `config/autoload/agentic/` 下创建：

```
config/autoload/agentic/
├── agentic.php      # 全局默认配置（迭代次数、预算、Grace）
├── agents.php       # Agent 定义
├── providers.php    # LLM 服务商配置（OpenAI 等）
├── tools.php        # 工具类注册
├── session.php      # 会话存储配置
├── cli.php          # CLI 命令注册
└── souls/           # 人设 Markdown 文件
    └── chat.md
```

## 快速开始

### 1. 配置 LLM 服务商

编辑 `config/autoload/agentic/providers.php`：

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

### 2. 定义一个 Agent

编辑 `config/autoload/agentic/agents.php`：

```php
return [
    'default' => [
        'persona'        => 'default.md',
        'tools'          => ['*'],
        'max_iterations' => 10,
    ],
];
```

### 3. 使用 Agent

```php
use ChenZhanjie\Agentic\Agentic;

// 通过 Hyperf DI 注入
$agentic = $this->container->get(Agentic::class);

// 执行 Agent
$result = $agentic->run('default', [
    ['role' => 'user', 'content' => '你好！'],
]);

echo $result->content;
```

## 核心 API

### Agent 执行

```php
// 执行指定 Agent（完整循环 + 工具调用）
$result = $agentic->run('default', $messages, $options);

// SSE 流式执行
$result = $agentic->runStream('default', $messages, function (string $type, array $payload) {
    echo "event: {$type}\ndata: " . json_encode($payload) . "\n\n";
});

// 纯 LLM 对话（无 Agent 循环，无工具）
$response = $agentic->chat($messages);

// 纯 LLM 流式对话
$agentic->chatStream($messages, function (string $chunk) {
    echo $chunk;
});
```

### 恢复挂起的 Agent

```php
// 恢复之前挂起的会话（例如等待人工输入后）
$result = $agentic->resume($sessionId);
```

### Agent 信息查询

```php
$agentic->agents();           // 获取所有已定义的 Agent 名称
$agentic->tools();            // 获取所有已注册的工具名称
$agentic->has('default');     // 检查 Agent 是否存在
$agentic->persona('default'); // 获取 Agent 的人设
```

## AgentResult

每次 `run()` 或 `runStream()` 调用都返回 `AgentResult`：

```php
$result->content;            // Agent 的文本回复
$result->iterations;         // 使用的迭代次数
$result->elapsedMs;          // 执行时间（毫秒）
$result->promptTokens;       // 总 prompt Token 消耗
$result->completionTokens;   // 总 completion Token 消耗
$result->toolCalls;          // 总工具调用次数
$result->toArray();          // 序列化为数组
```

## 自定义工具

### 注解方式（推荐）

```php
use ChenZhanjie\Agentic\Attribute\AsTool;
use ChenZhanjie\Agentic\Contract\ToolInterface;

#[AsTool]
class SearchTool implements ToolInterface
{
    public function name(): string { return 'search'; }
    public function description(): string { return '搜索知识库'; }
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
    public function isEnabled(): bool { return true; }
    public function isParallelAllowed(): bool { return true; }

    public function execute(array $arguments): array|string
    {
        return "搜索结果：{$arguments['query']}";
    }
}
```

注解扫描通过 Hyperf DI 自动完成。

### 配置方式

在 `config/autoload/agentic/tools.php` 中注册：

```php
return [
    'classes' => [
        \App\AgentTools\SearchTool::class,
    ],
];
```

## 人设（SOUL.md）

人设定义 Agent 的性格和行为方式。将 Markdown 文件放在 `config/autoload/agentic/souls/` 下。

**Markdown 文件就是系统提示词** — 随意书写，无格式限制。

```markdown
# 客服助手

你是一名专业的客户支持专员，负责我们 SaaS 平台的用户支持。
目标是快速准确地解决用户问题。

## 工作准则

- 回答前先问候用户
- 需求不明确时主动追问
- 不确定时转交人工处理

## 语气

专业而温暖。如果用户提供了姓名，请使用称呼。
```

**最佳实践：**
- 以 `# Agent 名称` 作为 H1 — 会被提取为 Agent 的显示名称
- 用 `##` 标题组织段落 — 方便你自己和 LLM 导航提示词
- 保持简洁：过长的提示词增加 Token 成本，降低指令遵从准确度
- 具体明确："100 字以内回复" > "简洁回答"

在 Agent 配置中引用：

```php
'default' => [
    'persona' => 'support.md', // 对应 souls/support.md
],
```

## 技能（SKILL.md）

技能提供操作指南的渐进式披露。创建技能目录：

```
config/autoload/agentic/skills/
└── query-templates/
    ├── SKILL.md          # 指令 + YAML frontmatter
    ├── references/       # 参考文档
    │   └── templates.md
    └── scripts/          # 可执行脚本
```

SKILL.md 格式：

```markdown
---
name: query-templates
description: SQL 查询模板库
tools:
  - search
---

# 查询模板

生成 SQL 查询的指导规范...
```

## 中间件

内置中间件钩子：

| 钩子 | 说明 |
|------|------|
| `beforeLoop` | Agent 循环开始前 |
| `afterLoop` | Agent 循环结束后 |
| `beforeLlmCall` | 每次 LLM API 调用前 |
| `afterLlmCall` | 每次 LLM API 调用后 |
| `beforeToolCall` | 工具分派前（可拦截） |
| `afterToolCall` | 工具执行后 |

自定义中间件：

```php
use ChenZhanjie\Agentic\Contract\MiddlewareInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    public function beforeToolCall(string $name, array $arguments): ?string
    {
        logger()->info("工具调用: {$name}");
        return null; // 返回字符串即可拦截
    }
    // ... 实现其他方法
}
```

## 护栏（Guardrails）

输入/输出安全检查：

```php
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\GuardrailResult;

class SafetyGuard implements GuardrailInterface
{
    public function checkInput(array $messages): GuardrailResult
    {
        foreach ($messages as $msg) {
            if (str_contains($msg['content'] ?? '', '危险内容')) {
                return GuardrailResult::tripwire('检测到不安全内容');
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

## 人工输入（Ask Tool）

### CLI 模式

```php
use ChenZhanjie\Agentic\Resolver\CliHumanInputResolver;

$resolver = new CliHumanInputResolver($symfonyStyle);
$agentic->setHumanInputResolver($resolver);
```

### HTTP 模式（非阻塞）

```php
use ChenZhanjie\Agentic\Resolver\HttpHumanInputResolver;

$resolver = new HttpHumanInputResolver($sessionStore, 'session-123');
$agentic->setHumanInputResolver($resolver);

// Agent 需要输入时抛出 AgentSuspendedException
// 稍后通过 session ID 恢复
$result = $agentic->resume('session-123');
```

## 预算控制

### 迭代预算

```php
// Agent 配置中
'max_iterations' => 10,  // 最大 LLM 调用轮数
```

包含 **Grace 轮** — 预算耗尽后额外一轮，让 LLM 干净地收尾。

### Token 预算

```php
// Agent 配置中
'max_cost_tokens' => 100000,  // 最大总 Token（prompt + completion）
```

使用量达 80% 时发出警告，达 100% 时停止。

## CLI 命令

交互式 Agent 对话：

```bash
# 使用默认 Agent 对话
php bin/hyperf.php agent:chat

# 指定 Agent
php bin/hyperf.php agent:chat support

# 无人值守模式
php bin/hyperf.php agent:chat --no-input

# 覆盖模型
php bin/hyperf.php agent:chat -m gpt-4o-mini
```

## 架构

5 层垂直架构，严格单向依赖：

```
Layer 5: 入口层 (Controller / Command / CLI)
    ↓
Layer 4: 门面层 (Agentic.php — 配置驱动的统一入口)
    ↓
Layer 3: Agent 核心 (AgentRunner + GuardrailRunner + MiddlewarePipeline)
    ↓
Layer 2: 子系统 (ToolRegistry / PromptBuilder / LlmClient / PersonaLoader / SkillRegistry)
    ↓
Layer 1: 基础层 (Contract/ — 接口，零上游依赖)
```

### 提示词构建器（7 层）

系统提示词由 7 层组装：

| 层级 | 类型 | 内容 |
|------|------|------|
| 1 | 缓存 | 人设（SOUL.md） |
| 2 | 缓存 | SDK 基础提示词 |
| 3 | 缓存 | Agent 自定义 system prompt |
| 4 | 缓存 | 工具边界声明 |
| 5 | 缓存 | 场景 + 技能 + 记忆 |
| 6 | 瞬态 | 运行时上下文（时间戳、能力） |
| 7 | 瞬态 | 预算警告 / Grace 消息 |

缓存层每次会话构建一次，瞬态层每轮重建。

## 测试

```bash
composer install
vendor/bin/phpunit
```

341 个测试，596 个断言 — 全部通过。

## 许可证

MIT
