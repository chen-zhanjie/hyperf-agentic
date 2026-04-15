# Guardrails（安全护栏）

护栏是运行在 Agent 循环中的安全检查机制，分为 **输入检查** 和 **输出检查**。

## 工作流程

```
用户消息 → [输入护栏检查] → Agent 循环 → [输出护栏检查] → 返回结果
                ↓                              ↓
           拦截 → 返回错误                拦截 → 返回错误
```

- 输入检查：在 Agent 循环开始前执行，检查用户消息是否合规
- 输出检查：在每次 LLM 生成回复后执行，检查输出内容是否安全
- 第一个拦截结果即停止，不再继续执行后续护栏

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

## 注册护栏

### 方式一：运行时注册

```php
$guardrailRunner->register(new ContentFilterGuardrail());
```

### 方式二：从配置加载

```php
$guardrailRunner->loadFromConfig([
    \App\Guardrail\ContentFilterGuardrail::class,
    \App\Guardrail\PiiGuardrail::class,
]);
```

> 注意：`loadFromConfig()` 会替换之前所有已注册的护栏。

## Per-Agent 护栏过滤

通过 `agents.php` 或 `runWithConfig()` 指定每个 Agent 启用的护栏白名单：

```php
// agents.php
return [
    'chat' => [
        'guardrails' => ['content_filter'],  // 仅启用 content_filter
    ],
    'admin' => [
        'guardrails' => [],                   // 空数组 = 全部护栏生效
    ],
];

// runWithConfig
$agentic->runWithConfig(
    ['guardrails' => ['content_filter', 'pii_filter']],
    $messages,
);
```

底层使用 `GuardrailRunner::only(array $names)` — 不可变过滤，返回新实例，不影响其他请求。

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

### PII 检测护栏

```php
class PiiGuardrail implements GuardrailInterface
{
    public function name(): string { return 'pii_filter'; }

    public function checkInput(array $messages): GuardrailResult
    {
        return GuardrailResult::ok();
    }

    public function checkOutput(string $content): GuardrailResult
    {
        // 检测身份证号
        if (preg_match('/\d{17}[\dXx]/', $content)) {
            return GuardrailResult::blocked('输出包含身份证号');
        }
        // 检测手机号
        if (preg_match('/1[3-9]\d{9}/', $content)) {
            return GuardrailResult::blocked('输出包含手机号');
        }
        return GuardrailResult::ok();
    }
}
```

## GuardrailRunner API

```php
$runner->register($guardrail);                          // 追加一个护栏
$runner->loadFromConfig([...]);                         // 从类名加载（替换）
$runner->checkInput($messages);                         // 检查输入，返回第一个拦截结果或 null
$runner->checkOutput($content);                         // 检查输出，返回第一个拦截结果或 null
$runner->only(['content_filter']);                      // 不可变过滤，返回新实例
```

## 拦截时的 AgentResult

当护栏拦截时，`AgentResult` 的状态为：

```php
$result->isComplete();          // false
$result->isGuardrailBlocked();  // true
$result->content;               // 拦截原因文本
$result->stopReason;            // 'guardrail_blocked'
```
