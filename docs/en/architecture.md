# Architecture

The SDK uses a 5-layer architecture, building from low-level interfaces to high-level entry points.

## Layer Structure

```
Layer 1: Contract (Interface Layer)
    │   ToolInterface, GuardrailInterface, SkillInterface,
    │   MessageStoreInterface, SessionStoreInterface, ...
    │
Layer 2: Subsystems
    │   ToolRegistry, GuardrailRunner, SkillRegistry,
    │   PromptBuilder, LlmClient, MiddlewarePipeline
    │
Layer 3: Agent Core
    │   AgentRunner, AgentResult, AgentConfigManager
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
│  Iteration limit reached? → BudgetExhausted │
└────────────┬─────────────────────┘
             │
             ▼
┌──────────────────────────────────┐
│  Return AgentResult              │
└──────────────────────────────────┘
```

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

// Factory (__invoke produces the instance)
Skill\SkillRegistry::class => SkillRegistryFactory::class,
ToolRegistry::class => ToolRegistryFactory::class,

// Self-registration (constructor injects dependencies)
AgentRunner::class => AgentRunner::class,
Agentic::class => Agentic::class,
```

## Concurrency Safety

Hyperf uses a coroutine model. The SDK's concurrency safety guarantees:

1. **PromptBuilder reset**: `AgentRunner::run()` calls `reset()` at the start, clearing the previous build's cache. No I/O yield between `reset()` + `build()`, making it atomic within a single coroutine.
2. **Immutable filtering**: `only()` returns new instances, not affecting global singletons.
3. **Local variables**: `$systemMessage`, `$messages`, etc. are method-local variables, naturally isolated.
4. **SessionStore**: Each request uses a different `conversation_id` / `sessionId`, with Redis providing natural isolation.

## Directory Structure

```
src/
├── Contract/          # Layer 1: Interface definitions
│   ├── ToolInterface.php
│   ├── GuardrailInterface.php
│   ├── SkillInterface.php
│   ├── MessageStoreInterface.php
│   ├── SessionStoreInterface.php
│   └── ...
├── Tool/              # Tool system
│   └── Builtin/       # Built-in tools (AskTool, SkillTool)
├── Skill/             # Skill system
│   ├── Skill.php
│   └── SkillRegistry.php
├── Guardrail/         # Guardrails (GuardrailResult, etc.)
├── Session/           # Session storage
├── Persona/           # Personas (Persona, PersonaLoader)
├── Loader/            # Loaders (Annotation, Config, Skill)
├── Event/             # Event system
├── Tracing/           # Distributed tracing
├── Attributes/        # PHP 8 Attributes (#[AsTool], etc.)
├── AgentRunner.php    # Layer 3: Agent core
├── AgentResult.php    # Agent execution result
├── PromptBuilder.php  # Prompt builder
├── ToolRegistry.php   # Tool registry
├── GuardrailRunner.php # Guardrail runner
├── LlmClient.php      # LLM client
├── Agentic.php        # Layer 4: Unified facade
└── ConfigProvider.php # Hyperf DI config
```
