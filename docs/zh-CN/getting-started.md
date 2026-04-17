# Getting Started

## Requirements

- PHP >= 8.1
- Hyperf framework
- Composer

## Installation

```bash
composer require chen-zhanjie/hyperf-agentic
```

Hyperf 的 `config-auto-scan` 会自动发现 `ConfigProvider`，无需手动注册。

## 发布配置文件

```bash
php bin/hyperf.php vendor:publish chen-zhanjie/hyperf-agentic
```

配置文件会发布到 `config/autoload/agentic/` 目录：

| 文件 | 用途 |
|------|------|
| `agentic.php` | 全局默认值（最大迭代、上下文引擎等） |
| `providers.php` | LLM 提供商配置（API Key、模型） |
| `agents.php` | Agent 定义（人设、工具、技能白名单） |
| `tools.php` | 工具注册（无法使用注解的类） |
| `session.php` | 会话存储（Redis 等） |
| `cli.php` | CLI 命令配置 |

## 快速使用

### 1. 配置 LLM 提供商

编辑 `config/autoload/agentic/providers.php`：

```php
return [
    'default' => 'openai',
    'providers' => [
        'openai' => [
            'protocol' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ],
    ],
];
```

### 2. 定义 Agent

编辑 `config/autoload/agentic/agents.php`：

```php
return [
    'general' => [
        'persona' => 'You are a helpful assistant.',
        'max_iterations' => 10,
    ],
];
```

或使用人设文件：

```php
return [
    'chat' => [
        'persona' => BASE_PATH . '/config/autoload/agentic/souls/chat.md',
        'tools' => ['search', 'ask'],
        'skills' => ['search-guide'],
        'max_iterations' => 15,
    ],
];
```

### 3. 在控制器中使用

```php
use ChenZhanjie\Agentic\Agentic;
use Hyperf\Di\Annotation\Inject;

class ChatController
{
    #[Inject]
    private Agentic $agentic;

    public function chat()
    {
        $result = $this->agentic->run('general', [
            ['role' => 'user', 'content' => 'Hello!'],
        ]);

        return ['response' => $result->content];
    }
}
```

### 4. 纯 LLM 对话（无工具循环）

```php
$response = $this->agentic->chat([
    ['role' => 'user', 'content' => 'Translate to English: 你好世界'],
]);
// $response 是字符串
```

## 下一步

- [配置参考](configuration.md) — 详细的配置项说明
- [API 参考](api.md) — Agentic 门面完整方法
- [工具系统](tools.md) — 注册和自定义工具
- [技能系统](skills.md) — 3 级渐进式披露
- [数据库驱动 Agent](database-agents.md) — 从数据库动态创建 Agent
