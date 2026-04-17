# 架构

SDK 采用 5 层架构设计，从底层接口到上层入口逐步构建。

## 层次结构

```
Layer 1: Contract（接口层）
    │   ToolInterface, GuardrailInterface, SkillInterface,
    │   MessageStoreInterface, SessionStoreInterface,
    │   ToolGuardrailInterface, ToolPermissionPolicyInterface,
    │   GuardrailAuditLoggerInterface, RiskyToolInterface, ...
    │
Layer 2: Subsystems（子系统）
    │   ToolRegistry, GuardrailRunner, ToolGuardrailRunner,
    │   SkillRegistry, PromptBuilder, LlmClient, MiddlewarePipeline,
    │   ToolDispatcher, LlmAdapter (OpenAiAdapter, AnthropicAdapter)
    │
Layer 3: Agent Core（Agent 核心）
    │   AgentRunner, ToolDispatcher, LoopState,
    │   AgentRunContext, AgentResult
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
    'tool_permissions' => ['deny' => ['exec_*']],
    'cancellation_timeout_ms' => 30000,
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
│  │  否 → 退出循环          │  │
│  └───────────────────────┘  │
│                             │
│  迭代次数或 Token 预算达上限？→ BudgetExhausted │
│  CancellationToken 已取消？→ 退出循环 │
└────────────┬────────────────┘
             │
             ▼
┌─────────────────────────────┐
│  返回 AgentResult            │
└─────────────────────────────┘
```

### 工具分发链

`ToolDispatcher` 拥有工具分发链，注入到 `AgentRunner` 中：

```
1. 工具护栏（输入检查）        → 可拦截或修正参数
2. 审批存储绕过                → 已预审批的工具跳过策略检查
3. 权限策略（deny/ask/allow）  → 可拒绝或要求用户确认
4. 人工审批（如果为 ASK）      → ONCE / TOOL / SESSION / DENY
5. 中间件（beforeToolCall）    → 可拦截
6. Agent 级处理器              → 或 ToolRegistry::execute()
7. 工具护栏（输出检查）        → 可拦截或转换输出
8. 中间件（afterToolCall）
```

审批提示可通过 `Support\ApprovalPrompts` 自定义 — 覆盖静态属性以实现国际化。

### AgentRunContext（Per-Request 上下文）

`AgentRunContext` 是每次请求创建的不可变值对象，持有所有请求级状态：

- 活跃护栏（按 Agent 过滤）
- 工具护栏
- 权限策略
- 审批存储（每次请求克隆隔离）
- 人工输入解析器
- Agent 级工具处理器
- 取消令牌
- 会话 ID

这替代了单例 `AgentRunner` 上的可变实例属性，消除了 Swoole 协程下的竞态条件。

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
Contract\ToolPermissionPolicyInterface::class => Policy\ConfigToolPermissionPolicy::class,
Contract\GuardrailAuditLoggerInterface::class => GuardrailAuditLogger::class,
Contract\PermissionApprovalStoreInterface::class => PermissionApprovalStore::class,

// 工厂（__invoke 产生实例）
Skill\SkillRegistry::class => SkillRegistryFactory::class,
ToolRegistry::class => ToolRegistryFactory::class,

// 自注册（构造函数注入依赖）
ToolDispatcher::class => ToolDispatcher::class,
AgentRunner::class => AgentRunner::class,
Agentic::class => Agentic::class,
```

## 并发安全

Hyperf 使用协程模型。SDK 的并发安全保证：

1. **AgentRunContext**：每次请求的不可变上下文替代可变实例属性，消除了并发请求共享单例 `AgentRunner` 时的竞态条件。
2. **PromptBuilder reset**：`AgentRunner::run()` 开头调用 `reset()`，清除上次构建的缓存。`reset()` + `build()` 之间无 I/O yield，单协程内原子操作。
3. **不可变过滤**：`only()` 返回新实例，不影响全局单例。
4. **局部变量**：`$systemMessage`、`$messages` 等是方法局部变量，天然隔离。
5. **SessionStore**：每次请求使用不同的 `conversation_id` / `sessionId`，Redis 天然隔离。

## 目录结构

```
src/
├── Contract/          # Layer 1: 接口定义
│   ├── ToolInterface.php
│   ├── GuardrailInterface.php
│   ├── ToolGuardrailInterface.php
│   ├── ToolPermissionPolicyInterface.php
│   ├── PermissionApprovalStoreInterface.php
│   ├── GuardrailAuditLoggerInterface.php
│   ├── RiskyToolInterface.php
│   ├── SkillInterface.php
│   ├── MessageStoreInterface.php
│   ├── SessionStoreInterface.php
│   └── ...
├── Tool/              # 工具系统
│   └── Builtin/       # 内置工具（AskTool, SkillTool）
├── Skill/             # 技能系统
│   ├── Skill.php
│   └── SkillRegistry.php
├── Guardrail/         # 护栏
│   └── SchemaValidationToolGuardrail.php
├── Policy/            # 权限策略
│   └── ConfigToolPermissionPolicy.php
├── Session/           # 会话存储
├── Resolver/          # 人工输入解析器（Cli, Http, Null）
├── Persona/           # 人设（Persona, PersonaLoader）
├── Loader/            # 加载器（Annotation, Config, Skill）
├── Event/             # 事件系统
├── Tracing/           # 链路追踪
├── Support/           # 支持工具
│   ├── ApprovalPrompts.php    # 可自定义的审批提示模板
│   ├── ConfigLoader.php
│   ├── DefaultPrompts.php
│   └── TokenEstimator.php
├── Attributes/        # PHP 8 Attribute（#[AsTool] 等）
├── LlmAdapter/        # LLM 协议适配器
│   ├── OpenAiAdapter.php    # OpenAI /v1/chat/completions
│   └── AnthropicAdapter.php # Anthropic /v1/messages
├── Stream/            # 流式传输适配器
│   └── SseWriter.php        # OpenAI 兼容 SSE 写入器
├── AgentRunner.php    # Layer 3: Agent 核心
├── TurnExecutor.php   # Layer 3: 单轮执行（统一同步/流式）
├── Agent.php          # Agent DTO（配置即数据）
├── ToolDispatcher.php # Layer 3: 工具分发链（护栏 → 权限 → 执行）
├── LoopState.php      # 每次请求的可变循环累加器
├── AgentRunContext.php # Per-Request 不可变上下文
├── AgentResult.php    # Agent 执行结果
├── ApprovalChoice.php # 用户审批选择枚举（ONCE/TOOL/SESSION/DENY）
├── PermissionMode.php # 权限模式枚举（DEFAULT/AUTO/STRICT/READONLY）
├── PermissionApprovalStore.php # 内存审批存储（通配符 + 双作用域）
├── PromptBuilder.php  # 提示构建器
├── ToolRegistry.php   # 工具注册表
├── ToolGuardrailRunner.php  # 工具级护栏运行器
├── ToolGuardrailResult.php  # 工具护栏结果值对象
├── ToolRiskLevel.php  # 工具风险等级枚举
├── ToolPermissionDecision.php # 权限决策枚举
├── GuardrailRunner.php # 护栏运行器（含优先级 + 审计）
├── GuardrailAuditEntry.php  # 审计日志条目
├── GuardrailAuditLogger.php # 默认审计日志器
├── LlmClient.php      # LLM 客户端
├── LlmCallMeta.php    # 中间件 LLM 调用元数据 DTO
├── LlmResponse.php    # 纯 LLM 对话响应 DTO
├── LlmClientFactory.php   # Hyperf DI 工厂（LlmClient）
├── Agentic.php        # Layer 4: 统一门面
├── AgenticFactory.php # Hyperf DI 工厂（Agentic）
└── ConfigProvider.php # Hyperf DI 配置
```
