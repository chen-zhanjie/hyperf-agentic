# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
