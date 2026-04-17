# API Reference

`ChenZhanjie\Agentic\Agentic` is the SDK's unified entry point (Layer 4 Facade). Inject via Hyperf DI.

```php
use ChenZhanjie\Agentic\Agentic;
use Hyperf\Di\Annotation\Inject;

class MyService
{
    #[Inject]
    private Agentic $agentic;
}
```

## Agent Execution

### run()

Execute a named agent (non-streaming).

```php
public function run(string $agentName, array $messages, array $options = []): AgentResult
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$agentName` | string | Agent name (key in `agents.php`) |
| `$messages` | array | Message array: `[['role' => 'user', 'content' => '...']]` |
| `$options` | array | Runtime options: `provider`, `model_override`, `runtime_context`, etc. |

**Returns:** `AgentResult`

**Throws:** `InvalidArgumentException` when agent is not defined

**Example:**

```php
$result = $this->agentic->run('general', [
    ['role' => 'user', 'content' => 'What is PHP?'],
]);

echo $result->content;          // Agent's response text
echo $result->iterations;       // Iteration count
echo $result->toolCalls;        // Tool call count
echo $result->elapsedMs;        // Execution time (ms)
echo $result->promptTokens;     // Prompt tokens consumed
echo $result->completionTokens; // Completion tokens consumed
```

### runStream()

Execute a named agent with streaming, sending SSE events via callback.

```php
public function runStream(
    string $agentName,
    array $messages,
    ?callable $onEvent = null,
    array $options = [],
): AgentResult
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$agentName` | string | Agent name |
| `$messages` | array | Message array |
| `$onEvent` | callable\|null | SSE event callback `fn(string $event, array $data) => void` |
| `$options` | array | Runtime options |

**Events:**

| Event | Description |
|-------|-------------|
| `started` | Agent loop started |
| `thinking` | About to call the LLM |
| `text_delta` | Text content chunk (`data['content']`), emitted in both sync and stream modes |
| `reasoning_delta` | Reasoning/thinking content chunk (`data['content']`), emitted in both sync and stream modes |
| `tool_call` | Tool call dispatched |
| `tool_result` | Tool result received |
| `complete` | Agent finished successfully |
| `error` | Agent encountered an error |
| `budget_exceeded` | Token budget exceeded |
| `guardrail_blocked` | Output blocked by guardrail |
| `suspended` | Agent suspended waiting for human input |

**Example:**

```php
$result = $this->agentic->runStream('general', $messages, function (string $event, array $data) {
    if ($event === 'text_delta') {
        echo $data['content'];
        ob_flush();
    }
});
```

### SSE Output

Use `SseWriter` to format streaming events as OpenAI-compatible SSE:

```php
use ChenZhanjie\Agentic\Stream\SseWriter;

$sse = new SseWriter(fn(string $line) => $eventStream->write($line));
$result = $agentic->runStream('general', $messages, $sse->asOnEvent());
```

For pure LLM chat streaming:

```php
$sse = new SseWriter(fn(string $line) => echo $line, model: 'gpt-4o');
$result = $agentic->chatStream($messages, $sse->asOnChunk());
$sse->finish($result->usage);
```

**SSE output format:**

Standard chunks (role, content, tool_call, finish) use the OpenAI `data:` wire format:

```
data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","content":""}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"content":"Hello"}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[...]}}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{...}}

data: [DONE]
```

`tool_result` events use a named SSE event type to stay outside the OpenAI chunk envelope:

```
event: tool_result
data: {"call_id":"call_abc","name":"search","result":"...","success":true}

```

Consumers receive `tool_result` via `EventSource.addEventListener('tool_result', ...)`. Standard OpenAI SDK parsers ignore named events automatically — no compatibility impact.

**Finish reasons:**

| Scenario | `finish_reason` |
|----------|----------------|
| Normal completion | `"stop"` |
| Budget exhausted | `"length"` |
| Guardrail blocked | `"content_filter"` |
| Explicit tool_calls | `"tool_calls"` |

### runWithConfig()

Execute an agent with a dynamic config array, bypassing agent name lookup. Designed for database-driven multi-agent scenarios.

```php
public function runWithConfig(Agent|array $agentConfig, array $messages, array $options = []): AgentResult
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$agentConfig` | Agent\|array | Agent DTO or configuration array (see structure below) |
| `$messages` | array | Message array |
| `$options` | array | `conversation_id`, `runtime_context`, etc. |

**`$agentConfig` structure:**

```php
[
    'persona' => new Persona(name: 'Bot', content: 'You are a bot.'),
    'tools' => ['search', 'ask'],        // Tool whitelist
    'skills' => ['guide'],               // Skill whitelist
    'guardrails' => ['content_filter'],  // Guardrail whitelist
    'guardrail_modes' => ['content_filter' => 'async'], // Guardrail mode overrides
    'tool_permissions' => [              // Tool permission rules
        'allow' => ['search_*', 'ask'],
        'deny' => ['exec_*'],
    ],
    'permission_mode' => 'default',     // Permission mode: default|auto|strict|readonly
    'auto_approve' => true,             // Auto-approve tools (true, or array of patterns)
    'max_iterations' => 15,              // Max iterations
    'system_prompt' => 'Extra rules',    // Additional system prompt
    'cancellation_timeout_ms' => 30000,  // Auto-cancel after 30s
]
```

**Example:**

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

> See [Database Agents](database-agents.md) for detailed usage.

### runStreamWithConfig()

Streaming variant of `runWithConfig()`.

```php
public function runStreamWithConfig(
    Agent|array $agentConfig,
    array $messages,
    ?callable $onEvent = null,
    array $options = [],
): AgentResult
```

## Pure LLM Chat

### chat()

Pure LLM chat without the agent loop (no tool calls).

```php
public function chat(array $messages, array $options = []): LlmResponse
```

**Returns:** `LlmResponse` (see DTO section below)

**Example:**

```php
$response = $this->agentic->chat([
    ['role' => 'user', 'content' => 'Translate to English: 你好世界'],
]);

echo $response->content;              // "Hello World"
echo $response->usage['prompt_tokens']; // Token usage
```

### chatStream()

Pure LLM streaming chat, forwarding chunks to a callback.

```php
public function chatStream(array $messages, callable $onChunk, array $options = []): LlmResponse
```

**Returns:** `LlmResponse` — the fully assembled response after streaming completes.

**Example:**

```php
$result = $this->agentic->chatStream($messages, function (array $chunk) {
    if (isset($chunk['content'])) {
        echo $chunk['content'];
        ob_flush();
    }
});

echo $result->content; // Full assembled response
```

When using `SseWriter` with `chatStream`, note that `finish()` accepts a usage array:

```php
$sse = new SseWriter(fn(string $line) => echo $line, model: 'gpt-4o');
$result = $agentic->chatStream($messages, $sse->asOnChunk());
$sse->finish($result->usage);
```

## Session Resume

### resume()

Resume a suspended agent session.

```php
public function resume(string $sessionId): AgentResult
```

**Throws:** `RuntimeException` when SessionStore is not configured or session not found

## Query Methods

### agents()

Get all defined agent names.

```php
public function agents(): array
```

### availableTools()

Get all enabled tool names.

```php
public function availableTools(): array
```

### persona()

Get the Persona object for a named agent.

```php
public function persona(string $agentName): ?Persona
```

### has()

Check if an agent is defined.

```php
public function has(string $agentName): bool
```

## Configuration

### setHumanInputResolver()

Set the human input resolver (injected into AskTool).

```php
public function setHumanInputResolver(HumanInputResolverInterface $resolver): void
```

## Permission Approval

Manage tool execution approvals globally or per-session.

### approveTool()

Approve a tool or pattern globally or for a specific session.

```php
public function approveTool(string $toolOrPattern, ?string $sessionId = null): void
```

**Example:**

```php
// Approve globally
$this->agentic->approveTool('search_*');

// Approve for a specific session
$this->agentic->approveTool('delete_db', 'conv-123');
```

### approveAll()

Approve all tools globally or for a specific session.

```php
public function approveAll(?string $sessionId = null): void
```

### revokeTool()

Revoke a specific approval.

```php
public function revokeTool(string $toolOrPattern, ?string $sessionId = null): void
```

### revokeAll()

Revoke all approvals globally or for a session.

```php
public function revokeAll(?string $sessionId = null): void
```

## AgentResult

All `run*` methods return an `AgentResult` object.

### Public Properties

| Property | Type | Description |
|----------|------|-------------|
| `$content` | string | Agent response text |
| `$reasoningContent` | ?string | Reasoning content (if model supports it) |
| `$iterations` | int | Iteration count |
| `$elapsedMs` | int | Execution time (ms) |
| `$promptTokens` | int | Prompt tokens consumed |
| `$completionTokens` | int | Completion tokens consumed |
| `$toolCalls` | int | Tool call count |
| `$stopReason` | ?string | Stop reason |

### Status Methods

| Method | Description |
|--------|-------------|
| `isComplete()` | Completed successfully |
| `isSuspended()` | Suspended waiting for human input |
| `isBudgetExhausted()` | Iteration budget exhausted |
| `isGuardrailBlocked()` | Blocked by a guardrail |

### Serialization

```php
$result->toArray(); // Convert to associative array
```

## LlmResponse

`chat()` and `chatStream()` return a `LlmResponse` DTO.

### Public Properties

| Property | Type | Description |
|----------|------|-------------|
| `$content` | string | LLM response text |
| `$usage` | array | Token usage: `['prompt_tokens' => int, 'completion_tokens' => int]` |
| `$model` | ?string | Model name used |
| `$provider` | ?string | Provider name used |
| `$reasoningContent` | ?string | Reasoning content (if model supports it) |
| `$toolCalls` | array | Tool calls (if any) |

### Serialization

```php
$response->toArray(); // Convert to associative array (backward compat)
```

## LlmCallMeta

Passed to `MiddlewareInterface::afterLlmCall()` for observability.

```php
public function afterLlmCall(array $response, LlmCallMeta $meta): void
```

### Public Properties

| Property | Type | Description |
|----------|------|-------------|
| `$provider` | string | LLM provider name |
| `$model` | string | Model name |
| `$promptTokens` | int | Prompt tokens consumed |
| `$completionTokens` | int | Completion tokens consumed |
| `$totalTokens` | int | Total tokens |

## Middleware

### Fault Tolerance

Notification methods (`afterLoop`, `afterLlmCall`, `afterToolCall`) catch exceptions internally and log a warning without breaking the agent loop. Chain methods (`beforeLoop`, `beforeLlmCall`) still throw on failure since they transform data and must be correct.
