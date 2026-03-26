# Semitexa Framework - AI Assistant Entry Point

> **Guiding principle:** Make it work → Make it right → Make it fast.

## Foundational context (stack versions)

Use these versions so you don't assume outdated syntax or APIs:

- **PHP:** ^8.4 (see `composer.json` / `composer.lock`)
- **semitexa/core:** dev-main or v1.x (path packages: `packages/semitexa-core` or `vendor/semitexa/core`)
- **semitexa/docs:** ^1.0 (AI_REFERENCE, guides)
- **Key dependencies:** Symfony 7.x (console, process, etc.), Twig ^3.10, PSR Container (Semitexa custom DI: AsServiceContract, InjectAsReadonly/Mutable/Factory — see vendor/semitexa/core/src/Container/README.md)

Exact versions are in `composer.lock`. Do not assume Laravel, Illuminate, or Kernel-style middleware — Semitexa has its own module and route discovery.

## Rules and guards

- **Do not** add root-level directories or change module discovery without explicit user approval.
- **Do not** add Composer dependencies without explicit user approval.
- **Do not** create documentation files (README, guides, extra `.md` in the project) unless the user explicitly asks for them.
- Treat **`docs/`** as the canonical project documentation.
- Treat **`var/docs/`** as scratch space for drafts, research notes, and temporary AI working files.

## Read before you change (mandatory)

| Before you… | Read first |
|-------------|------------|
| Understand **why** Semitexa (philosophy, goals, pain) | **vendor/semitexa/docs/README.md** (vision) and **AI_REFERENCE.md** (for agents). Monorepo: **packages/semitexa-docs/** |
| Understand the **project docs map** | **docs/README.md** |
| Create or change **module structure** (folders, Application/…) | **docs/MODULE_STRUCTURE.md** and **vendor/semitexa/core/docs/ADDING_ROUTES.md** |
| Change **service contracts** or DI bindings | **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md**; run `bin/semitexa contracts:list --json` to see current bindings |
| Add **new pages or routes** | **vendor/semitexa/core/docs/ADDING_ROUTES.md** |

## Before you generate code (checklist)

- **Modules:** only in `src/modules/`; standard layout: `Application/Payload/`, `Application/Resource/`, `Application/Handler/PayloadHandler/`, `Application/View/templates/`.
- **Routes:** only via modules (Request + Handler with attributes). Do not add routes in project `src/` (App\ is not discovered).
- **Generators first:** before hand-authoring new `Payload` / `Handler` / `Response` / page scaffolding, check whether a Semitexa generator exists via `bin/semitexa ai:capabilities --json`.
- **Use command-first flow:** if capability discovery shows a matching generator, prefer the generator over writing repetitive boilerplate manually.
- **Use follow-up hints:** after generator execution, prefer machine-readable follow-up output such as `--llm-hints` to continue only the unresolved domain logic.
- **Payloads:** after adding or changing Payload classes (or `#[AsPayloadPart]` traits), do **not** treat `registry:sync` as a required manual step. Use registry commands only for maintenance/debug flows documented by the framework.
- **Module autoload:** do not add per-module PSR-4 entries to project root `composer.json`; the framework autoloads from `src/modules/` at runtime.
- **Contracts/DI:** before changing a contract or adding an override, run `bin/semitexa contracts:list` or `contracts:list --json` to see current implementations and active binding.

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

- **docs/README.md** – project docs index and navigation.
- **docs/AI_CONTEXT.md** – short project-specific AI context.
- **vendor/semitexa/docs/README.md** and **AI_REFERENCE.md** – philosophy and goals.
- **vendor/semitexa/core/docs/** – canonical framework reference.

## Framework docs (package reference)

- **vendor/semitexa/docs/README.md** and **AI_REFERENCE.md** – philosophy and goals; read first so changes align with project intent.
- **vendor/semitexa/core/docs/ADDING_ROUTES.md** – how to add new pages/routes (modules only)
- **vendor/semitexa/core/docs/RUNNING.md** – how to run the app (Docker)
- **vendor/semitexa/core/docs/attributes/** – Request, Handler, Response attributes
- **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md** – service contracts, active implementation, and **contracts:list** command
- **vendor/semitexa/docs/README.md** – package map; **vendor/semitexa/docs/guides/CONVENTIONS.md** – conventions (when semitexa/docs is installed)

## Machine-readable commands (for AI agents and scripts)

These commands produce **stable, parseable output** — use them instead of scraping human-oriented tables:

| Command | Output | Use when |
|---------|--------|----------|
| `bin/semitexa ai:capabilities --json` | JSON: machine-readable command catalog with `use_when`, `avoid_when`, inputs, outputs, and follow-up support | Run early when the task may match a built-in generator or other AI-relevant command. Prefer this before writing boilerplate manually. |
| `bin/semitexa contracts:list --json` | JSON: `contracts[]` with `contract`, `active`, `implementations` | Debugging DI, checking bindings before/after changing contracts or modules. See vendor/semitexa/core/docs/SERVICE_CONTRACTS.md. |
| `bin/semitexa registry:sync` | Runs available registry maintenance tasks | Maintenance/debug command. Do not treat it as a required manual step after ordinary payload changes unless a specific package doc tells you to. |

(More commands may be added here with `--json` or similar; check `bin/semitexa list` and framework docs.)

## Recommended AI workflow

When a task sounds like "add a page", "create a payload", "add a response DTO", or other canonical Semitexa scaffolding:

1. Run `bin/semitexa ai:capabilities --json`.
2. If a matching generator exists, use the generator first instead of writing structural boilerplate by hand.
3. Prefer `--dry-run` if overwrite risk exists.
4. After generation, use `--llm-hints` when available to continue only the remaining domain-specific implementation.

Do not default to manual scaffolding when the framework can generate the deterministic structure safely.

## Debugging: service contracts (for AI agents and developers)

To see **which interface is bound to which implementation** (and which implementation is active when several modules provide one):

```bash
bin/semitexa contracts:list
```

Table: Contract (interface) | Implementations (module → class) | Active. Use when debugging DI or after adding/removing modules.

**For AI agents:** use `bin/semitexa contracts:list --json` for stable, parseable output. See vendor/semitexa/core/docs/SERVICE_CONTRACTS.md for details.

## Quick start

1. Read this file; follow **Read before you change** above when modifying modules, contracts, or routes.
2. For new routes: read vendor/semitexa/core/docs/ADDING_ROUTES.md
3. Run (Docker):

```bash
cp .env.example .env
```

```bash
bin/semitexa server:start
```

4. Default URL: http://0.0.0.0:{{ default_swoole_port }} (see .env SWOOLE_PORT). See vendor/semitexa/core/docs/RUNNING.md for details.
