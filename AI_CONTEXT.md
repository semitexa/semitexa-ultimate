# AI_CONTEXT.md — runtime rules for this project

> Scaffold doc (`semitexa/ultimate`). Edit root → `bin/semitexa scaffold:sync-docs`. Consumer projects: overwritten by `bin/semitexa init --only-docs`.

Read **`AGENTS.md`** (operator manual) and **`AI_ENTRY.md`** (stack + command surface) first. This file covers Semitexa runtime specifics that bite at edit time.

## Runtime critical rules

1. **Swoole long-running process.** Static state leaks across requests. Module services are per-worker readonly; mutable state is per-request via clone. Don't cache request-scoped data in static properties.
2. **PHP attributes, not config files** — routes via `#[AsPayload]` / `#[AsPayloadHandler]`, events via `#[AsEventListener]`, services via `#[SatisfiesServiceContract]` + `#[InjectAsReadonly|Mutable|Factory]`.
3. **DTOs for request and response** — never pass loose arrays across the handler boundary.
4. **Keep `SSR_DEFERRED_PERSISTENT_SSE=false`** unless a route explicitly needs persistent SSE and you have reviewed capacity/security.

## Creating code — prefer generators

Hand-rolling payload/handler/resource is legacy. See `AI_ENTRY.md` Generators table. In short: `make:page` for endpoints, `make:contract` for service contracts, `make:event-listener` for listeners, `make:command` for CLI.

## Before changing service bindings

1. `bin/semitexa contracts:list --json` — current active implementations.
2. `bin/semitexa ai:review-graph:query --usages=<InterfaceFQCN> --json` — every consumer and implementor in the graph.
3. `bin/semitexa ai:review-graph:impact <ImplementationFQCN> --json` — blast radius before swapping.

## Testing

- Run tests with **`bin/semitexa test:run`** — executes PHPUnit inside Docker with `APP_ENV=dev`. Do **not** run `vendor/bin/phpunit` directly.
- PHPUnit args pass positionally: `bin/semitexa test:run --filter MyTest`.
- Test location: `tests/` or `src/modules/*/Tests/`.
