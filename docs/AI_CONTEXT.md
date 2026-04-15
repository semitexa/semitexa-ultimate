# AI Context & Rules

> **AI agents:** Read `AGENTS.md` in the project root FIRST, then this file.
> Read this file to understand how to work with this specific Semitexa project.

## Entry point

Before changing package-specific code, read this repository first, then use installed package docs when they are actually present in `vendor/`:

- `README.md` for the project overview and main navigation.
- `AI_ENTRY.md` for the project-specific working rules for AI agents.
- `vendor/semitexa/docs/README.md` for vision, motivation, and product direction when that package is installed.
- `vendor/semitexa/docs/AI_REFERENCE.md` for the same guidance in agent-oriented form when available.

Project-specific guidance lives in `docs/`. Framework reference stays in package docs such as `vendor/semitexa/core/docs/`.

## Core philosophy

- Stack: PHP 8.4+, Swoole, Semitexa Framework
- Architecture: modular, stateful, attribute-driven
- Rule of thumb: make it work, make it right, make it fast

## Critical rules

1. Do not put new route code in project `src/` root. Create a module in `src/modules/`.
2. Remember the app runs in a loop. Static state can leak across requests.
3. Prefer PHP attributes over config files for routes, events, and services.
4. Use DTOs for requests and responses instead of passing loose arrays.
5. Keep `SSR_DEFERRED_PERSISTENT_SSE=false` unless a route explicitly needs persistent live updates and you have reviewed the capacity/security impact.

## Common tasks

### Adding a page or endpoint

1. Create a module under `src/modules/MyFeature/`.
2. Add a request DTO in `Application/Payload/Request/`.
3. Add a handler in `Application/Handler/PayloadHandler/`.
4. Return JSON or a Twig-based response DTO.

Review an existing module in `src/modules/` before changing module layout or route definitions. If the installed core package includes docs, also read `vendor/semitexa/core/docs/MODULE_STRUCTURE.md` and `vendor/semitexa/core/docs/ADDING_ROUTES.md`.

### Adding a service

1. Define an interface in `Domain/Contract/`.
2. Implement it in `Infrastructure/Service/` with `#[AsServiceContract(...)]`.
3. Inject it with property injection attributes such as `#[InjectAsReadonly]`, `#[InjectAsMutable]`, or `#[InjectAsFactory]`.

Run `bin/semitexa contracts:list --json` before changing service bindings. If the installed core package includes docs, also read `vendor/semitexa/core/docs/SERVICE_CONTRACTS.md`.

## Discovery

- Routes are defined in module request DTOs and handlers.
- Modules are discovered from `src/modules/*`, project `packages/*`, and vendor packages.

## Testing

- Run unit tests with `vendor/bin/phpunit`.
- Put tests in `tests/` or `src/modules/*/Tests/`.
