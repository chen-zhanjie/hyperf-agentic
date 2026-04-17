# Architecture

The SDK uses a 5-layer architecture, building from low-level interfaces to high-level entry points.

## Layer Structure

```
Layer 1: Contract (Interface Layer)
    │   ToolInterface, GuardrailInterface, SkillInterface,
    │   MessageStoreInterface, SessionStoreInterface,
    │   ToolGuardrailInterface, ToolPermissionPolicyInterface,
    │   GuardrailAuditLoggerInterface, RiskyToolInterface, ...
    │
Layer 2: Subsystems
    │   ToolRegistry, GuardrailRunner, ToolGuardrailRunner,
    │   SkillRegistry, PromptBuilder, LlmClient, MiddlewarePipeline,
    │   ToolDispatcher, TurnExecutor,
    │   LlmAdapter (OpenAiAdapter, AnthropicAdapter),
    │   Stream (SseWriter)
    │
Layer 3: Agent Core
    │   AgentRunner, Agent (DTO), ToolDispatcher, LoopState,
    │   AgentRunContext, AgentResult
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
5. Middleware (beforeToolCall)        → can intercept
6. Agent-level handler               → or ToolRegistry::execute()
7. Tool Guardrail (output check)     → can block or transform output
8. Middleware (afterToolCall)
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

`ConfigProvider` registers all services:

```php
// Interface → Implementation
Contract\MessageStoreInterface::class => Session\MemoryMessageStore::class,
Contract\ToolPermissionPolicyInterface::class => Policy\ConfigToolPermissionPolicy::class,
Contract\GuardrailAuditLoggerInterface::class => GuardrailAuditLogger::class,
Contract\PermissionApprovalStoreInterface::class => PermissionApprovalStore::class,

// Factory (__invoke produces the instance)
Skill\SkillRegistry::class => SkillRegistryFactory::class,
ToolRegistry::class => ToolRegistryFactory::class,

// Self-registration (constructor injects dependencies)
ToolDispatcher::class => ToolDispatcher::class,
AgentRunner::class => AgentRunner::class,
Agentic::class => Agentic::class,
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
├── AgentRunner.php    # Layer 3: Agent core
├── TurnExecutor.php   # Layer 3: Single turn execution (unified sync/stream)
├── Agent.php          # Agent DTO (config as data)
├── ToolDispatcher.php # Layer 3: Tool dispatch chain (guardrails → permissions → execution)
├── LoopState.php      # Per-request mutable loop accumulator
├── AgentRunContext.php # Per-request immutable context
├── AgentResult.php    # Agent execution result
├── ApprovalChoice.php # User approval choice enum (ONCE/TOOL/SESSION/DENY)
├── PermissionMode.php # Permission mode enum (DEFAULT/AUTO/STRICT/READONLY)
├── PermissionApprovalStore.php # In-memory approval store (wildcard + dual-scope)
├── PromptBuilder.php  # Prompt builder
├── ToolRegistry.php   # Tool registry
├── ToolGuardrailRunner.php  # Tool-level guardrail runner
├── ToolGuardrailResult.php  # Tool guardrail result value object
├── ToolRiskLevel.php  # Tool risk level enum
├── ToolPermissionDecision.php # Permission decision enum
├── GuardrailRunner.php # Guardrail runner (with priority + audit)
├── GuardrailAuditEntry.php  # Audit log entry
├── GuardrailAuditLogger.php # Default audit logger
├── LlmClient.php      # LLM client
├── LlmCallMeta.php    # Middleware LLM call metadata DTO
├── LlmResponse.php    # Pure LLM chat response DTO
├── LlmClientFactory.php   # Hyperf DI factory for LlmClient
├── Agentic.php        # Layer 4: Unified facade
├── AgenticFactory.php # Hyperf DI factory for Agentic
└── ConfigProvider.php # Hyperf DI config
```
