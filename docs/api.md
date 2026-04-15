# API Reference

`ChenZhanjie\Agentic\Agentic` 是 SDK 的统一入口（Layer 4 Facade）。通过 Hyperf DI 注入使用。

```php
use ChenZhanjie\Agentic\Agentic;
use Hyperf\Di\Annotation\Inject;

class MyService
{
    #[Inject]
    private Agentic $agentic;
}
```

## Agent 执行

### run()

执行指定名称的 Agent（非流式）。

```php
public function run(string $agentName, array $messages, array $options = []): AgentResult
```

**参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `$agentName` | string | Agent 名称（对应 `agents.php` 中的键名） |
| `$messages` | array | 消息数组，格式：`[['role' => 'user', 'content' => '...']]` |
| `$options` | array | 运行时选项：`provider`、`model_override`、`runtime_context` 等 |

**返回：** `AgentResult`

**异常：** Agent 未定义时抛出 `InvalidArgumentException`

**示例：**

```php
$result = $this->agentic->run('general', [
    ['role' => 'user', 'content' => 'What is PHP?'],
]);

echo $result->content;          // Agent 的回复内容
echo $result->iterations;       // 迭代次数
echo $result->toolCalls;        // 工具调用次数
echo $result->elapsedMs;        // 执行耗时（毫秒）
echo $result->promptTokens;     // 消耗的 prompt token 数
echo $result->completionTokens; // 消耗的 completion token 数
```

### runStream()

执行指定名称的 Agent（流式），通过回调发送 SSE 事件。

```php
public function runStream(
    string $agentName,
    array $messages,
    ?callable $onEvent = null,
    array $options = [],
): AgentResult
```

**参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `$agentName` | string | Agent 名称 |
| `$messages` | array | 消息数组 |
| `$onEvent` | callable\|null | SSE 事件回调 `fn(string $event, array $data) => void` |
| `$options` | array | 运行时选项 |

**示例：**

```php
$result = $this->agentic->runStream('general', $messages, function (string $event, array $data) {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
});
```

### runWithConfig()

使用动态配置执行 Agent，跳过 Agent 名称查找。适用于数据库驱动的多 Agent 场景。

```php
public function runWithConfig(array $agentConfig, array $messages, array $options = []): AgentResult
```

**参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `$agentConfig` | array | Agent 配置（见下方结构） |
| `$messages` | array | 消息数组 |
| `$options` | array | `conversation_id`、`runtime_context` 等 |

**`$agentConfig` 结构：**

```php
[
    'persona' => new Persona(name: 'Bot', content: 'You are a bot.'),  // Persona 对象
    'tools' => ['search', 'ask'],        // 工具白名单
    'skills' => ['guide'],               // 技能白名单
    'guardrails' => ['content_filter'],  // 护栏白名单
    'max_iterations' => 15,              // 最大迭代
    'system_prompt' => 'Extra rules',    // 附加系统提示
]
```

**示例：**

```php
use ChenZhanjie\Agentic\Persona\Persona;

$result = $this->agentic->runWithConfig(
    [
        'persona' => new Persona(name: 'Expert', content: 'You are an expert.'),
        'tools' => ['search'],
        'max_iterations' => 10,
    ],
    [['role' => 'user', 'content' => 'Hello']],
);
```

> 详见 [数据库驱动 Agent](database-agents.md)

### runStreamWithConfig()

`runWithConfig()` 的流式版本。

```php
public function runStreamWithConfig(
    array $agentConfig,
    array $messages,
    ?callable $onEvent = null,
    array $options = [],
): AgentResult
```

## 纯 LLM 对话

### chat()

纯 LLM 对话，不经过 Agent 循环（不调用工具）。

```php
public function chat(array $messages, array $options = []): string|array
```

**返回：** 字符串（纯文本响应）或数组（结构化响应）

### chatStream()

纯 LLM 流式对话，逐块转发到回调。

```php
public function chatStream(array $messages, callable $onChunk, array $options = []): void
```

**示例：**

```php
$this->agentic->chatStream($messages, function (string $chunk) {
    echo $chunk;
    ob_flush();
});
```

## 会话恢复

### resume()

恢复一个挂起的 Agent 会话。

```php
public function resume(string $sessionId): AgentResult
```

**异常：** `SessionStore` 未配置或会话不存在时抛出 `RuntimeException`

## 查询方法

### agents()

获取所有已定义的 Agent 名称。

```php
public function agents(): array
```

### tools()

获取所有已注册的工具名称。

```php
public function tools(): array
```

### availableTools()

获取所有已启用（enabled）的工具名称。

```php
public function availableTools(): array
```

### persona()

获取指定 Agent 的 Persona 对象。

```php
public function persona(string $agentName): ?Persona
```

### has()

检查 Agent 是否已定义。

```php
public function has(string $agentName): bool
```

## 配置方法

### setHumanInputResolver()

设置人工输入解析器（注入到 AskTool）。

```php
public function setHumanInputResolver(HumanInputResolverInterface $resolver): void
```

## AgentResult

所有 `run*` 方法返回 `AgentResult` 对象。

### 公开属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$content` | string | Agent 回复内容 |
| `$reasoningContent` | ?string | 推理过程（如模型支持） |
| `$iterations` | int | 迭代次数 |
| `$elapsedMs` | int | 执行耗时（毫秒） |
| `$promptTokens` | int | prompt token 消耗 |
| `$completionTokens` | int | completion token 消耗 |
| `$toolCalls` | int | 工具调用次数 |
| `$stopReason` | ?string | 停止原因 |

### 状态判断方法

| 方法 | 说明 |
|------|------|
| `isComplete()` | 正常完成 |
| `isSuspended()` | 因等待人工输入而挂起 |
| `isBudgetExhausted()` | 迭代预算耗尽 |
| `isGuardrailBlocked()` | 被安全护栏拦截 |

### 序列化

```php
$result->toArray(); // 转为关联数组
```
