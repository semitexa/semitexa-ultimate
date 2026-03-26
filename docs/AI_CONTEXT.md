# AI Context & Rules

> Read this file to understand how to work with this specific Semitexa project.

## Entry point

Before changing package-specific code, read Semitexa's goals so your work stays aligned with the framework:

- `docs/README.md` for project-level navigation and what is canonical vs draft.
- `vendor/semitexa/docs/README.md` for vision, motivation, and product direction.
- `vendor/semitexa/docs/AI_REFERENCE.md` for the same guidance in agent-oriented form.

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

## Common tasks

### Adding a page or endpoint

1. Create a module under `src/modules/MyFeature/`.
2. Add a request DTO in `Application/Payload/Request/`.
3. Add a handler in `Application/Handler/PayloadHandler/`.
4. Return JSON or a Twig-based response DTO.

Read `docs/MODULE_STRUCTURE.md` and `vendor/semitexa/core/docs/ADDING_ROUTES.md` before changing module layout or route definitions.

### Adding a service

1. Define an interface in `Domain/Contract/`.
2. Implement it in `Infrastructure/Service/` with `#[AsServiceContract(...)]`.
3. Inject it with property injection attributes such as `#[InjectAsReadonly]`, `#[InjectAsMutable]`, or `#[InjectAsFactory]`.

Read `vendor/semitexa/core/docs/SERVICE_CONTRACTS.md` and `vendor/semitexa/core/src/Container/README.md` before changing service bindings.

## Discovery

- Routes are defined in module request DTOs and handlers.
- Modules are discovered from `src/modules/*`, project `packages/*`, and vendor packages.

## Testing

- Run unit tests with `vendor/bin/phpunit`.
- Put tests in `tests/` or `src/modules/*/Tests/`.
