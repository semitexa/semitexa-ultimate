# AI Agent Instructions

## Session Start Protocol

**BEFORE writing any code or answering questions**, execute these commands in order:

```bash
bin/semitexa ai:review-graph:generate --json
bin/semitexa ai:review-graph:stats --json
bin/semitexa ai:capabilities --json
```

The project graph is the **fastest and most reliable** way to understand the codebase structure. It provides:
- Module boundaries and cross-module dependencies
- Event flows and execution paths
- Service contracts and DI bindings
- Impact analysis for proposed changes

**Do not skip graph generation.** Reading source files without graph context is slower and error-prone.

## Core Rules

- **Do not** add root-level directories or change module discovery without explicit approval
- **Do not** add Composer dependencies without explicit approval
- **Do not** create documentation files unless explicitly requested
- **Do not** write boilerplate by hand — use code generators from `ai:capabilities`
- Treat `docs/` as canonical project documentation
- Treat `vendor/semitexa/*/docs/` as canonical framework reference
- Treat `var/docs/` as scratch space only

## Project Understanding Workflow

1. **Generate graph** → `bin/semitexa ai:review-graph:generate --json`
2. **Confirm readiness** → `bin/semitexa ai:review-graph:stats --json`
3. **Get task context** → `bin/semitexa ai:review-graph:context "<task>" --format=json`
4. **Trace flows/events** → `bin/semitexa ai:review-graph:event-trace <Event> --format=json`
5. **Check impact** → `bin/semitexa ai:review-graph:impact <Component> --format=json`
6. **Read specific files** identified in steps 3-5

## Code Generation

Always run `bin/semitexa ai:capabilities --json` before writing:
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
