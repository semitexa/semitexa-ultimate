# Semitexa Framework - AI Assistant Entry Point

> **AI agents:** Read `AGENTS.md` first. It contains the task-first startup rule, code generation workflow, and debugging commands. This file is the deeper project reference.
> **Guiding principle:** Make it work → Make it right → Make it fast.

## Foundational context (stack versions)

Use these versions so you don't assume outdated syntax or APIs:

- **PHP:** ^8.4 (see `composer.json`)
- **semitexa/core:** dev-main or v1.x (path packages: `packages/semitexa-core` or `vendor/semitexa/core`)
- **semitexa/docs:** optional package-level docs when installed in `vendor/semitexa/docs/`
- **Key dependencies:** Symfony 7.x (console, process, etc.), Twig ^3.10, PSR Container (Semitexa custom DI: AsServiceContract, InjectAsReadonly/Mutable/Factory)

Use the constraints declared in `composer.json`. Do not assume Laravel, Illuminate, or Kernel-style middleware — Semitexa has its own module and route discovery.

## Rules and guards

- **Do not** add root-level directories or change module discovery without explicit user approval.
- **Do not** add Composer dependencies without explicit user approval.
- **Do not** create documentation files (README, guides, extra `.md` in the project) unless the user explicitly asks for them.
- Treat **`docs/`** as the canonical monorepo and workspace documentation.
- Treat **`packages/<package>/docs/`** as the canonical package-level technical reference.
- Treat **`var/docs/`** as scratch space for drafts, research notes, and temporary AI working files.

## Read before you change (mandatory)

| Before you… | Read first |
|-------------|------------|
| Understand **why** Semitexa (philosophy, goals, pain) | **README.md**, then **docs/AI_CONTEXT.md** for this project's AI-facing constraints. |
| Understand the **project docs map** | **AI_ENTRY.md**, **README.md**, and **docs/AI_CONTEXT.md** |
| Create or change **module structure** (folders, Application/…) | Review an existing module under **src/modules/** first; if installed, also consult **vendor/semitexa/core/docs/MODULE_STRUCTURE.md** and **vendor/semitexa/core/docs/ADDING_ROUTES.md** |
| Change **service contracts** or DI bindings | Run `bin/semitexa contracts:list --json` to inspect current bindings; if installed, read **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md** |
| Add **new pages or routes** | Review an existing module under **src/modules/** and use `bin/semitexa ai:capabilities --json`; if installed, consult **vendor/semitexa/core/docs/ADDING_ROUTES.md** |

## Before you generate code (checklist)

- **Payloads:** after adding or changing Payload classes (or `#[AsPayloadPart]` traits), do **not** treat `registry:sync` as a required manual step. Use registry commands only for maintenance/debug flows documented by the framework.
- **Module autoload:** do not add per-module PSR-4 entries to project root `composer.json`; the framework autoloads from `src/modules/` at runtime via IntelligentAutoloader.
- **Follow-up hints:** after generator execution, prefer machine-readable follow-up output such as `--llm-hints` to continue only the unresolved domain logic.
- **Dry-run:** prefer `--dry-run` if overwrite risk exists.

## Project structure (standalone app)

- **bin/semitexa** – CLI
- **public/** – web root
- **src/** – application code; **new routes** go in **modules** (src/modules/), not in src/Request or src/Handler (App\ is not discovered for routes).
- **src/modules/** – application modules (where to add new pages and endpoints). **Do not add per-module PSR-4 entries to composer.json** – the framework autoloads all modules from src/modules/ via IntelligentAutoloader at runtime.
- **docs/** – canonical project docs for humans and AI: onboarding, architecture, conventions, decisions.
- **var/log**, **var/cache** – runtime
- **var/docs/** – working directory for notes, plans, drafts, and research. Do not treat it as canonical documentation.
- **AI_NOTES.md** – your own notes for AI (created once, never overwritten by the framework).
- **vendor/semitexa/** – framework packages

## Documentation map

- **README.md** – project overview and quick-start entry point.
- **docs/DOCUMENTATION_OWNERSHIP.md** – where documentation belongs and which layer owns it.
- **docs/AI_CONTEXT.md** – short project-specific AI context.
- **vendor/semitexa/docs/README.md** and **vendor/semitexa/docs/AI_REFERENCE.md** – framework/product philosophy when the docs package is installed.
- **vendor/semitexa/core/docs/** – framework reference when the core package ships docs in this checkout.

## Framework docs (package reference)

- **vendor/semitexa/docs/README.md** and **vendor/semitexa/docs/AI_REFERENCE.md** – philosophy and goals when `semitexa/docs` is installed.
- **vendor/semitexa/core/docs/ADDING_ROUTES.md** – how to add new pages/routes (modules only)
- **vendor/semitexa/core/docs/RUNNING.md** – how to run the app (Docker)
- **vendor/semitexa/core/docs/attributes/** – Request, Handler, Response attributes
- **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md** – service contracts, active implementation, and **contracts:list** command
- **vendor/semitexa/docs/README.md** – package map; **vendor/semitexa/docs/guides/CONVENTIONS.md** – conventions (when `semitexa/docs` is installed)

## Machine-readable commands (for AI agents and scripts)

See `AGENTS.md` for the core debugging commands table. Below are additional commands with detailed output descriptions:

| Command | Output | Use when |
|---------|--------|----------|
| `bin/semitexa ai:task "<description>"` | Task classification entry point for the pull-based AI workflow | Use first when available. Lets the task decide which context to fetch next. |
| `bin/semitexa ai:review-graph:generate --json` | JSON: graph build result with node/edge deltas | Run only when graph-backed answers are required and the graph may be stale or missing. |
| `bin/semitexa ai:review-graph:stats --json` | JSON: node/edge counts by type, module breakdown | Use after an explicit graph refresh or before relying on graph output for risky work. |
| `bin/semitexa ai:review-graph:context "<task>" --format=json` | JSON: matched components, flows, events, dependencies, hotspots | Use when you need task-scoped graph context, not as mandatory startup ritual. |
| `bin/semitexa ai:review-graph:event-trace <Event> --format=json` | JSON: full event lifecycle (emitters, listeners, NATS, replay, DLQ) | Understanding event-driven flows, debugging event propagation. |
| `bin/semitexa ai:review-graph:flow-trace <Flow> --format=json` | JSON: execution flow with ordered steps, storage touches, external calls | Understanding how a request flows through the system. |
| `bin/semitexa ai:review-graph:impact <Component> --format=json` | JSON: dependents, cross-module impact, blast radius, risk score | Before making changes to shared services, handlers, or events. |
| `bin/semitexa ai:capabilities --json` | JSON: machine-readable command catalog with `use_when`, `avoid_when`, inputs, outputs, and follow-up support | Run when the task may match a built-in generator or other AI-relevant command. Prefer this before writing boilerplate manually, but not as universal startup cost. |
| `bin/semitexa registry:sync` | Runs available registry maintenance tasks | Maintenance/debug command. Do not treat it as a required manual step after ordinary payload changes unless a specific package doc tells you to. |

## Recommended AI workflow

**Step 1 — Start from the task:**
See `AGENTS.md` for the Project Understanding Workflow. Additionally:
- Use `event-trace` or `flow-trace` if your task involves events or request flows.
- Use `impact` before making changes to understand blast radius.
- Refresh the graph only when those graph-backed commands are actually needed.

**Step 2 — Generate code (if applicable):**
See `AGENTS.md` for the Code Generation section. Additionally:
- Prefer `--dry-run` if overwrite risk exists.
- After generation, use `--llm-hints` when available to continue only the remaining domain-specific implementation.

Do not default to manual scaffolding when the framework can generate the deterministic structure safely.

## Quick start

1. Read `AGENTS.md` for the task-first startup protocol.
2. For new routes: inspect an existing module in `src/modules/` first, then read `vendor/semitexa/core/docs/ADDING_ROUTES.md` if that reference exists in the installed vendor tree.
3. Run (Docker):

```bash
$EDITOR .env
```

`SSR_DEFERRED_PERSISTENT_SSE=false` is the default. Keep it that way for public pages unless you intentionally need long-lived live updates.

```bash
bin/semitexa server:start
```

4. Default URL: use the `SWOOLE_PORT` from your local `.env` (or the framework default if unset). If available in your installed dependencies, see `vendor/semitexa/core/docs/RUNNING.md` for the framework-level runtime notes.
