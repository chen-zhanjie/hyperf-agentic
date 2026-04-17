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
// $response 是 LlmResponse DTO
echo $response->content; // "Hello World"
```

## 本地调试 CLI

SDK 自带独立的 `debug.php` 调试脚本，无需 Hyperf 框架即可在本地快速调试 Agent 功能。支持交互式工具调用、ask 工具的确认/单选/多选，以及流式输出。

### 配置

将 `.env.test.example` 复制为 `.env.test` 并填入 API 凭据：

```bash
cp .env.test.example .env.test
# 编辑 .env.test 填入你的 API Key
```

### 启动会话

```bash
php debug.php                         # OpenAI 协议，默认模型
php debug.php --protocol anthropic    # Anthropic 协议
php debug.php --model gpt-4o-mini     # 指定模型
php debug.php --stream                # 流式模式
```

### 会话命令

| 命令 | 说明 |
|------|------|
| `/quit`、`/exit` | 退出会话 |
| `/reset` | 清空对话历史 |
| `/stream` | 切换流式/非流式模式 |
| `/model <name>` | 切换模型 |

### 内置工具

调试 CLI 注册了 3 个测试工具：

| 工具 | 说明 |
|------|------|
| `get_time` | 获取指定时区的当前时间 |
| `calculate` | 计算数学表达式 |
| `ask` | 交互式用户输入（确认 / 单选 / 多选 / 文本） |

### 示例提示词

使用以下提示词体验不同的交互场景：

| 场景 | 提示词 | 预期行为 |
|------|--------|----------|
| 工具调用 | `现在几点了？北京时间` | 调用 `get_time`，传入 `Asia/Shanghai` |
| 数学计算 | `计算 123 * 456 + 789` | 调用 `calculate` |
| 确认 | `确认删除所有临时文件` | 通过 `ask` 弹出确认提示 (confirm) |
| 单选 | `帮我从美式、拿铁、卡布奇诺里选一杯咖啡` | 通过 `ask` 显示单选菜单 (select) |
| 多选 | `我想吃水果，从苹果、香蕉、橙子、葡萄里帮我挑几种` | 通过 `ask` 显示多选菜单 (multiselect) |
| 多工具 | `帮我算一下 (15 + 27) * 3，然后告诉我现在是几点` | 先调用 `calculate` 再调用 `get_time` |
| 流式体验 | 先输入 `/stream`，再正常对话 | 实时逐 token 输出，含推理过程 |

> **注意：** LLM 自行决定调用哪个工具以及如何构造 `ask` 字段。不同模型的工具调用模式可能略有差异。以上提示词经过验证，能可靠触发对应的交互类型。

## 下一步

- [配置参考](configuration.md) — 详细的配置项说明
- [API 参考](api.md) — Agentic 门面完整方法
- [工具系统](tools.md) — 注册和自定义工具
- [技能系统](skills.md) — 3 级渐进式披露
- [数据库驱动 Agent](database-agents.md) — 从数据库动态创建 Agent
