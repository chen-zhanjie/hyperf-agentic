# Conversations（对话持久化）

SDK 支持通过 `MessageStoreInterface` 实现多轮对话持久化。当提供 `conversation_id` 时，自动加载历史消息并追加新消息。

## 工作流程

```
用户请求（含 conversation_id）
    │
    ├─ 1. 从 MessageStore 加载历史消息
    ├─ 2. 合并新消息到历史消息末尾
    ├─ 3. 将完整消息发送给 LLM
    ├─ 4. 获取 Agent 回复
    └─ 5. 将新消息 + 回复追加到 MessageStore
```

**仅在 Agent 正常完成（`isComplete() === true`）时才持久化。** 被护栏拦截或预算耗尽时不写入。

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

## 内置实现

### MemoryMessageStore

内存实现，用于测试和无状态场景。数据不跨请求保留。

```php
use ChenZhanjie\Agentic\Session\MemoryMessageStore;

$store = new MemoryMessageStore();
```

SDK 默认绑定：

```php
// ConfigProvider.php
Contract\MessageStoreInterface::class => Session\MemoryMessageStore::class,
```

## 自定义实现

实现 `MessageStoreInterface` 即可接入 Redis、数据库等持久化存储：

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

在 Hyperf 中覆盖默认绑定：

```php
// config/autoload/dependencies.php
return [
    \ChenZhanjie\Agentic\Contract\MessageStoreInterface::class => \App\Store\RedisMessageStore::class,
];
```

## 使用对话持久化

通过 `runWithConfig()` 或 `runStreamWithConfig()` 的 `options` 参数传入 `conversation_id`：

```php
$result = $agentic->runWithConfig(
    ['persona' => new Persona(name: 'Bot', content: 'You are helpful.')],
    [['role' => 'user', 'content' => '我叫张三']],
    ['conversation_id' => 'conv-123'],
);

// 第二轮 — 历史消息自动加载
$result = $agentic->runWithConfig(
    ['persona' => new Persona(name: 'Bot', content: 'You are helpful.')],
    [['role' => 'user', 'content' => '我叫什么名字？']],
    ['conversation_id' => 'conv-123'],
);
// Agent 能回答 "张三"，因为历史消息被自动加载
```

## 消息格式

消息数组使用标准 OpenAI 格式：

```php
[
    ['role' => 'system', 'content' => '...'],    // 系统消息（自动注入）
    ['role' => 'user', 'content' => '...'],       // 用户消息
    ['role' => 'assistant', 'content' => '...'],  // Agent 回复
    ['role' => 'tool', 'content' => '...'],       // 工具结果
]
```

持久化时会追加：

```php
// 原始历史 + 新用户消息 + Agent 回复
$toAppend = $newMessages;
$toAppend[] = ['role' => 'assistant', 'content' => $result->content];
$store->append($conversationId, $toAppend);
```
