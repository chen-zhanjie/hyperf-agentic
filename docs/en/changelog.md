# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
