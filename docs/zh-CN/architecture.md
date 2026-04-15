# Architecture

SDK 采用 5 层架构设计，从底层接口到上层入口逐步构建。

## 层次结构

```
Layer 1: Contract（接口层）
    │   ToolInterface, GuardrailInterface, SkillInterface,
    │   MessageStoreInterface, SessionStoreInterface, ...
    │
Layer 2: Subsystems（子系统）
    │   ToolRegistry, GuardrailRunner, SkillRegistry,
    │   PromptBuilder, LlmClient, MiddlewarePipeline
    │
Layer 3: Agent Core（Agent 核心）
    │   AgentRunner, AgentResult, AgentConfigManager
    │
Layer 4: Facade（门面）
    │   Agentic — 统一入口
    │
Layer 5: Entry Points（入口）
        Hyperf Controllers, CLI Commands, HTTP API
```

## 核心概念

### Config-Driven（配置驱动）

Agent 是一个配置数组，不是一个类。这源自 Hermes 设计理念：

```php
$agentConfig = [
    'persona' => new Persona(...),
    'tools' => ['search'],
    'skills' => ['guide'],
    'max_iterations' => 15,
];
```

### Agent 循环

AgentRunner 实现了标准的 ReAct（Reasoning + Acting）循环：

```
┌─────────────────────────────┐
│  构建系统提示（PromptBuilder） │
└────────────┬────────────────┘
             │
             ▼
┌─────────────────────────────┐
│  检查输入护栏                 │ ◄── 拦截 → 返回 GuardrailBlocked
└────────────┬────────────────┘
             │
             ▼
┌─────────────────────────────┐
│       Agent 循环              │
│  ┌───────────────────────┐  │
│  │ 调用 LLM              │  │
│  └──────────┬────────────┘  │
│             │                │
│             ▼                │
│  ┌───────────────────────┐  │
│  │ 检查输出护栏           │  │ ◄── 拦截 → 退出循环
│  └──────────┬────────────┘  │
│             │                │
│             ▼                │
│  ┌───────────────────────┐  │
│  │ LLM 返回工具调用？     │  │
│  │  是 → 执行工具 → 继续循环 │
│  │  否 → 退出循环          │
│  └───────────────────────┘  │
│                             │
│  迭代次数达上限？→ BudgetExhausted │
└────────────┬────────────────┘
             │
             ▼
┌─────────────────────────────┐
│  返回 AgentResult            │
└─────────────────────────────┘
```

### PromptBuilder（7 层提示构建）

系统提示由 7 层内容组成，分为**缓存层**和**临时层**：

**缓存层**（同一 Agent 内不变，只构建一次）：

| 层 | 内容 |
|----|------|
| L1 | Agent 身份（Persona） |
| L2 | 场景规则（HTTP / CLI） |
| L3 | 工具描述（JSON Schema） |
| L4 | 技能索引（Level 1 描述） |
| L5 | 记忆快照（Memory） |

**临时层**（每次请求可能不同）：

| 层 | 内容 |
|----|------|
| L6 | 运行时上下文（Runtime Context） |
| L7 | 预算状态（Iteration / Cost Budget） |

### 不可变过滤

`ToolRegistry::only()` 和 `GuardrailRunner::only()` 采用不可变模式：

```php
// 返回新实例，不修改原始对象
$filteredRegistry = $registry->only(['search', 'ask']);
```

这保证了 Hyperf 协程模型下的并发安全 — 不同请求的 per-agent 过滤互不影响。

## DI 绑定

`ConfigProvider` 注册所有服务：

```php
// 接口 → 实现
Contract\MessageStoreInterface::class => Session\MemoryMessageStore::class,

// 工厂（__invoke 产生实例）
Skill\SkillRegistry::class => SkillRegistryFactory::class,
ToolRegistry::class => ToolRegistryFactory::class,

// 自注册（构造函数注入依赖）
AgentRunner::class => AgentRunner::class,
Agentic::class => Agentic::class,
```

## 并发安全

Hyperf 使用协程模型。SDK 的并发安全保证：

1. **PromptBuilder reset**：`AgentRunner::run()` 开头调用 `reset()`，清除上次构建的缓存。`reset()` + `build()` 之间无 I/O yield，单协程内原子操作。
2. **不可变过滤**：`only()` 返回新实例，不影响全局单例。
3. **局部变量**：`$systemMessage`、`$messages` 等是方法局部变量，天然隔离。
4. **SessionStore**：每次请求使用不同的 `conversation_id` / `sessionId`，Redis 天然隔离。

## 目录结构

```
src/
├── Contract/          # Layer 1: 接口定义
│   ├── ToolInterface.php
│   ├── GuardrailInterface.php
│   ├── SkillInterface.php
│   ├── MessageStoreInterface.php
│   ├── SessionStoreInterface.php
│   └── ...
├── Tool/              # 工具系统
│   └── Builtin/       # 内置工具（AskTool, SkillTool）
├── Skill/             # 技能系统
│   ├── Skill.php
│   └── SkillRegistry.php
├── Guardrail/         # 护栏（GuardrailResult 等）
├── Session/           # 会话存储
├── Persona/           # 人设（Persona, PersonaLoader）
├── Loader/            # 加载器（Annotation, Config, Skill）
├── Event/             # 事件系统
├── Tracing/           # 链路追踪
├── Attributes/        # PHP 8 Attribute（#[AsTool] 等）
├── AgentRunner.php    # Layer 3: Agent 核心
├── AgentResult.php    # Agent 执行结果
├── PromptBuilder.php  # 提示构建器
├── ToolRegistry.php   # 工具注册表
├── GuardrailRunner.php # 护栏运行器
├── LlmClient.php      # LLM 客户端
├── Agentic.php        # Layer 4: 统一门面
└── ConfigProvider.php # Hyperf DI 配置
```
