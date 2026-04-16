# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] - 2026-04-17

### 新增

- **`SseWriter`** — 轻量 SSE 传输适配器，位于 `src/Stream/SseWriter.php`。将内部事件转换为 OpenAI 兼容的 SSE 格式。Model 从 `started` 事件自动捕获
- **`reasoning_delta` 事件** — `TurnExecutor` 在流式传输过程中现在会发出 `REASONING_DELTA` 事件，用于推理/思考内容（之前被静默缓冲）
- **`started` 事件现在携带 `model`** — `AgentRunner` 在 `started` 事件 payload 中包含 model 名称，SSE 适配器可自动捕获

### 变更

- `runWithConfig()` 和 `runStreamWithConfig()` 的 `$agentConfig` 参数现在接受 `Agent|array` 类型

### 移除

- **`runStreamSse()`、`runStreamWithConfigSse()`、`chatStreamSse()`** — SSE 格式化由消费者负责。直接使用 `new SseWriter($write)` + `asOnEvent()` 或 `asOnChunk()`
- **`OpenAiSseFormatter`** — 由 `SseWriter` 替代（从 `Stream\Formatter` 命名空间移至 `Stream`）
- **`StreamFormatterInterface`** — 已删除（YAGNI：仅有一个实现，无外部消费者）

## [0.7.0] - 2026-04-16

### 新增

- **真正的 token 流式传输** — `chatStream()` 和 `runStream()` 现在发出真实的 SSE token 分片，而非缓冲完整响应
- **`LlmAdapterInterface`** — LLM 协议适配器契约（`chat()`、`chatStream()`）
- **`Agent` DTO** — Agent 数据类模式（受 OpenAI Agents SDK 启发），支持 `toArray()` 和 `fromArray()`
- **`TurnExecutor`** — 从 `AgentRunner` 提取，统一 4 个重复方法（executeTurn、executeStreamTurn、runGraceTurn、runStreamGraceTurn）为单一参数化的 `execute()`
- **无状态适配器** — `OpenAiAdapter` 和 `AnthropicAdapter` 的流式状态从实例属性移至局部变量

### 修复

- **流式内容丢失** — `TurnExecutor::callLlmStream()` 使用 `$textBuffer` 而非空的 `$response['content']`
- **Anthropic token 用量** — `AnthropicAdapter` 现在解析 `message_start` 获取输入 token 和 `message_delta` 获取输出 token

## [0.6.0] - 2026-04-16

### 新增

- **Anthropic API 协议支持** — 内置 `AnthropicAdapter`，原生支持 Anthropic Messages API（`/v1/messages`）。自动消息格式转换（系统提示、tool_use 块、tool_result 块、thinking 块）
- **OpenAiAdapter** — 提取内置 OpenAI 兼容 HTTP 适配器（`/v1/chat/completions`）
- **LlmAdapter 目录** — 新增 `src/LlmAdapter/` 命名空间用于协议适配器
- **双协议路由** — `LlmClient` 通过 `protocol` 配置键路由（`'openai'` 或 `'anthropic'`），自动选择正确的适配器
- **集成测试框架** — 真实 LLM 集成测试，使用 `@group integration`、`.env.test` 配置、bootstrap 文件
- **Anthropic 集成测试** — 6 个测试：聊天、用量、系统提示、工具调用、Agent 工具调用、双协议一致性验证

### 变更

- `ToolInterface::execute()` 返回类型从 `string|array` 改为 `string`
- `LlmClient::chat()` 返回类型从 `string|array` 改为 `array`
- `Agentic::chat()` 返回类型从 `string|array` 改为 `array`
- `SpanInterface::status()` 返回类型从 `string` 改为 `SpanStatus` 枚举
- `SpanInterface` 新增 `events()` 方法要求
- 可调用属性（`LlmClient`、`GuardrailAuditLogger`、`AuditMiddleware`）统一为 `Closure` 类型
- `ToolDispatcher` 审批响应统一为单一格式（`$approval['values']['choice']`）
- `Span::status()` 直接返回 `SpanStatus` 枚举而非 `string`

### 修复

- **未定义变量 Bug** — `AgentRunner::run()` 中的 `$persona` 未定义；已修复为使用 `$setup['persona']->name`

### 移除

- **AgentConfigManager** — 死代码，注册在 DI 中但从未被注入或使用
- `AgentRunner::normalizeResponse()` — `doChat()` 始终返回 `array` 后不再需要
- `Agentic::tools()` — 与 `availableTools()` 重复
- `Agentic::approveToolForSession()` / `approveAllForSession()` — 已弃用的 BC 兼容方法
- `AsyncGuardrailHandle.php` 和 `TraceExporterInterface.php` 中的冗余同命名空间 import

## [0.5.0] - 2026-04-16

### 新增

- **AgentRunContext** — Per-Request 不可变上下文，替代单例 AgentRunner 上的可变实例属性，修复 Swoole 协程竞态条件
- **ToolGuardrailInterface** — 工具级护栏，提供 `checkToolInput()` 和 `checkToolOutput()` 进行工具边界的输入验证和输出过滤
- **ToolGuardrailRunner** — 顺序运行工具护栏，支持 sanitize（修正参数）和 transform（转换输出）
- **ToolGuardrailResult** — 值对象，静态工厂：`ok()`、`blocked()`、`sanitize()`、`transformOutput()`
- **SchemaValidationToolGuardrail** — 内置护栏，根据声明的 JSON Schema 校验工具参数（必填字段、类型匹配）
- **ToolRiskLevel** — 枚举（LOW / MEDIUM / HIGH / CRITICAL）用于工具风险分级
- **ToolPermissionDecision** — 枚举（ALLOW / DENY / ASK）用于权限决策
- **RiskyToolInterface** — 扩展 ToolInterface，增加 `riskLevel()` 和 `riskDescription()`
- **ToolPermissionPolicyInterface** — 配置驱动的工具权限策略接口
- **ConfigToolPermissionPolicy** — 内置实现，`deny > ask > allow > 默认阈值` 优先级，支持 `fnmatch()` 通配符
- **GuardrailEntry 优先级** — 护栏按优先级排序执行（高优先级先执行）
- **GuardrailAuditLoggerInterface** — 护栏决策审计日志接口
- **GuardrailAuditEntry** — 不可变审计记录，含 `guardrailName`、`phase`、`decision`、`reason`、`durationMs`、`timestamp`
- **GuardrailAuditLogger** — 内置双通道（PSR-3 + callable）审计日志器
- `tool_blocked` / `tool_denied` / `guardrail_decision` 事件类型，用于可观测性
- **CancellationToken 激活** — `runLoop` 现在检查 `$context->isCancelled()` 实现协作取消
- `cancellation_timeout_ms` Agent 配置选项，支持基于超时的自动取消

### 修复

- **Swoole 协程竞态条件** — `$activeGuardrails`、`$agentToolHandlers`、`$humanInputResolver` 作为请求级状态写入单例属性；已用不可变 `AgentRunContext` 替代
- **CancellationToken 死代码** — 之前已创建但从未在循环中检查；现已通过 `AgentRunContext` 激活
- **loadFromConfig() 优先级排序** — 配置项中非零 priority 现在会正确排序
- **4 个 PHP 废弃警告** — 可选参数在必需参数 `$context` 之前声明，已重新排序

### 变更

- `GuardrailRunner::register()` 新增可选 `int $priority` 参数
- `GuardrailRunner::loadFromConfig()` 支持 `priority` 配置键
- `GuardrailRunner` 构造函数接受可选 `GuardrailAuditLoggerInterface`
- `AgentRunner` 构造函数新增必需参数 `ToolGuardrailRunner` 和 `ToolPermissionPolicyInterface`
- `dispatchTool()` 现在发出 `TOOL_BLOCKED` 和 `TOOL_DENIED` 事件
- `ToolGuardrailRunner::checkToolInput()` sanitize 不再短路 — 后续护栏可以看到修正后的参数
- `ConfigToolPermissionPolicy::matches()` 使用 `fnmatch()` 替代正则表达式进行通配符匹配

## [0.4.0] - 2026-04-15

### Added

- **`Agentic::runWithConfig()`** — 数据库驱动 Agent 支持，跳过配置文件查找，直接传入动态配置数组
- **`Agentic::runStreamWithConfig()`** — `runWithConfig()` 的流式版本
- **`SkillInterface`** — 技能接口契约，支持从数据库等非文件系统来源创建技能
- **`MessageStoreInterface`** — 对话持久化接口，支持多轮对话历史加载和追加
- **`MemoryMessageStore`** — 内存实现，用于测试和无状态场景
- `conversation_id` 选项 — `runWithConfig()` 支持 `conversation_id`，自动加载/追加对话历史
- `GuardrailRunner::only()` — Per-Agent 护栏白名单过滤（不可变，并发安全）
- Per-Agent 技能过滤 — 通过 `skills` 配置键限制 Agent 可用的技能
- `SkillTool` 注册到 `ToolRegistryFactory` — LLM 运行时可调用 `skill` 工具加载技能指令
- `AgentRunner` 注入 `SkillRegistry` 依赖，传递技能配置到 `PromptBuilder`

### Fixed

- **PromptBuilder 缓存泄漏** — `AgentRunner::run()` 开头添加 `reset()` 调用，防止单例 PromptBuilder 缓存跨 Agent 泄漏
- 对话持久化仅在 `AgentResult::isComplete()` 时写入，护栏拦截和预算耗尽时不持久化

### Changed

- `Skill` 类属性从 `public readonly` 改为 `private readonly` + getter 方法，实现 `SkillInterface`
- `SkillRegistry` 类型提示从 `Skill` 改为 `SkillInterface`

### Removed

- `ToolRegistry::executeText()` — 零调用者，已删除
- 未使用的 `AgentEventType` import（Agentic.php）

## [0.3.0] - 2026-04-13

### Added

- Free-form persona 支持 — Persona 简化为 `name` + `content`，支持任意格式文本
- 统一默认 Agent 名称机制

## [0.2.0] - 2026-04-12

### Fixed

- 添加 `extra.hyperf.config` 到 `composer.json`，修复 Hyperf 自动发现

## [0.1.0] - 2026-04-11

### Added

- 初始发布
- 5 层架构：Contract → Subsystems → Agent Core → Facade → Entry Points
- Agent 循环（ReAct 模式）
- 工具系统（ToolRegistry、ToolInterface、`#[AsTool]` 注解）
- 技能系统（3 级渐进式披露）
- 护栏系统（GuardrailRunner、GuardrailInterface）
- LLM 客户端（OpenAI 协议兼容）
- 会话管理（SessionStore）
- CLI 命令支持
- 事件系统（EventEmitter）
- 中间件管道（MiddlewarePipeline）
- 链路追踪（TraceExporter）
