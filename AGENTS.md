# AI Agent Instructions

## Session Start Protocol

Start from the task, not from a mandatory warmup ritual.

Default entry point:

```bash
bin/semitexa ai:task "<task description>"
```

Use graph and capability commands only when the classifier, the verification flow, or the task itself requires them. If `ai:task` is not available in the current install, apply the same rule manually: start with a one-line task description and fetch only the narrowest relevant command instead of running `generate -> stats -> capabilities` by default.

The project graph remains the fastest structural tool when you actually need it. Use it explicitly for:
- Module boundaries and cross-module dependencies
- Event flows and execution paths
- Service contracts and DI bindings
- Impact analysis for proposed changes

## Core Rules

- **Do not** add root-level directories or change module discovery without explicit approval
- **Do not** add Composer dependencies without explicit approval
- **Do not** create documentation files unless explicitly requested
- **Do not** write boilerplate by hand — use code generators from `ai:capabilities`
- Treat `docs/` as canonical project documentation
- Treat `vendor/semitexa/*/docs/` as canonical framework reference
- Treat `var/docs/` as scratch space only

## Project Understanding Workflow

1. **Classify the task first** → `bin/semitexa ai:task "<task>"` when available
2. **Fetch narrow graph context only if needed** → `bin/semitexa ai:review-graph:context "<task>" --format=json` or the closest available graph command for the task
3. **Refresh graph explicitly when graph-backed answers are stale or missing** → `bin/semitexa ai:review-graph:generate --json`
4. **Confirm graph readiness only after refresh or before relying on graph output for risky work** → `bin/semitexa ai:review-graph:stats --json`
5. **Trace flows/events for evented or execution-heavy changes** → `bin/semitexa ai:review-graph:event-trace <Event> --format=json`
6. **Check impact before risky shared changes** → `bin/semitexa ai:review-graph:impact <Component> --format=json`
7. **Read specific files** identified by the task-scoped context

## Code Generation

Use `bin/semitexa ai:capabilities --json` when the task may match a built-in generator or scaffolder. Do not treat it as mandatory for every edit.

Generator mapping:
- Payloads → use `make:payload`
- Handlers → use `make:handler`
- Resources → use `make:resource`
- Pages → use `make:page`
- Modules → use `make:module`
- Services → use `make:service`
- Event listeners → use `make:event-listener`
- Contracts → use `make:contract`
- CLI commands → use `make:command`

## Module Structure

New modules go in `src/modules/` only. Standard layout:
```
src/modules/<Module>/
├── Application/
│   ├── Payload/
│   ├── Resource/
│   ├── Handler/PayloadHandler/
│   └── View/templates/
```

**Do not** add per-module PSR-4 entries to `composer.json` — the framework autoloads from `src/modules/` at runtime.

## Routes

Routes exist only via modules (Request + Handler with attributes). Do not add routes in project `src/` — `App\` namespace is not discovered for routes.

## Service Contracts

Before changing a contract or adding an override:
```bash
bin/semitexa contracts:list --json
```

## Debugging Commands

| Task | Command |
|------|---------|
| Module structure | `bin/semitexa describe:module --json --module=<Name>` |
| Project overview | `bin/semitexa describe:project --json` |
| Route chain | `bin/semitexa describe:route --json --path=/path` |
| Events | `bin/semitexa describe:event --json` |
| DI bindings | `bin/semitexa contracts:list --json` |
| All routes | `bin/semitexa routes:list` |
| Handler validation | `bin/semitexa semitexa:lint:handlers` |
| DI validation | `bin/semitexa semitexa:lint:di` |

## Stack

- PHP: ^8.4
- semitexa/core: see `composer.json` for the exact pinned version
- Symfony 7.x (console, process)
- Twig ^3.10
- PSR Container with Semitexa custom DI (AsServiceContract, InjectAsReadonly/Mutable/Factory)

## Quick Start

1. Ensure `.env` exists with local overrides (`.env.default` is the committed baseline — do not copy it).
2. Run:

```bash
bin/semitexa server:start
```

Default URL: use the `SWOOLE_PORT` from your `.env` (or framework default if unset). See `vendor/semitexa/core/docs/RUNNING.md` for details.
