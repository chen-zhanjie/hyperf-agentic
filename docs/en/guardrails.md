# Guardrails

Guardrails are safety check mechanisms that run within the agent loop, split into **input checks** and **output checks**.

## Workflow

```
User Message → [Input Guardrail Check] → Agent Loop → [Output Guardrail Check] → Return Result
                    ↓                                        ↓
               Blocked → Return Error                  Blocked → Return Error
```

- **Input check**: Runs before the agent loop starts, validating user messages
- **Output check**: Runs after each LLM response, validating output content
- First blocked result stops execution — subsequent guardrails are not run

## GuardrailInterface

All guardrails must implement `ChenZhanjie\Agentic\Contract\GuardrailInterface`:

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
// Pass
GuardrailResult::ok();

// Block
GuardrailResult::blocked('Contains sensitive information');
```

## Registering Guardrails

### Method 1: Runtime Registration

```php
$guardrailRunner->register(new ContentFilterGuardrail());
```

### Method 2: Load from Config

```php
$guardrailRunner->loadFromConfig([
    \App\Guardrail\ContentFilterGuardrail::class,
    \App\Guardrail\PiiGuardrail::class,
]);
```

> Note: `loadFromConfig()` replaces all previously registered guardrails.

## Per-Agent Guardrail Filtering

Specify guardrail whitelists per agent via `agents.php` or `runWithConfig()`:

```php
// agents.php
return [
    'chat' => [
        'guardrails' => ['content_filter'],  // Only enable content_filter
    ],
    'admin' => [
        'guardrails' => [],                   // Empty = all guardrails active
    ],
];

// runWithConfig
$agentic->runWithConfig(
    ['guardrails' => ['content_filter', 'pii_filter']],
    $messages,
);
```

Uses `GuardrailRunner::only(array $names)` internally — immutable filter returning a new instance, safe for concurrent requests.

## Custom Guardrail Examples

### Content Filter Guardrail

```php
use ChenZhanjie\Agentic\Contract\GuardrailInterface;
use ChenZhanjie\Agentic\GuardrailResult;

class ContentFilterGuardrail implements GuardrailInterface
{
    private array $blockedPatterns = [
        '/violence/i', '/attack/i', '/drugs/i',
    ];

    public function name(): string { return 'content_filter'; }

    public function checkInput(array $messages): GuardrailResult
    {
        foreach ($messages as $msg) {
            foreach ($this->blockedPatterns as $pattern) {
                if (preg_match($pattern, $msg['content'] ?? '')) {
                    return GuardrailResult::blocked('Input contains prohibited content');
                }
            }
        }
        return GuardrailResult::ok();
    }

    public function checkOutput(string $content): GuardrailResult
    {
        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return GuardrailResult::blocked('Output contains prohibited content');
            }
        }
        return GuardrailResult::ok();
    }
}
```

### PII Detection Guardrail

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
        // Detect ID numbers
        if (preg_match('/\d{17}[\dXx]/', $content)) {
            return GuardrailResult::blocked('Output contains ID number');
        }
        // Detect phone numbers
        if (preg_match('/1[3-9]\d{9}/', $content)) {
            return GuardrailResult::blocked('Output contains phone number');
        }
        return GuardrailResult::ok();
    }
}
```

## GuardrailRunner API

```php
$runner->register($guardrail);                          // Append a guardrail
$runner->loadFromConfig([...]);                         // Load from class names (replaces)
$runner->checkInput($messages);                         // Check input, returns first blocked result or null
$runner->checkOutput($content);                         // Check output, returns first blocked result or null
$runner->only(['content_filter']);                      // Immutable filter, returns new instance
```

## AgentResult When Blocked

When a guardrail blocks execution, the `AgentResult` state is:

```php
$result->isComplete();          // false
$result->isGuardrailBlocked();  // true
$result->content;               // Block reason text
$result->stopReason;            // 'guardrail_blocked'
```
