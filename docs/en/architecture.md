# Architecture

The SDK uses a 5-layer architecture, building from low-level interfaces to high-level entry points. Layer 3 is split into two sub-layers (LLM and Agent) following the v0.10 refactor.

## Layer Structure

```
Layer 1: Contract (Interface Layer)
    │   ToolInterface, GuardrailInterface, SkillInterface,
    │   MessageStoreInterface, SessionStoreInterface,
    │   ToolGuardrailInterface, ToolPermissionPolicyInterface,
    │   GuardrailAuditLoggerInterface, RiskyToolInterface,
    │   LlmMiddlewareInterface, AgentMiddlewareInterface, ...
    │
Layer 2: Subsystems
    │   ToolRegistry, GuardrailRunner, ToolGuardrailRunner,
    │   SkillRegistry, PromptBuilder,
    │   LlmCallRequest (immutable DTO), LlmResponse (DTO),
    │   ToolDispatcher, TurnExecutor,
    │   LlmAdapter (OpenAiAdapter, AnthropicAdapter),
    │   Stream (SseWriter)
    │
Layer 3a: LLM Layer
    │   LlmClient, LlmMiddlewarePipeline,
    │   LlmCallRequest, LlmResponse, LlmCallMeta
    │
Layer 3b: Agent Core
    │   AgentRunner, Agent (DTO), ToolDispatcher, LoopState,
    │   AgentRunContext, AgentResult, AgentMiddlewarePipeline
    │
Layer 4: Facade
    │   Agentic — unified entry point
    │
Layer 5: Entry Points
        Hyperf Controllers, CLI Commands, HTTP API
```

## Core Concepts

### Config-Driven

An agent is a config array, not a class. Inspired by Hermes design philosophy:

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

### LLM / Agent Layer Separation

Layer 3 is split into two independent sub-layers so that direct LLM calls and full agent loops can be used independently.

**Layer 3a (LLM Layer)** handles raw LLM communication. `LlmClient` sends requests through `LlmMiddlewarePipeline` which exposes four hooks:

| Hook | Purpose |
|------|---------|
| `beforeCall` | Inspect or modify the request before it reaches the adapter |
| `afterCall` | Observe the response (logging, metrics, cost tracking) |
| `onRetry` | Notified when the adapter retries a failed request |
| `onFailover` | Notified when the client switches to a different provider |

The pipeline returns a `LlmResponse` DTO containing `content`, `usage`, `provider`, `model`, `reasoningContent`, `toolCalls`, and `latencyMs`. `Agentic::chat()` and `chatStream()` bypass `AgentRunner` entirely and call `LlmClient` directly.

**Layer 3b (Agent Core)** handles the ReAct loop. `AgentRunner` orchestrates iterations using `TurnExecutor` internally, which in turn delegates to `LlmClient` for each LLM call. `AgentMiddlewarePipeline` exposes four hooks at the agent level:

| Hook | Purpose |
|------|---------|
| `beforeLoop` | Inspect or modify messages before the loop starts |
| `afterLoop` | Inspect or transform the `AgentResult` after the loop ends |
| `beforeToolCall` | Intercept a tool call; return a string to short-circuit execution |
| `afterToolCall` | Observe tool call results (logging, metrics) |

This separation means consumers can use the LLM layer for simple chat without pulling in the agent loop, guardrails, or tool dispatch machinery.

**`LlmCallRequest`** is an immutable DTO passed through the LLM middleware chain. It carries `messages`, `options`, `provider`, and `model` as readonly properties. A `with(array $overrides): self` method produces a new instance with selective field overrides, following the immutable pattern used throughout the SDK.

### Agent Loop

AgentRunner implements the standard ReAct (Reasoning + Acting) loop:

```
┌──────────────────────────────────┐
│  Build system prompt (PromptBuilder) │
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│  Check input guardrails           │ ◄── Blocked → Return GuardrailBlocked
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│         Agent Loop                │
│  ┌────────────────────────────┐  │
│  │ Call LLM                   │  │
│  └──────────────┬─────────────┘  │
│                 │                  │
│                 ▼                  │
│  ┌────────────────────────────┐  │
│  │ Check output guardrails    │  │ ◄── Blocked → Exit loop
│  └──────────────┬─────────────┘  │
│                 │                  │
│                 ▼                  │
│  ┌────────────────────────────┐  │
│  │ LLM returns tool call?     │  │
│  │  Yes → Execute tool → Continue │
│  │  No  → Exit loop            │  │
│  └────────────────────────────┘  │
│                                   │
│  Iteration limit or budget? → BudgetExhausted │
│  CancellationToken cancelled? → Exit loop │
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│  Return AgentResult              │
└──────────────────────────────────┘
```

### Tool Dispatch Chain

`ToolDispatcher` owns the tool dispatch chain, injected into `AgentRunner`:

```
1. Tool Guardrail (input check)      → can block or sanitize arguments
2. Approval Store bypass             → pre-approved tools skip policy check
3. Permission Policy (deny/ask/allow) → can deny or require user confirmation
4. Human Approval (if ASK)           → ONCE / TOOL / SESSION / DENY
5. AgentMiddleware (beforeToolCall)  → can intercept
6. Agent-level handler               → or ToolRegistry::execute()
7. Tool Guardrail (output check)     → can block or transform output
8. AgentMiddleware (afterToolCall)
```

Approval prompts are customizable via `Support\ApprovalPrompts` — override static properties for i18n.

### AgentRunContext (Per-Request Context)

`AgentRunContext` is an immutable value object created per request, holding all per-request state:

- Active guardrails (filtered per agent)
- Tool guardrails
- Permission policy
- Approval store (cloned per-request for isolation)
- Human input resolver
- Agent-level tool handlers
- Cancellation token
- Session ID

This replaces mutable instance properties on the singleton `AgentRunner`, eliminating race conditions under Swoole coroutines.

### PromptBuilder (7-Layer Prompt Construction)

The system prompt consists of 7 layers, split into **cached** and **ephemeral**:

**Cached layers** (unchanged within the same agent, built once):

| Layer | Content |
|-------|---------|
| L1 | Agent identity (Persona) |
| L2 | Scene rules (HTTP / CLI) |
| L3 | Tool descriptions (JSON Schema) |
| L4 | Skill index (Level 1 descriptions) |
| L5 | Memory snapshot |

**Ephemeral layers** (may differ per request):

| Layer | Content |
|-------|---------|
| L6 | Runtime context |
| L7 | Budget status (Iteration / Cost Budget) |

### Immutable Filtering

`ToolRegistry::only()` and `GuardrailRunner::only()` use an immutable pattern:

```php
// Returns a new instance, does not modify the original
$filteredRegistry = $registry->only(['search', 'ask']);
```

This ensures concurrency safety in Hyperf's coroutine model — per-agent filtering across different requests doesn't interfere.

## DI Bindings

`ConfigProvider` registers all services across four layers:

```php
// Layer 1: Foundation interfaces → implementations
Contract\ContextEngineInterface::class => NullContextEngine::class,
Contract\MemoryProviderInterface::class => NullMemoryProvider::class,
Contract\MessageStoreInterface::class => Session\MemoryMessageStore::class,
Contract\TraceExporterInterface::class => Tracing\LogTraceExporter::class,
Contract\ToolPermissionPolicyInterface::class => Policy\ConfigToolPermissionPolicy::class,
Contract\GuardrailAuditLoggerInterface::class => GuardrailAuditLogger::class,
Contract\PermissionApprovalStoreInterface::class => PermissionApprovalStore::class,

// Layer 2: Subsystems
Persona\PersonaLoader::class => Persona\PersonaLoader::class,
Loader\AnnotationToolLoader::class => Loader\AnnotationToolLoader::class,
Loader\ConfigToolLoader::class => Loader\ConfigToolLoader::class,
Loader\SkillLoader::class => Loader\SkillLoader::class,
Skill\SkillRegistry::class => SkillRegistryFactory::class,
ToolRegistry::class => ToolRegistryFactory::class,

// Layer 3a: LLM Layer
LlmMiddlewarePipeline::class => LlmMiddlewarePipeline::class,
LlmClient::class => LlmClientFactory::class,

// Layer 3b: Agent Core
PromptBuilder::class => PromptBuilder::class,
GuardrailRunner::class => GuardrailRunner::class,
ToolGuardrailRunner::class => ToolGuardrailRunner::class,
AgentMiddlewarePipeline::class => AgentMiddlewarePipeline::class,
ToolDispatcher::class => ToolDispatcher::class,
AgentRunner::class => AgentRunner::class,

// Layer 4: Facade
Agentic::class => AgenticFactory::class,
```

## Concurrency Safety

Hyperf uses a coroutine model. The SDK's concurrency safety guarantees:

1. **AgentRunContext**: Per-request immutable context replaces mutable instance properties, eliminating race conditions between concurrent requests sharing the singleton `AgentRunner`.
2. **PromptBuilder reset**: `AgentRunner::run()` calls `reset()` at the start, clearing the previous build's cache. No I/O yield between `reset()` + `build()`, making it atomic within a single coroutine.
3. **Immutable filtering**: `only()` returns new instances, not affecting global singletons.
4. **Local variables**: `$systemMessage`, `$messages`, etc. are method-local variables, naturally isolated.
5. **SessionStore**: Each request uses a different `conversation_id` / `sessionId`, with Redis providing natural isolation.

## Directory Structure

```
src/
├── Contract/          # Layer 1: Interface definitions
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
│   ├── LlmMiddlewareInterface.php
│   ├── AgentMiddlewareInterface.php
│   └── ...
├── Tool/              # Tool system
│   └── Builtin/       # Built-in tools (AskTool, SkillTool)
├── Skill/             # Skill system
│   ├── Skill.php
│   └── SkillRegistry.php
├── Guardrail/         # Guardrails
│   └── SchemaValidationToolGuardrail.php
├── Policy/            # Permission policies
│   └── ConfigToolPermissionPolicy.php
├── Session/           # Session storage
├── Resolver/          # Human input resolvers (Cli, Http, Null)
├── Persona/           # Personas (Persona, PersonaLoader)
├── Loader/            # Loaders (Annotation, Config, Skill)
├── Event/             # Event system
├── Tracing/           # Distributed tracing
├── Middleware/        # Built-in middleware implementations
│   └── AuditMiddleware.php
├── Support/           # Support utilities
│   ├── ApprovalPrompts.php    # Customizable approval prompt templates
│   ├── ConfigLoader.php
│   ├── DefaultPrompts.php
│   └── TokenEstimator.php
├── Attributes/        # PHP 8 Attributes (#[AsTool], etc.)
├── LlmAdapter/        # LLM protocol adapters
│   ├── OpenAiAdapter.php    # OpenAI /v1/chat/completions
│   └── AnthropicAdapter.php # Anthropic /v1/messages
├── Stream/            # Streaming transport adapters
│   └── SseWriter.php        # OpenAI-compatible SSE writer
├── AgentRunner.php    # Layer 3b: Agent core
├── TurnExecutor.php   # Layer 3b: Single turn execution (unified sync/stream)
├── Agent.php          # Agent DTO (config as data)
├── ToolDispatcher.php # Layer 3b: Tool dispatch chain (guardrails → permissions → execution)
├── LoopState.php      # Per-request mutable loop accumulator
├── AgentRunContext.php # Per-request immutable context
├── AgentResult.php    # Agent execution result
├── AgentMiddlewarePipeline.php # Layer 3b: Agent middleware pipeline
├── ApprovalChoice.php # User approval choice enum (ONCE/TOOL/SESSION/DENY)
├── PermissionMode.php # Permission mode enum (DEFAULT/AUTO/STRICT/READONLY)
├── PermissionApprovalStore.php # In-memory approval store (wildcard + dual-scope)
├── PromptBuilder.php  # Layer 3b: Prompt builder
├── ToolRegistry.php   # Tool registry
├── ToolGuardrailRunner.php  # Tool-level guardrail runner
├── ToolGuardrailResult.php  # Tool guardrail result value object
├── ToolRiskLevel.php  # Tool risk level enum
├── ToolPermissionDecision.php # Permission decision enum
├── GuardrailRunner.php # Guardrail runner (with priority + audit)
├── GuardrailAuditEntry.php  # Audit log entry
├── GuardrailAuditLogger.php # Default audit logger
├── LlmClient.php      # Layer 3a: LLM client
├── LlmCallRequest.php # Layer 3a: Immutable LLM call request DTO
├── LlmResponse.php    # Layer 3a: Pure LLM chat response DTO
├── LlmCallMeta.php    # Layer 3a: LLM call metadata DTO
├── LlmMiddlewarePipeline.php # Layer 3a: LLM middleware pipeline
├── LlmClientFactory.php   # Hyperf DI factory for LlmClient
├── Agentic.php        # Layer 4: Unified facade
├── AgenticFactory.php # Hyperf DI factory for Agentic
└── ConfigProvider.php # Hyperf DI config
```
