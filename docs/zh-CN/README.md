# Hyperf Agentic SDK

简体中文 | [English](README.md)

一个配置驱动的 AI Agent SDK，为 Hyperf 应用而生。**Agent 是配置，不是类。**

## 安装

```bash
composer require chenzhanjie/hyperf-agentic
```

发布配置文件：

```bash
php bin/hyperf.php vendor:publish chenzhanjie/hyperf-agentic
```

## 快速开始

### 1. 配置 LLM 提供商

编辑 `config/autoload/agentic/providers.php`：

```php
return [
    'default' => 'openai',
    'providers' => [
        'openai' => [
            'protocol' => 'openai',
            'api_key'  => env('OPENAI_API_KEY'),
            'base_url' => 'https://api.openai.com/v1',
            'model'    => 'gpt-4o',
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

### 3. 使用 Agent

```php
use ChenZhanjie\Agentic\Agentic;

$agentic = $this->container->get(Agentic::class);

$result = $agentic->run('general', [
    ['role' => 'user', 'content' => '你好！'],
]);

echo $result->content;
```

### 数据库驱动 Agent（v0.4.0）

从数据库动态创建 Agent：

```php
use ChenZhanjie\Agentic\Persona\Persona;

$result = $agentic->runWithConfig(
    [
        'persona' => new Persona(name: 'Expert', content: 'You are an expert.'),
        'tools' => ['search'],
        'skills' => ['search-guide'],
        'max_iterations' => 15,
    ],
    [['role' => 'user', 'content' => '你好']],
    ['conversation_id' => 'conv-123'],  // 自动管理对话历史
);
```

## 核心 API

| 方法 | 说明 |
|------|------|
| `run(name, messages)` | 执行指定 Agent |
| `runStream(name, messages, onEvent)` | 流式执行（SSE） |
| `runWithConfig(config, messages, options)` | 动态配置执行 |
| `runStreamWithConfig(config, messages, onEvent, options)` | 动态配置 + 流式 |
| `chat(messages)` | 纯 LLM 对话（无工具） |
| `chatStream(messages, onChunk)` | 纯 LLM 流式对话 |
| `resume(sessionId)` | 恢复挂起的会话 |

## 文档

| 文档 | 说明 |
|------|------|
| [快速开始](docs/getting-started.md) | 安装与快速开始 |
| [配置参考](docs/configuration.md) | 完整配置项说明 |
| [API 参考](docs/api.md) | Agentic 门面方法参考 |
| [工具系统](docs/tools.md) | 工具注册、自定义工具、内置工具 |
| [技能系统](docs/skills.md) | 3 级渐进式披露技能系统 |
| [安全护栏](docs/guardrails.md) | 输入/输出安全检查 |
| [对话持久化](docs/conversations.md) | 多轮对话历史管理 |
| [数据库 Agent](docs/database-agents.md) | 数据库驱动动态 Agent |
| [架构概览](docs/architecture.md) | 5 层架构设计 |
| [更新日志](docs/changelog.md) | 版本变更记录 |

## 架构

```
Layer 5: 入口层 (Controller / Command / CLI)
    ↓
Layer 4: 门面层 (Agentic.php — 配置驱动的统一入口)
    ↓
Layer 3: Agent 核心 (AgentRunner + GuardrailRunner + MiddlewarePipeline)
    ↓
Layer 2: 子系统 (ToolRegistry / PromptBuilder / LlmClient / SkillRegistry)
    ↓
Layer 1: 基础层 (Contract/ — 接口，零上游依赖)
```

## 测试

```bash
composer install
vendor/bin/phpunit
```

500 个测试，916 个断言 — 全部通过。

## 许可证

MIT
