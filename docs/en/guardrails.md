# Guardrails

Guardrails are safety check mechanisms that run within the agent loop, split into **input checks** and **output checks**. They support both **synchronous** and **asynchronous** modes.

## Workflow

### Sync Guardrails (default)

```
User Message → [Sync Input Guardrails] → Agent Loop → [Sync Output Guardrails] → Return Result
                      ↓                                          ↓
                 Blocked → Return Error                      Blocked → Return Error
```

### Async Guardrails (Swoole)

```
User Message → [Sync Input Guardrails] ──────────→ Agent Loop ──→ [Sync Output Guardrails] ──→ Return Result
              [Async Input Guardrails ──→ running]                  [Async Output Guardrails ──→ running]
                      ↓ (completes during loop)                              ↓ (completes after output)
                  Blocked → Recall Event                              Blocked → COMPLETE → GUARDRAIL_RECALLED
```

- **Sync guardrails**: Block execution immediately, content never reaches the client
- **Async guardrails**: Run in background coroutines; content is emitted first, then recalled if blocked
- **Graceful degradation**: Without Swoole, async guardrails fall back to synchronous execution

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

## Guardrail Modes

Each guardrail can be configured as **sync** or **async**:

```php
use ChenZhanjie\Agentic\GuardrailMode;

// Register with mode (default is SYNC)
$guardrailRunner->register(new ToxicityDetector(), GuardrailMode::ASYNC);
```

### Config format

```php
// agents.php
return [
    'chat' => [
        'persona' => 'chat.md',
        'guardrails' => ['content_filter', 'toxicity_detector'],
        'guardrail_modes' => [                        // Optional
            'toxicity_detector' => 'async',            // Specify as async
            // Unlisted guardrails default to 'sync'
        ],
        'async_guardrail_timeout' => 5000,            // Optional, milliseconds, default 5000
    ],
];
```

### When to use async?

- **LLM-based guardrails** (toxicity detection, PII identification) that take seconds to complete
- **External API guardrails** (content moderation services) with network latency
- Any guardrail where blocking the response would noticeably impact user experience

### Event flow for async recall

When an async output guardrail blocks content:

```
THINKING → COMPLETE → GUARDRAIL_RECALLED
↑ Client sees content     ↑ Client retracts content
```

When an async input guardrail blocks during the loop:

```
THINKING → ... → GUARDRAIL_RECALLED
                  ↑ Loop interrupted, partial results discarded
```

## Registering Guardrails

### Method 1: Runtime Registration

```php
$guardrailRunner->register(new ContentFilterGuardrail());
```

### Method 2: Load from Config

```php
// Simple format (all SYNC)
$guardrailRunner->loadFromConfig([
    \App\Guardrail\ContentFilterGuardrail::class,
]);

// Extended format (with mode)
$guardrailRunner->loadFromConfig([
    ['class' => \App\Guardrail\ContentFilterGuardrail::class, 'mode' => 'sync'],
    ['class' => \App\Guardrail\ToxicityDetector::class, 'mode' => 'async'],
]);
```

> Note: `loadFromConfig()` replaces all previously registered guardrails.

### Method 3: withModes() Override

Apply mode overrides immutably:

```php
$runner = $guardrailRunner
    ->only(['toxicity', 'pii'])
    ->withModes(['toxicity' => GuardrailMode::ASYNC]);
```

## Per-Agent Guardrail Filtering

Specify guardrail whitelists per agent via `agents.php` or `runWithConfig()`:

```php
// agents.php
return [
    'chat' => [
        'guardrails' => ['content_filter'],  // Only enable content_filter
        'guardrail_modes' => ['content_filter' => 'async'],
    ],
    'admin' => [
        'guardrails' => [],                   // Empty = all guardrails active
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

Uses `GuardrailRunner::only(array $names)` internally — immutable filter returning a new instance, safe for concurrent requests.

## Message Recall (RecallTool)

The SDK includes a built-in `recall` tool for message retraction. This is the unified mechanism for all message interception scenarios:

- Async guardrail recall (automatic)
- LLM self-correction (LLM calls `recall` tool)
- External policy enforcement

### How it works

```php
// Automatically registered by ToolRegistryFactory
// The LLM can call it like any other tool:
// { "name": "recall", "arguments": { "message_id": "msg-123", "reason": "PII detected" } }
```

When a `MessageStoreInterface` is injected, recall automatically persists the change:

```php
$recallTool = new RecallTool($messageStore);
$result = $recallTool->execute([
    'conversation_id' => 'conv-1',
    'message_id' => 'msg-123',
    'reason' => 'toxic content',
]);
// The message is marked as recalled in the store
```

### MessageStore recall support

```php
interface MessageStoreInterface
{
    // ... existing methods ...
    public function recall(string $conversationId, string $messageId, string $reason): void;
}
```

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

### LLM-based Async Guardrail

```php
class ToxicityDetector implements GuardrailInterface
{
    public function name(): string { return 'toxicity_detector'; }

    public function checkInput(array $messages): GuardrailResult
    {
        // Use a secondary LLM call to detect toxicity
        $lastMessage = end($messages)['content'] ?? '';
        return $this->analyzeToxicity($lastMessage);
    }

    public function checkOutput(string $content): GuardrailResult
    {
        return $this->analyzeToxicity($content);
    }

    private function analyzeToxicity(string $text): GuardrailResult
    {
        // Call moderation API or secondary LLM
        // This may take seconds — ideal for ASYNC mode
        $score = $this->moderationApi->analyze($text);
        return $score > 0.8
            ? GuardrailResult::blocked('Toxic content detected (score: ' . $score . ')')
            : GuardrailResult::ok();
    }
}
```

## GuardrailRunner API

```php
$runner->register($guardrail);                          // Append a guardrail (SYNC by default)
$runner->register($guardrail, GuardrailMode::ASYNC);    // Append as async
$runner->loadFromConfig([...]);                         // Load from class names (replaces)
$runner->checkInput($messages);                         // Sync check, returns first blocked or null
$runner->checkOutput($content);                         // Sync check, returns first blocked or null
$runner->checkInputAsync($messages);                    // Returns AsyncGuardrailContext
$runner->checkOutputAsync($content);                    // Returns AsyncGuardrailContext
$runner->only(['content_filter']);                      // Immutable filter, returns new instance
$runner->withModes(['toxicity' => GuardrailMode::ASYNC]); // Immutable mode override
```

## AgentResult States

### When sync guardrail blocks:

```php
$result->isGuardrailBlocked();  // true
$result->content;               // '' (empty)
$result->stopReason;            // 'guardrail'
```

### When async guardrail recalls:

```php
$result->isGuardrailBlocked();  // true
$result->isRecalled();          // true
$result->content;               // The original output (before recall)
$result->recallReason;          // 'toxic content detected'
$result->stopReason;            // 'guardrail'
```

## New Event Types

| Event | When |
|-------|------|
| `guardrail_blocked` | Sync guardrail blocks before content reaches client |
| `guardrail_recalled` | Async guardrail blocks after content was emitted |
| `message_recalled` | RecallTool executed (LLM self-recall or external) |
