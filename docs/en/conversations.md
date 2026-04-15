# Conversations (Message Persistence)

The SDK supports multi-turn conversation persistence via `MessageStoreInterface`. When a `conversation_id` is provided, history is automatically loaded and new messages are appended.

## Workflow

```
User Request (with conversation_id)
    │
    ├─ 1. Load history from MessageStore
    ├─ 2. Merge new messages at the end of history
    ├─ 3. Send complete messages to LLM
    ├─ 4. Get agent response
    └─ 5. Append new messages + response to MessageStore
```

**Messages are only persisted when the agent completes successfully (`isComplete() === true`).** Guardrail-blocked or budget-exhausted results are not written.

## MessageStoreInterface

```php
interface MessageStoreInterface
{
    public function load(string $conversationId): array;
    public function append(string $conversationId, array $messages): void;
    public function delete(string $conversationId): void;
    public function exists(string $conversationId): bool;
}
```

## Built-in Implementation

### MemoryMessageStore

In-memory implementation for testing and stateless usage. Data does not persist across requests.

```php
use ChenZhanjie\Agentic\Session\MemoryMessageStore;

$store = new MemoryMessageStore();
```

Default SDK binding:

```php
// ConfigProvider.php
Contract\MessageStoreInterface::class => Session\MemoryMessageStore::class,
```

## Custom Implementation

Implement `MessageStoreInterface` to integrate Redis, databases, or other persistent storage:

```php
use ChenZhanjie\Agentic\Contract\MessageStoreInterface;

class RedisMessageStore implements MessageStoreInterface
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'agentic:messages:',
        private readonly int $ttl = 86400,
    ) {}

    public function load(string $conversationId): array
    {
        $data = $this->redis->get($this->prefix . $conversationId);
        return $data ? json_decode($data, true) : [];
    }

    public function append(string $conversationId, array $messages): void
    {
        $key = $this->prefix . $conversationId;
        $existing = $this->load($conversationId);
        $merged = array_merge($existing, $messages);
        $this->redis->setex($key, $this->ttl, json_encode($merged, JSON_UNESCAPED_UNICODE));
    }

    public function delete(string $conversationId): void
    {
        $this->redis->del($this->prefix . $conversationId);
    }

    public function exists(string $conversationId): bool
    {
        return (bool) $this->redis->exists($this->prefix . $conversationId);
    }
}
```

Override the default binding in Hyperf:

```php
// config/autoload/dependencies.php
return [
    \ChenZhanjie\Agentic\Contract\MessageStoreInterface::class => \App\Store\RedisMessageStore::class,
];
```

## Using Conversation Persistence

Pass `conversation_id` in the `options` parameter of `runWithConfig()` or `runStreamWithConfig()`:

```php
$result = $agentic->runWithConfig(
    ['persona' => new Persona(name: 'Bot', content: 'You are helpful.')],
    [['role' => 'user', 'content' => 'My name is John']],
    ['conversation_id' => 'conv-123'],
);

// Second turn — history is automatically loaded
$result = $agentic->runWithConfig(
    ['persona' => new Persona(name: 'Bot', content: 'You are helpful.')],
    [['role' => 'user', 'content' => 'What is my name?']],
    ['conversation_id' => 'conv-123'],
);
// Agent can answer "John" because history was auto-loaded
```

## Message Format

Messages use the standard OpenAI format:

```php
[
    ['role' => 'system', 'content' => '...'],    // System message (auto-injected)
    ['role' => 'user', 'content' => '...'],       // User message
    ['role' => 'assistant', 'content' => '...'],  // Agent response
    ['role' => 'tool', 'content' => '...'],       // Tool result
]
```

When persisting, the SDK appends:

```php
// Original history + new user messages + agent response
$toAppend = $newMessages;
$toAppend[] = ['role' => 'assistant', 'content' => $result->content];
$store->append($conversationId, $toAppend);
```
