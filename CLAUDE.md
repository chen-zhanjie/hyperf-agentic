# Hyperf Agentic SDK

> Hyperf-native AI Agent SDK — config-driven, Hermes-inspired

## Quick Start

```bash
composer install
vendor/bin/phpunit          # Run all tests (252 tests, 468 assertions)
```

## Architecture

5-layer vertical architecture, strict one-way dependency:

```
Layer 5: Entry Points (Controller / Command / CLI)
Layer 4: Agentic Facade (Agentic.php — unified entry, config-driven)
Layer 3: Agent Core (AgentRunner + Guardrail + Middleware)
Layer 2: Subsystems (ToolRegistry / PromptBuilder / LlmClient / PersonaLoader)
Layer 1: Foundation (Contract/ — interfaces, zero upstream deps)
```

## Namespace

`ChenZhanjie\Agentic\` — all source code under `src/`

## Project Structure

```
src/
├── Contract/          # Layer 1: interfaces (zero deps)
├── Attribute/         # #[AsAgent] #[AsTool] annotations
├── Loader/            # ConfigToolLoader, SkillLoader
├── Persona/           # Persona value object + PersonaLoader
├── Skill/             # Skill value object + SkillRegistry (3-level disclosure)
├── Resolver/          # HumanInputResolver implementations (Null, Cli, Http)
├── Session/           # SessionStore implementations (Memory, Redis)
├── Command/           # Hyperf Console commands (AgentChatCommand)
├── Middleware/         # Built-in middleware (AuditMiddleware)
├── Support/           # ConfigLoader, DefaultPrompts, TokenEstimator
├── Event/             # AgentEventType enum + EventEmitter trait
├── Exception/         # AgentSuspendedException
├── ToolRegistry.php   # Zero-dep tool registry
├── PromptBuilder.php  # 7-layer prompt builder (cached + ephemeral)
├── LlmClient.php      # Multi-provider + failover + retry
├── AgentRunner.php    # Conversation loop + tool dispatch + Grace + CostBudget + resume
├── GuardrailRunner.php # Input/output guard execution
├── MiddlewarePipeline.php # 6-hook middleware pipeline
├── FrontendToolValidator.php # Security validation for frontend tools
├── Agentic.php        # Layer 4: unified entry facade
├── Tool/Builtin/      # Built-in tools (AskTool, SkillTool)
├── IterationBudget.php
├── CostBudget.php
├── AgentResult.php
└── ...
```

## Implementation Progress

See [plan](.claude/plans/agent-sdk-refactor.md) for full design.

### Phase 1: Foundation Layer — COMPLETE
- All contracts (ToolInterface, SessionStore, ContextEngine, MemoryProvider, Guardrail, Middleware, etc.)
- ToolRegistry (zero-dep) + ToolEntry + ToolExecutionResult
- Persona (SOUL.md parsing) + PersonaLoader
- Skill (YAML frontmatter) + SkillRegistry (3-level progressive disclosure)
- IterationBudget + CostBudget
- AgentResult + GuardrailResult + AgentSuspendedException
- EventEmitter trait + AgentEventType enum
- NullContextEngine + NullMemoryProvider
- Support classes (ConfigLoader, DefaultPrompts, TokenEstimator)
- Attribute classes (AsAgent, AsTool)
- Loader classes (ConfigToolLoader, SkillLoader)
- Resources (base.md, tool_boundary.md, default.md)
- Publish config templates
- **140 tests passing**

### Phase 2: Subsystems — COMPLETE
- PromptBuilder (7-layer: 5 cached + 2 ephemeral)
  - Layer 1: Persona, Layer 2: SDK base prompt, Layer 3: Agent system_prompt
  - Layer 4: Tool boundary, Layer 5: Scene + skills + memory
  - Layer 6: Runtime context, Layer 7: Budget warning / Grace
- LlmClient (multi-provider, failover, exponential backoff retry)
- **18 PromptBuilder tests + 13 LlmClient tests passing**

### Phase 3: Agent Core — COMPLETE
- AgentRunner (conversation loop + tool dispatch chain + Guardrail + Grace turn + CostBudget + resume)
- GuardrailRunner (input/output guard execution)
- MiddlewarePipeline (6-hook middleware)
- Built-in tools (AskTool, SkillTool)
- FrontendToolValidator (security validation for frontend tools)
- NullHumanInputResolver (unattended mode)
- MemorySessionStore (testing)
- AuditMiddleware (PII redaction)
- **252 tests, 468 assertions**

### Phase 4: Agentic Facade — COMPLETE
- Agentic.php (unified entry point — run, chat, agents, tools, persona, has, setHumanInputResolver)

### Phase 5: Entry Points — COMPLETE
- CliHumanInputResolver (SymfonyStyle blocking prompts: confirm, select, multiselect, text)
- HttpHumanInputResolver (non-blocking: stores pending_ask + throws AgentSuspendedException)
- RedisSessionStore (production: SCAN-based TTL, atomic GETDEL, safe unserialize)
- AgentChatCommand (Symfony Console: interactive loop, --no-input, --model, /quit)
- **289 tests, 526 assertions**

### Remaining: Project Integration — PENDING
- Deploy config/autoload/agentic/ configuration directory
- Migrate project-specific controllers and tools to use SDK
- Streaming support (runStream, chatStream)
