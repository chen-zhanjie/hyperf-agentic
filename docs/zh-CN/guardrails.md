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

## 护栏优先级

护栏按**优先级排序**执行（高优先级先执行）。这确保关键安全检查先于耗时的 LLM 分析运行。

```php
// 注册时指定优先级（数值越高越先执行）
$guardrailRunner->register(new CriticalSafetyCheck(), GuardrailMode::SYNC, priority: 100);
$guardrailRunner->register(new ToxicityDetector(), GuardrailMode::ASYNC, priority: 50);
$guardrailRunner->register(new LoggingGuardrail(), GuardrailMode::SYNC, priority: 0);
```

### 配置格式（含优先级）

```php
$guardrailRunner->loadFromConfig([
    ['class' => CriticalSafetyCheck::class, 'mode' => 'sync', 'priority' => 100],
    ['class' => ToxicityDetector::class, 'mode' => 'async', 'priority' => 50],
    ['class' => LoggingGuardrail::class],  // 默认：sync, priority 0
]);
```

## 护栏审计日志

每个护栏决策都可以被记录，用于合规审计和调试。

### GuardrailAuditLoggerInterface

```php
interface GuardrailAuditLoggerInterface
{
    public function log(GuardrailAuditEntry $entry): void;
}
```

### GuardrailAuditEntry

```php
$entry = new GuardrailAuditEntry(
    guardrailName: 'pii_filter',
    phase: 'input',          // input | output
    decision: 'blocked',     // pass | blocked
    reason: '检测到 SSN',
    durationMs: 12.5,
);
$entry->toArray();
// ['guardrail_name' => 'pii_filter', 'phase' => 'input', 'decision' => 'blocked', ...]
```

### 内置实现

默认的 `GuardrailAuditLogger` 支持双通道日志（PSR-3 + callable）：

```php
$logger = new GuardrailAuditLogger(
    logger: $psr3Logger,                              // 可选 PSR-3 日志器
    handler: fn(GuardrailAuditEntry $e) => /* ... */, // 可选回调
);
```

已通过 `ConfigProvider` 默认注册。自定义时可覆盖绑定：

```php
Contract\GuardrailAuditLoggerInterface::class => MyCustomAuditLogger::class,
```

## 工具护栏

工具级护栏在每次工具执行的**前后**运行，提供工具边界的输入验证和输出过滤。

### ToolGuardrailInterface

```php
interface ToolGuardrailInterface
{
    public function name(): string;
    public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult;
    public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult;
}
```

### ToolGuardrailResult

```php
ToolGuardrailResult::ok();                                         // 通过
ToolGuardrailResult::blocked('无效参数');                           // 拒绝调用
ToolGuardrailResult::sanitize(['query' => '***REDACTED***']);      // 通过并修正参数
ToolGuardrailResult::transformOutput('脱敏输出');                   // 通过并转换输出
```

### 执行流程

```
工具护栏（输入检查）
  → 拦截：返回错误给 LLM
  → 修正：修改参数，继续下一个护栏
  ↓
权限策略（deny/ask/allow）
  → 拒绝：返回错误给 LLM
  → 确认：提示用户确认
  ↓
中间件 → 工具执行
  ↓
工具护栏（输出检查）
  → 拦截：返回错误给 LLM
  → 转换：修改输出，继续下一个护栏
  ↓
返回 Agent 循环
```

多个工具护栏按顺序运行。sanitize 和 transform 操作将修改后的值传递给后续护栏。

### SchemaValidationToolGuardrail（内置）

根据工具声明的 `parameters()` JSON Schema 校验工具参数：

```php
use ChenZhanjie\Agentic\Guardrail\SchemaValidationToolGuardrail;

$guardrail = new SchemaValidationToolGuardrail($toolRegistry);
$toolGuardrailRunner->register($guardrail);
```

检查项：
- 必填字段是否存在
- 基本类型匹配（string、integer、number、boolean、array、object）

### 自定义工具护栏示例

```php
use ChenZhanjie\Agentic\Contract\ToolGuardrailInterface;
use ChenZhanjie\Agentic\ToolGuardrailResult;

class PiiFilterToolGuardrail implements ToolGuardrailInterface
{
    public function name(): string { return 'pii_filter'; }

    public function checkToolInput(string $toolName, array $arguments): ToolGuardrailResult
    {
        $sanitized = $this->redactPii($arguments);
        if ($sanitized !== $arguments) {
            return ToolGuardrailResult::sanitize($sanitized, '参数中的 PII 已脱敏');
        }
        return ToolGuardrailResult::ok();
    }

    public function checkToolOutput(string $toolName, array $arguments, string $result): ToolGuardrailResult
    {
        if ($this->containsSecrets($result)) {
            return ToolGuardrailResult::blocked('工具输出包含敏感信息');
        }
        return ToolGuardrailResult::ok();
    }
}
```

## 工具权限

工具可按风险等级分类，配合配置驱动的权限策略。

### ToolRiskLevel

```php
enum ToolRiskLevel: string
{
    case LOW = 'low';           // 只读，无副作用
    case MEDIUM = 'medium';     // 有副作用但可逆
    case HIGH = 'high';         // 不可逆，外部影响
    case CRITICAL = 'critical'; // 系统级变更，必须确认
}
```

### RiskyToolInterface

高风险工具实现 `RiskyToolInterface`：

```php
interface RiskyToolInterface extends ToolInterface
{
    public function riskLevel(): ToolRiskLevel;
    public function riskDescription(): string;
}
```

### ConfigToolPermissionPolicy

配置驱动的权限策略，支持通配符模式匹配：

```php
// agents.php 或 runWithConfig
'tool_permissions' => [
    'allow' => ['search_*', 'skill', 'ask'],
    'ask'   => ['delete_*', 'recall'],
    'deny'  => ['exec_*'],
    'default_ask_threshold' => 'high',  // 未列出的 HIGH+ 工具需要审批
],
```

优先级：`deny > ask > allow > 默认阈值`。模式支持 `*` 通配符。

### 权限决策流程

```
1. 工具在拒绝列表中？    → DENY（返回错误）
2. 工具在确认列表中？    → ASK（提示用户确认）
3. 工具在允许列表中？    → ALLOW
4. 风险等级 >= 阈值？    → ASK
5. 默认                  → ALLOW
```

### 事件

| 事件 | 触发时机 |
|------|----------|
| `tool_blocked` | 工具护栏拦截工具调用 |
| `tool_denied` | 权限策略拒绝工具调用 |

## 取消令牌

Agent 支持通过 `CancellationToken` 进行协作取消：

```php
// agents.php 或 runWithConfig
'cancellation_timeout_ms' => 30000,  // 30 秒后自动取消
```

取消时，Agent 循环在下一次迭代边界退出。

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
$runner->register($guardrail, mode: ..., priority: 100);// 追加并指定优先级（越高越先执行）
$runner->loadFromConfig([...]);                         // 从类名加载（替换）
$runner->checkInput($messages);                         // 同步检查，返回第一个拦截结果或 null
$runner->checkOutput($content);                         // 同步检查，返回第一个拦截结果或 null
$runner->checkInputAsync($messages);                    // 返回 AsyncGuardrailContext
$runner->checkOutputAsync($content);                    // 返回 AsyncGuardrailContext
$runner->only(['content_filter']);                      // 不可变过滤，返回新实例
$runner->withModes(['toxicity' => GuardrailMode::ASYNC]); // 不可变模式覆盖
```

## ToolGuardrailRunner API

```php
$runner->register($toolGuardrail);                      // 注册工具护栏
$runner->checkToolInput($toolName, $arguments);         // 检查/修正参数，返回 blocked 或 null
$runner->checkToolOutput($toolName, $arguments, $output); // 检查/转换输出，返回 blocked 或 null
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

## 事件类型

| 事件 | 触发时机 |
|------|----------|
| `guardrail_blocked` | 同步护栏在内容到达客户端前拦截 |
| `guardrail_recalled` | 异步护栏在内容输出后拦截 |
| `guardrail_decision` | 每次护栏决策（通过或拦截） |
| `message_recalled` | RecallTool 执行时（LLM 自主撤回或外部触发） |
| `tool_blocked` | 工具护栏拦截工具调用 |
| `tool_denied` | 权限策略拒绝工具调用 |
