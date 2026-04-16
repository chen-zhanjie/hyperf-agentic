# Configuration Reference

所有配置文件位于 `config/autoload/agentic/`。

## agentic.php — 全局配置

```php
return [
    'default_agent' => 'general',
    'max_iterations' => 15,
    'max_total_tokens' => 200000,
    'grace_turn' => true,
    'context_engine' => null,     // null | 'sliding_window' | 'summary'
    'memory_provider' => null,    // null | 'redis'
];
```

| 键 | 类型 | 默认值 | 说明 |
|----|------|--------|------|
| `default_agent` | string | `'general'` | 未指定 agent 时使用的默认 agent 名称 |
| `max_iterations` | int | `15` | Agent 循环最大迭代次数 |
| `max_total_tokens` | int | `200000` | 单次运行最大 token 消耗 |
| `grace_turn` | bool | `true` | 最后一次迭代是否允许无工具调用的"优雅退出" |
| `context_engine` | string\|null | `null` | 上下文压缩策略 |
| `memory_provider` | string\|null | `null` | 记忆提供者 |

## providers.php — LLM 提供商

```php
return [
    'default' => 'openai',
    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ],
        'deepseek' => [
            'driver' => 'openai',       // 兼容 OpenAI 协议
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => 'https://api.deepseek.com/v1',
            'model' => 'deepseek-chat',
            'max_tokens' => 4096,
        ],
    ],
];
```

| 键 | 类型 | 必填 | 说明 |
|----|------|------|------|
| `default` | string | 是 | 默认提供商名称 |
| `providers` | array | 是 | 提供商配置映射 |
| `providers.{name}.driver` | string | 是 | 驱动类型：`openai` |
| `providers.{name}.api_key` | string | 是 | API 密钥 |
| `providers.{name}.base_url` | string | 是 | API 基础 URL |
| `providers.{name}.model` | string | 是 | 模型名称 |
| `providers.{name}.max_tokens` | int | 否 | 单次请求最大 token 数 |
| `providers.{name}.temperature` | float | 否 | 生成温度 (0.0 - 2.0) |

## agents.php — Agent 定义

```php
return [
    'chat' => [
        'persona' => BASE_PATH . '/config/autoload/agentic/souls/chat.md',
        'tools' => ['search', 'ask'],
        'skills' => ['search-guide'],
        'guardrails' => [],
        'max_iterations' => 15,
        'system_prompt' => '',
    ],
    'coder' => [
        'persona' => 'You are an expert programmer.',
        'tools' => [],
        'max_iterations' => 20,
    ],
];
```

| 键 | 类型 | 默认值 | 说明 |
|----|------|--------|------|
| `persona` | string\|null | `null` | 人设文件路径或内联文本 |
| `tools` | string[] | `[]` | 工具白名单（空数组 = 全部可用） |
| `skills` | string[] | `[]` | 技能白名单（空数组 = 全部可用） |
| `guardrails` | string[] | `[]` | 护栏白名单（空数组 = 全局生效） |
| `guardrail_modes` | array | `[]` | 护栏模式覆盖：`['name' => 'async']` |
| `tool_permissions` | array | `[]` | 权限规则：`['allow' => [...], 'ask' => [...], 'deny' => [...]]` |
| `permission_mode` | string | `'default'` | 权限模式：`default`、`auto`、`strict`、`readonly` |
| `auto_approve` | bool\|array\|null | `null` | 自动审批工具：`true`（全部）、`['pattern']`（指定模式）或 `null`（禁用） |
| `max_iterations` | int\|null | `null` | 覆盖全局 max_iterations |
| `system_prompt` | string | `''` | 附加系统提示文本 |
| `cancellation_timeout_ms` | int | `0` | 自动取消超时（毫秒，0 = 禁用） |
| `async_guardrail_timeout` | int | `5000` | 异步护栏完成超时（毫秒） |

## tools.php — 工具注册

```php
return [
    'classes' => [
        \App\Tool\SearchTool::class,
        \App\Tool\CalculatorTool::class,
    ],
];
```

支持 `#[AsTool]` 注解的工具无需在此注册，会自动发现。

## session.php — 会话存储

```php
return [
    'driver' => \ChenZhanjie\Agentic\Session\RedisSessionStore::class,
    'ttl' => 3600,
    'prefix' => 'agentic:session:',
];
```

| 键 | 类型 | 默认值 | 说明 |
|----|------|--------|------|
| `driver` | string | RedisSessionStore | SessionStoreInterface 实现 |
| `ttl` | int | `3600` | 会话存活时间（秒） |
| `prefix` | string | `'agentic:session:'` | Redis key 前缀 |

## cli.php — CLI 命令

```php
return [
    'commands' => [],
    'default_agent' => 'general',
];
```
