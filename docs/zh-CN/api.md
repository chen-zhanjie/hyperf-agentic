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

**事件：**

| 事件 | 说明 |
|------|------|
| `started` | Agent 循环开始 |
| `thinking` | 即将调用 LLM |
| `text_delta` | 文本内容分片（`data['content']`），同步和流式模式下均会触发 |
| `reasoning_delta` | 推理/思考内容分片（`data['content']`），同步和流式模式下均会触发 |
| `tool_call` | 工具调用已分发 |
| `tool_result` | 工具结果已返回 |
| `complete` | Agent 正常结束 |
| `error` | Agent 遇到错误 |
| `budget_exceeded` | Token 预算超限 |
| `guardrail_blocked` | 输出被护栏拦截 |
| `suspended` | Agent 挂起等待人工输入 |

**示例：**

```php
$result = $this->agentic->runStream('general', $messages, function (string $event, array $data) {
    if ($event === 'text_delta') {
        echo $data['content'];
        ob_flush();
    }
});
```

### SSE 输出

使用 `SseWriter` 将流式事件格式化为 OpenAI 兼容的 SSE：

```php
use ChenZhanjie\Agentic\Stream\SseWriter;

$sse = new SseWriter(fn(string $line) => $eventStream->write($line));
$result = $agentic->runStream('general', $messages, $sse->asOnEvent());
```

纯 LLM 聊天流式：

```php
$sse = new SseWriter(fn(string $line) => echo $line, model: 'gpt-4o');
$result = $agentic->chatStream($messages, $sse->asOnChunk());
$sse->finish($result['usage'] ?? []);
```

**SSE 输出格式：**

```
data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","content":""}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"content":"Hello"}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{...}}

data: [DONE]
```

**结束原因：**

| 场景 | `finish_reason` |
|------|----------------|
| 正常完成 | `"stop"` |
| 预算超限 | `"length"` |
| 护栏拦截 | `"content_filter"` |
| 显式工具调用 | `"tool_calls"` |

### runWithConfig()

使用动态配置执行 Agent，跳过 Agent 名称查找。适用于数据库驱动的多 Agent 场景。

```php
public function runWithConfig(Agent|array $agentConfig, array $messages, array $options = []): AgentResult
```

**参数：**

| 参数 | 类型 | 说明 |
|------|------|------|
| `$agentConfig` | Agent\|array | Agent DTO 或配置数组（见下方结构） |
| `$messages` | array | 消息数组 |
| `$options` | array | `conversation_id`、`runtime_context` 等 |

**`$agentConfig` 结构：**

```php
[
    'persona' => new Persona(name: 'Bot', content: 'You are a bot.'),  // Persona 对象
    'tools' => ['search', 'ask'],        // 工具白名单
    'skills' => ['guide'],               // 技能白名单
    'guardrails' => ['content_filter'],  // 护栏白名单
    'guardrail_modes' => ['content_filter' => 'async'], // 护栏模式覆盖
    'tool_permissions' => [              // 工具权限规则
        'allow' => ['search_*', 'ask'],
        'deny' => ['exec_*'],
    ],
    'permission_mode' => 'default',     // 权限模式：default|auto|strict|readonly
    'auto_approve' => true,             // 自动审批工具（true 或模式数组）
    'max_iterations' => 15,              // 最大迭代
    'system_prompt' => 'Extra rules',    // 附加系统提示
    'cancellation_timeout_ms' => 30000,  // 30 秒后自动取消
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
    Agent|array $agentConfig,
    array $messages,
    ?callable $onEvent = null,
    array $options = [],
): AgentResult
```

## 纯 LLM 对话

### chat()

纯 LLM 对话，不经过 Agent 循环（不调用工具）。

```php
public function chat(array $messages, array $options = []): LlmResponse
```

**返回：** `LlmResponse`（见下方 DTO 部分）

**示例：**

```php
$response = $this->agentic->chat([
    ['role' => 'user', 'content' => 'Translate to English: 你好世界'],
]);

echo $response->content;               // "Hello World"
echo $response->usage['prompt_tokens']; // Token 用量
```

### chatStream()

纯 LLM 流式对话，逐块转发到回调。

```php
public function chatStream(array $messages, callable $onChunk, array $options = []): LlmResponse
```

**返回：** `LlmResponse` — 流式完成后拼装的完整响应。

**示例：**

```php
$result = $this->agentic->chatStream($messages, function (array $chunk) {
    if (isset($chunk['content'])) {
        echo $chunk['content'];
        ob_flush();
    }
});

echo $result->content; // 完整拼接后的回复
```

使用 `SseWriter` 配合 `chatStream` 时，`finish()` 接受 usage 数组：

```php
$sse = new SseWriter(fn(string $line) => echo $line, model: 'gpt-4o');
$result = $agentic->chatStream($messages, $sse->asOnChunk());
$sse->finish($result->usage);
```

## OpenAI 兼容 SSE 输出

使用 `SseWriter` 将流式事件格式化为 OpenAI 兼容的 SSE：

```php
use ChenZhanjie\Agentic\Stream\SseWriter;

// Agent 流式 — model 自动从 started 事件获取
$sse = new SseWriter(fn(string $line) => $eventStream->write($line));
$result = $agentic->runStream('general', $messages, $sse->asOnEvent());
```

纯 LLM 对话流式：

```php
$sse = new SseWriter(fn(string $line) => echo $line, model: 'gpt-4o');
$result = $agentic->chatStream($messages, $sse->asOnChunk());
$sse->finish($result->usage);
```

**SSE 输出格式：**

标准 chunk（role、content、tool_call、finish）使用 OpenAI `data:` 格式：

```
data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","content":""}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"content":"你好"}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[...]}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{...}}

data: [DONE]
```

`tool_result` 事件使用命名 SSE 事件类型，不嵌入 OpenAI chunk 信封：

```
event: tool_result
data: {"call_id":"call_abc","name":"search","result":"...","success":true}

```

消费端通过 `EventSource.addEventListener('tool_result', ...)` 接收。标准 OpenAI SDK 解析器会自动忽略命名事件，不影响兼容性。

**结束原因（finish_reason）：**

| 场景 | `finish_reason` |
|------|----------------|
| 正常完成 | `"stop"` |
| 预算耗尽 | `"length"` |
| 护栏拦截 | `"content_filter"` |
| 工具调用 | `"tool_calls"` |

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

## 权限审批

管理工具执行的全局或按会话审批。

### approveTool()

全局或为指定会话审批一个工具或模式。

```php
public function approveTool(string $toolOrPattern, ?string $sessionId = null): void
```

**示例：**

```php
// 全局审批
$this->agentic->approveTool('search_*');

// 为指定会话审批
$this->agentic->approveTool('delete_db', 'conv-123');
```

### approveAll()

全局或为指定会话审批所有工具。

```php
public function approveAll(?string $sessionId = null): void
```

### revokeTool()

撤销指定审批。

```php
public function revokeTool(string $toolOrPattern, ?string $sessionId = null): void
```

### revokeAll()

撤销全局或指定会话的所有审批。

```php
public function revokeAll(?string $sessionId = null): void
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

## LlmResponse

`chat()` 和 `chatStream()` 返回 `LlmResponse` DTO。

### 公开属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$content` | string | LLM 回复内容 |
| `$usage` | array | Token 用量：`['prompt_tokens' => int, 'completion_tokens' => int]` |
| `$model` | ?string | 使用的模型名称 |
| `$provider` | ?string | 使用的 Provider 名称 |
| `$reasoningContent` | ?string | 推理过程（如模型支持） |
| `$toolCalls` | array | 工具调用（如有） |

### 序列化

```php
$response->toArray(); // 转为关联数组（向后兼容）
```

## LlmCallMeta

传递给 `LlmMiddlewareInterface::afterCall()` 用于可观测性。

```php
public function afterLlmCall(array $response, LlmCallMeta $meta): void
```

### 公开属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `$provider` | string | LLM Provider 名称 |
| `$model` | string | 模型名称 |
| `$promptTokens` | int | prompt token 消耗 |
| `$completionTokens` | int | completion token 消耗 |
| `$totalTokens` | int | 总 token 消耗 |

## 中间件

### 容错机制

通知方法（`afterLoop`、`afterCall`、`afterToolCall`、`onAgentStart`、`onChunk`）内部捕获异常并记录警告，不会中断 Agent 循环。链式方法（`beforeLoop`、`beforeCall`）失败时仍然抛出异常，因为它们负责数据变换，必须保证正确性。

### LlmMiddlewareInterface

```php
interface LlmMiddlewareInterface
{
    public function beforeCall(LlmCallRequest $request): LlmCallRequest;
    public function afterCall(LlmCallRequest $request, LlmResponse $response): ?LlmResponse;
    public function onRetry(string $provider, int $attempt, \Throwable $error): void;
    public function onFailover(string $fromProvider, string $toProvider): void;
    public function onChunk(array $chunk): void;
}
```

- `afterCall`：返回 `null` 透传原始响应，或返回新的 `LlmResponse` 替换
- `onChunk`：`chatStream()` 期间每个分块调用。用于实时 token 计数、日志记录

### AgentMiddlewareInterface

```php
interface AgentMiddlewareInterface
{
    public function onAgentStart(array $agentConfig, array $options): void;
    public function beforeLoop(array $messages, array $agentConfig): array;
    public function afterLoop(AgentResult $result): AgentResult;
    public function beforeToolCall(string $name, array $arguments, ToolCallContext $context): ?string;
    public function afterToolCall(string $name, array $arguments, string $result, ToolCallContext $context): void;
}
```

- `onAgentStart`：Agent 运行开始时调用一次，在任何处理之前
- `beforeToolCall` / `afterToolCall`：接收类型化的 `ToolCallContext` DTO 而非松散数组

### ToolCallContext

传递给 `beforeToolCall` 和 `afterToolCall` 的不可变 DTO：

```php
class ToolCallContext
{
    public readonly ?string $sessionId;
    public readonly string $agentName;
    public readonly ?string $toolCallId;
    public readonly int $iteration;

    public function with(array $overrides): self { ... }
}
```
