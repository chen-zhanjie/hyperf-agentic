# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0] - 2026-04-16

### Added

- **AgentRunContext** — Per-request immutable context, replaces mutable instance properties on singleton AgentRunner, fixes Swoole coroutine race condition
- **ToolGuardrailInterface** — Tool-level guardrails with `checkToolInput()` and `checkToolOutput()` for input validation and output filtering at tool boundaries
- **ToolGuardrailRunner** — Runs tool guardrails in sequence, supports sanitize (modify arguments) and transform (modify output)
- **ToolGuardrailResult** — Value object with static factories: `ok()`, `blocked()`, `sanitize()`, `transformOutput()`
- **SchemaValidationToolGuardrail** — Built-in guardrail validating tool arguments against declared JSON Schema (required fields, type matching)
- **ToolRiskLevel** — Enum (LOW / MEDIUM / HIGH / CRITICAL) for tool risk classification
- **ToolPermissionDecision** — Enum (ALLOW / DENY / ASK) for permission decisions
- **RiskyToolInterface** — Extends ToolInterface with `riskLevel()` and `riskDescription()`
- **ToolPermissionPolicyInterface** — Config-driven tool permission policy interface
- **ConfigToolPermissionPolicy** — Built-in implementation with `deny > ask > allow > default threshold` priority and `fnmatch()` wildcard patterns
- **GuardrailEntry priority** — Guardrails execute in priority order (highest first)
- **GuardrailAuditLoggerInterface** — Audit logging interface for guardrail decisions
- **GuardrailAuditEntry** — Immutable audit record with `guardrailName`, `phase`, `decision`, `reason`, `durationMs`, `timestamp`
- **GuardrailAuditLogger** — Built-in dual-channel (PSR-3 + callable) audit logger
- `tool_blocked` / `tool_denied` / `guardrail_decision` event types for observability
- **CancellationToken activation** — `runLoop` now checks `$context->isCancelled()` for cooperative cancellation
- `cancellation_timeout_ms` agent config option for automatic timeout-based cancellation

### Fixed

- **Swoole coroutine race condition** — `$activeGuardrails`, `$agentToolHandlers`, `$humanInputResolver` were per-request state written to singleton properties; replaced by immutable `AgentRunContext`
- **CancellationToken dead code** — Was created but never checked in the loop; now active via `AgentRunContext`
- **loadFromConfig() priority sorting** — Config entries with non-zero priority are now properly sorted
- **4 PHP deprecation warnings** — Optional parameters declared before required `$context` parameter reordered

### Changed

- `GuardrailRunner::register()` now accepts optional `int $priority` parameter
- `GuardrailRunner::loadFromConfig()` supports `priority` key in config arrays
- `GuardrailRunner` constructor accepts optional `GuardrailAuditLoggerInterface`
- `AgentRunner` constructor now requires `ToolGuardrailRunner` and `ToolPermissionPolicyInterface`
- `dispatchTool()` now emits `TOOL_BLOCKED` and `TOOL_DENIED` events
- `ToolGuardrailRunner::checkToolInput()` no longer short-circuits on sanitize — subsequent guardrails see modified arguments
- `ConfigToolPermissionPolicy::matches()` uses `fnmatch()` instead of regex for wildcard patterns

## [0.4.0] - 2026-04-15

### Added

- **`Agentic::runWithConfig()`** — Database-driven agent support, bypasses config file lookup with dynamic config arrays
- **`Agentic::runStreamWithConfig()`** — Streaming variant of `runWithConfig()`
- **`SkillInterface`** — Skill interface contract, supports creating skills from non-filesystem sources (e.g., databases)
- **`MessageStoreInterface`** — Conversation persistence interface, supports multi-turn history loading and appending
- **`MemoryMessageStore`** — In-memory implementation for testing and stateless usage
- `conversation_id` option — `runWithConfig()` supports `conversation_id` for automatic history loading/appending
- `GuardrailRunner::only()` — Per-agent guardrail whitelist filtering (immutable, concurrency-safe)
- Per-agent skill filtering — via `skills` config key to limit available skills per agent
- `SkillTool` registered in `ToolRegistryFactory` — LLMs can now invoke the `skill` tool at runtime
- `AgentRunner` injects `SkillRegistry` dependency, passing skill config to `PromptBuilder`

### Fixed

- **PromptBuilder cache leak** — `AgentRunner::run()` now calls `reset()` at the start to prevent singleton PromptBuilder cache from leaking across agents
- Conversation persistence only writes when `AgentResult::isComplete()` is true — guardrail-blocked and budget-exhausted results are not persisted

### Changed

- `Skill` class properties changed from `public readonly` to `private readonly` + getter methods, implementing `SkillInterface`
- `SkillRegistry` type hints changed from `Skill` to `SkillInterface`

### Removed

- `ToolRegistry::executeText()` — Zero callers, removed
- Unused `AgentEventType` import (Agentic.php)

## [0.3.0] - 2026-04-13

### Added

- Free-form persona support — Persona simplified to `name` + `content`, supporting arbitrary text formats
- Unified default agent name mechanism

## [0.2.0] - 2026-04-12

### Fixed

- Added `extra.hyperf.config` to `composer.json` for Hyperf auto-discovery

## [0.1.0] - 2026-04-11

### Added

- Initial release
- 5-layer architecture: Contract → Subsystems → Agent Core → Facade → Entry Points
- Agent loop (ReAct pattern)
- Tool system (ToolRegistry, ToolInterface, `#[AsTool]` annotation)
- Skill system (3-level progressive disclosure)
- Guardrail system (GuardrailRunner, GuardrailInterface)
- LLM client (OpenAI protocol compatible)
- Session management (SessionStore)
- CLI command support
- Event system (EventEmitter)
- Middleware pipeline (MiddlewarePipeline)
- Distributed tracing (TraceExporter)
