# 护栏（安全护栏）

护栏是运行在 Agent 循环中的安全检查机制，分为 **输入检查** 和 **输出检查**。支持 **同步** 和 **异步** 两种模式。

## 工作流程

### 同步护栏（默认）

```
用户消息 → [同步输入护栏] → Agent 循环 → [同步输出护栏] → 返回结果
                ↓                              ↓
           拦截 → 返回错误                拦截 → 返回错误
```

### 异步护栏（Swoole 环境）

```
用户消息 → [同步输入护栏] ──────────→ Agent 循环 ──→ [同步输出护栏] ──→ 返回结果
          [异步输入护栏 ──→ 运行中]                    [异步输出护栏 ──→ 运行中]
                ↓ (循环期间完成)                              ↓ (输出后完成)
            拦截 → 撤回事件                            拦截 → COMPLETE → GUARDRAIL_RECALLED
```

- **同步护栏**：立即拦截，内容不会到达客户端
- **异步护栏**：在后台协程中运行；内容先输出，如果拦截则触发撤回
- **优雅降级**：无 Swoole 环境时，异步护栏自动降级为同步执行

## GuardrailInterface

所有护栏必须实现 `ChenZhanjie\Agentic\Contract\GuardrailInterface`：

```php
interface GuardrailInterface
{
    public function name(): string;
    public function checkInput(array $messages): GuardrailResult;
    public function checkOutput(string $content): GuardrailResult;
}
```

### GuardrailResult

```php
// 通过
GuardrailResult::ok();

// 拦截
GuardrailResult::blocked('包含敏感信息');
```

## 护栏模式

每个护栏可以配置为 **同步** 或 **异步**：

```php
use ChenZhanjie\Agentic\GuardrailMode;

// 注册时指定模式（默认为 SYNC）
$guardrailRunner->register(new ToxicityDetector(), GuardrailMode::ASYNC);
```

### 配置格式

```php
// agents.php
return [
    'chat' => [
        'persona' => 'chat.md',
        'guardrails' => ['content_filter', 'toxicity_detector'],
        'guardrail_modes' => [                        // 可选
            'toxicity_detector' => 'async',            // 指定为异步
            // 未列出的护栏默认为 'sync'
        ],
        'async_guardrail_timeout' => 5000,            // 可选，毫秒，默认 5000
    ],
];
```

### 何时使用异步？

- **基于大模型的护栏**（毒性检测、PII 识别）需要数秒完成
- **外部 API 护栏**（内容审核服务）存在网络延迟
- 任何阻塞响应会明显影响用户体验的护栏

### 异步撤回的事件流

异步输出护栏拦截内容时：

```
THINKING → COMPLETE → GUARDRAIL_RECALLED
↑ 客户端看到内容     ↑ 客户端撤回内容
```

异步输入护栏在循环中拦截时：

```
THINKING → ... → GUARDRAIL_RECALLED
                  ↑ 循环中断，部分结果丢弃
```

## 注册护栏

### 方式一：运行时注册

```php
$guardrailRunner->register(new ContentFilterGuardrail());
```

### 方式二：从配置加载

```php
// 简单格式（全部 SYNC）
$guardrailRunner->loadFromConfig([
    \App\Guardrail\ContentFilterGuardrail::class,
]);

// 扩展格式（带模式）
$guardrailRunner->loadFromConfig([
    ['class' => \App\Guardrail\ContentFilterGuardrail::class, 'mode' => 'sync'],
    ['class' => \App\Guardrail\ToxicityDetector::class, 'mode' => 'async'],
]);
```

> 注意：`loadFromConfig()` 会替换之前所有已注册的护栏。

### 方式三：withModes() 覆盖

不可变地应用模式覆盖：

```php
$runner = $guardrailRunner
    ->only(['toxicity', 'pii'])
    ->withModes(['toxicity' => GuardrailMode::ASYNC]);
```

## Per-Agent 护栏过滤

通过 `agents.php` 或 `runWithConfig()` 指定每个 Agent 启用的护栏白名单：

```php
// agents.php
return [
    'chat' => [
        'guardrails' => ['content_filter'],  // 仅启用 content_filter
        'guardrail_modes' => ['content_filter' => 'async'],
    ],
    'admin' => [
        'guardrails' => [],                   // 空数组 = 全部护栏生效
    ],
];

// runWithConfig
$agentic->runWithConfig(
    [
        'guardrails' => ['content_filter', 'pii_filter'],
        'guardrail_modes' => ['pii_filter' => 'async'],
        'async_guardrail_timeout' => 3000,
    ],
    $messages,
);
```

底层使用 `GuardrailRunner::only(array $names)` — 不可变过滤，返回新实例，不影响其他请求。

## 消息撤回（RecallTool）

SDK 内置了 `recall` 工具用于消息撤回。这是所有消息拦截场景的统一机制：

- 异步护栏撤回（自动触发）
- LLM 自我纠正（LLM 主动调用 `recall` 工具）
- 外部策略执行

### 工作原理

```php
// 由 ToolRegistryFactory 自动注册
// LLM 可以像调用其他工具一样调用它：
// { "name": "recall", "arguments": { "message_id": "msg-123", "reason": "检测到 PII" } }
```

当注入 `MessageStoreInterface` 时，撤回操作会自动持久化：

```php
$recallTool = new RecallTool($messageStore);
$result = $recallTool->execute([
    'conversation_id' => 'conv-1',
    'message_id' => 'msg-123',
    'reason' => '有害内容',
]);
// 消息在存储中被标记为已撤回
```

### MessageStore 撤回支持

```php
interface MessageStoreInterface
{
    // ... 现有方法 ...
    public function recall(string $conversationId, string $messageId, string $reason): void;
}
```

## 自定义护栏示例

### 内容过滤护栏

```php
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\GuardrailResult;

class ContentFilterGuardrail implements GuardrailInterface
{
    private array $blockedPatterns = [
        '/攻击/i', '/暴力/i', '/毒品/i',
    ];

    public function name(): string { return 'content_filter'; }

    public function checkInput(array $messages): GuardrailResult
    {
        foreach ($messages as $msg) {
            foreach ($this->blockedPatterns as $pattern) {
                if (preg_match($pattern, $msg['content'] ?? '')) {
                    return GuardrailResult::blocked('输入内容包含违规信息');
                }
            }
        }
        return GuardrailResult::ok();
    }

    public function checkOutput(string $content): GuardrailResult
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return GuardrailResult::blocked('输出内容包含违规信息');
            }
        }
        return GuardrailResult::ok();
    }
}
```

### 基于大模型的异步护栏

```php
class ToxicityDetector implements GuardrailInterface
{
    public function name(): string { return 'toxicity_detector'; }

    public function checkInput(array $messages): GuardrailResult
    {
        $lastMessage = end($messages)['content'] ?? '';
        return $this->analyzeToxicity($lastMessage);
    }

    public function checkOutput(string $content): GuardrailResult
    {
        return $this->analyzeToxicity($content);
    }

    private function analyzeToxicity(string $text): GuardrailResult
    {
        // 调用审核 API 或辅助大模型
        // 这可能需要几秒 — 非常适合 ASYNC 模式
        $score = $this->moderationApi->analyze($text);
        return $score > 0.8
            ? GuardrailResult::blocked('检测到有害内容（分数: ' . $score . '）')
            : GuardrailResult::ok();
    }
}
```

## GuardrailRunner API

```php
$runner->register($guardrail);                          // 追加一个护栏（默认 SYNC）
$runner->register($guardrail, GuardrailMode::ASYNC);    // 追加为异步
$runner->loadFromConfig([...]);                         // 从类名加载（替换）
$runner->checkInput($messages);                         // 同步检查，返回第一个拦截结果或 null
$runner->checkOutput($content);                         // 同步检查，返回第一个拦截结果或 null
$runner->checkInputAsync($messages);                    // 返回 AsyncGuardrailContext
$runner->checkOutputAsync($content);                    // 返回 AsyncGuardrailContext
$runner->only(['content_filter']);                      // 不可变过滤，返回新实例
$runner->withModes(['toxicity' => GuardrailMode::ASYNC]); // 不可变模式覆盖
```

## AgentResult 状态

### 同步护栏拦截时：

```php
$result->isGuardrailBlocked();  // true
$result->content;               // ''（空）
$result->stopReason;            // 'guardrail'
```

### 异步护栏撤回时：

```php
$result->isGuardrailBlocked();  // true
$result->isRecalled();          // true
$result->content;               // 原始输出内容（撤回前）
$result->recallReason;          // '检测到有害内容'
$result->stopReason;            // 'guardrail'
```

## 新增事件类型

| 事件 | 触发时机 |
|------|----------|
| `guardrail_blocked` | 同步护栏在内容到达客户端前拦截 |
| `guardrail_recalled` | 异步护栏在内容输出后拦截 |
| `message_recalled` | RecallTool 执行时（LLM 自主撤回或外部触发） |
