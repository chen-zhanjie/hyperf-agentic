# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-19

### Added

- **Explicit SSL verification** — `OpenAiAdapter` and `AnthropicAdapter` now set `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` explicitly, preventing insecure defaults in misconfigured environments
- **Error logging on chain middleware** — `LlmMiddlewarePipeline::beforeCall()` and `AgentMiddlewarePipeline::beforeLoop()` now log warnings before re-throwing, improving debuggability of middleware failures
- **Coroutine-aware sleep** — `LlmClient` uses `Swoole\Coroutine\System::sleep()` when running inside a coroutine, preventing worker blocking during retry backoff
- **beforeToolCall fault tolerance** — `AgentMiddlewarePipeline::beforeToolCall()` now catches middleware exceptions, logs a warning, and returns `null` (pass-through) instead of crashing the tool dispatch chain

### Fixed

- **API key leakage in error messages** — Error messages from `OpenAiAdapter` and `AnthropicAdapter` now truncate the response body to 200 characters, preventing accidental credential or sensitive data exposure in logs
- **Hardcoded Chinese string** — `ToolDispatcher` tool execution error message changed from Chinese to English for consistency
- **AuditMiddleware success detection** — Fixed matching against the old Chinese error string (`'工具执行错误'`), now matches the updated English `'Tool execution error'` prefix

## [0.9.0] - 2026-04-17

### Added

- **`LlmCallMeta` DTO** — Readonly DTO passed to `LlmMiddlewareInterface::afterCall()` containing `provider`, `model`, `promptTokens`, `completionTokens`, and `totalTokens`
- **`LlmResponse` DTO** — Readonly DTO returned by `Agentic::chat()` and `Agentic::chatStream()` with `content`, `usage`, `model`, `provider`, `reasoningContent`, `toolCalls`, and `toArray()` for backward compat
- **Middleware fault tolerance** — Notification methods (`afterLoop`, `afterCall`, `afterToolCall`) now catch exceptions and continue, instead of breaking the agent loop. Chain methods (`beforeLoop`, `beforeCall`) still throw on failure

### Changed

- **BREAKING:** `MiddlewareInterface` split into `LlmMiddlewareInterface` and `AgentMiddlewareInterface`. Old `MiddlewareInterface` removed
- **BREAKING:** `MiddlewarePipeline` replaced by `LlmMiddlewarePipeline` and `AgentMiddlewarePipeline`
- **BREAKING:** `Agentic::chat()` and `chatStream()` now bypass `AgentRunner` and call `LlmClient` directly
- **BREAKING:** `Agentic::chat()` and `chatStream()` now return `LlmResponse` instead of `array`. Use `$result->content` or `$result->toArray()` for backward compat
- **BREAKING:** `AgentMiddlewareInterface::beforeToolCall()` and `afterToolCall()` now accept `array $runContext = []` for stateless context passing
- `AuditMiddleware` now stateless — receives session/agent context via `$runContext` instead of mutable instance properties
- `TurnExecutor` no longer depends on `AgentMiddlewarePipeline` (moved to `AgentRunner`)
- `AgentRunContext` now carries `agentName` for middleware context
- `AgentRunner` now injects resolved `model` and `provider` into options before passing to `TurnExecutor`
- `LlmClient::chat()` and `chatStream()` return `LlmResponse` DTOs with actual provider/model from API response
- `SseWriter::finish()` PHPDoc improved to clarify it internally calls `done()`

## [0.8.3] - 2026-04-17

### Added

- **`LlmClientFactory`** — Reads `agentic.providers` config and injects provider configs into `LlmClient` via Hyperf DI
- **`AgenticFactory`** — Reads `agentic.agents` and `agentic.agentic` config and injects agent definitions into `Agentic` via Hyperf DI

### Changed

- `ConfigProvider` now maps `LlmClient` and `Agentic` to their factory classes instead of direct class resolution. `vendor:publish` now works out of the box — no manual DI overrides needed

## [0.8.2] - 2026-04-17

### Added

- **`tool_result` named SSE event** — `SseWriter` now emits `tool_result` as a named SSE event (`event: tool_result\ndata: {...}\n\n`), completing the tool_call → tool_result lifecycle in SSE streams. Standard OpenAI chunks are unaffected — named events are automatically ignored by OpenAI SDK parsers

## [0.8.1] - 2026-04-17

### Added

- **Debug CLI** — `debug.php` for interactive agent debugging from the terminal
- **Sync-mode events** — `TurnExecutor` now emits `REASONING_DELTA` and `TEXT_DELTA` events in both sync and stream modes
- **`ask` tool option handling** — Fixed option resolution in `AskTool`

## [0.8.0] - 2026-04-17

### Added

- **`SseWriter`** — Thin SSE transport adapter at `src/Stream/SseWriter.php`. Converts internal events to OpenAI-compatible SSE wire format. Model is auto-captured from the `started` event
- **`reasoning_delta` event** — `TurnExecutor` now emits `REASONING_DELTA` events for reasoning/thinking content in both sync and stream modes (previously stream-only and buffered silently)
- **`text_delta` event in sync mode** — `TurnExecutor` now emits `TEXT_DELTA` events in both sync and stream modes (previously stream-only). Sync mode emits the full content as a single chunk
- **`started` event now carries `model`** — `AgentRunner` includes the model name in the `started` event payload, so SSE adapters can auto-capture it

### Changed

- `runWithConfig()` and `runStreamWithConfig()` now accept `Agent|array` for `$agentConfig` parameter

### Removed

- **`runStreamSse()`, `runStreamWithConfigSse()`, `chatStreamSse()`** — SSE formatting is the consumer's responsibility. Use `new SseWriter($write)` + `asOnEvent()` or `asOnChunk()` directly
- **`OpenAiSseFormatter`** — Replaced by `SseWriter` (moved from `Stream\Formatter` to `Stream` namespace)
- **`StreamFormatterInterface`** — Removed (YAGNI: single implementation, no external consumers)

## [0.7.0] - 2026-04-16

### Added

- **True token streaming** — `chatStream()` and `runStream()` now emit real SSE token chunks instead of buffering full responses
- **`LlmAdapterInterface`** — Adapter contract for LLM protocol implementations (`chat()`, `chatStream()`)
- **`Agent` DTO** — Agent as dataclass pattern (inspired by OpenAI Agents SDK), supports `toArray()` and `fromArray()`
- **`TurnExecutor`** — Extracted from `AgentRunner`, unifies 4 duplicated methods (executeTurn, executeStreamTurn, runGraceTurn, runStreamGraceTurn) into single parameterized `execute()`
- **Stateless adapters** — `OpenAiAdapter` and `AnthropicAdapter` streaming state moved from instance properties to local variables

### Fixed

- **Streaming content loss** — `TurnExecutor::callLlmStream()` uses `$textBuffer` for content instead of empty `$response['content']`
- **Anthropic token usage** — `AnthropicAdapter` now parses `message_start` for input tokens and `message_delta` for output tokens

## [0.6.0] - 2026-04-16

### Added

- **Anthropic API protocol support** — Built-in `AnthropicAdapter` for native Anthropic Messages API (`/v1/messages`). Automatic message conversion (system prompts, tool_use blocks, tool_result blocks, thinking blocks)
- **OpenAiAdapter** — Extracted built-in OpenAI-compatible HTTP adapter (`/v1/chat/completions`)
- **LlmAdapter directory** — New `src/LlmAdapter/` namespace for protocol adapters
- **Dual-protocol routing** — `LlmClient` routes by `protocol` config key (`'openai'` or `'anthropic'`), auto-selects the correct adapter
- **Integration test framework** — Real LLM integration tests with `@group integration`, `.env.test` configuration, bootstrap file
- **Anthropic integration tests** — 6 tests: chat, usage, system prompt, tool calling, agent with tool, both-protocols-same-shape verification

### Changed

- `ToolInterface::execute()` return type changed from `string|array` to `string`
- `LlmClient::chat()` return type changed from `string|array` to `array`
- `Agentic::chat()` return type changed from `string|array` to `array`
- `SpanInterface::status()` return type changed from `string` to `SpanStatus` enum
- `SpanInterface` now requires `events()` method
- Callable properties (`LlmClient`, `GuardrailAuditLogger`, `AuditMiddleware`) normalized to `Closure` with constructor conversion
- `ToolDispatcher` approval response normalized to single format (`$approval['values']['choice']`)
- `Span::status()` returns `SpanStatus` enum directly instead of `string`

### Fixed

- **Undefined variable bug** — `$persona` in `AgentRunner::run()` was not defined; fixed to use `$setup['persona']->name`

### Removed

- **AgentConfigManager** — Dead code, registered in DI but never injected or used
- `AgentRunner::normalizeResponse()` — No longer needed after `doChat()` always returns `array`
- `Agentic::tools()` — Duplicate of `availableTools()`
- `Agentic::approveToolForSession()` / `approveAllForSession()` — Deprecated BC shims
- Redundant same-namespace imports in `AsyncGuardrailHandle.php` and `TraceExporterInterface.php`

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
