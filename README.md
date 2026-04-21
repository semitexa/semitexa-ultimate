# About Semitexa

> **Source: `semitexa/ultimate` scaffold.** This file is bundled with the Semitexa Ultimate package and is copied into new projects by `bin/semitexa init`. In the `semitexa.dev` monorepo edit the root copy and run `bin/semitexa scaffold:sync-docs` to propagate; in a consumer project, `bin/semitexa init --only-docs` refreshes the file and local edits will be overwritten.

"Make it work, make it right, make it fast." — Kent Beck

Semitexa isn't just a framework; it's a philosophy of efficiency.
Engineered for the high-performance Swoole ecosystem and built with an AI-first mindset,
it allows you to stop fighting the infrastructure and start building the future.

Simple by design. Powerful by nature.

## Requirements

- Docker and Docker Compose
- Composer (on host for install)

## Install

From an empty folder (get the framework and install dependencies):

```bash
composer require semitexa/ultimate
```

From a clone or existing project (dependencies already in `composer.json`):

```bash
composer install
```

Then:

```bash
cp .env.default .env
```

## Run (Docker — supported way)

```bash
bin/semitexa server:start
```

To stop:

```bash
bin/semitexa server:stop
```

Default URL: **http://0.0.0.0:9502** (configurable via `.env` `SWOOLE_PORT`).

## Documentation

Official framework documentation lives in `packages/semitexa-docs/`. Package-level deep reference lives in `vendor/` (or `packages/` in the monorepo).

| Topic | File or folder |
|-------|----------------|
| **AI context for this project** | [AI_CONTEXT.md](AI_CONTEXT.md) |
| **Framework docs hub** | [packages/semitexa-docs/docs/README.md](packages/semitexa-docs/docs/README.md) |
| **Workspace / monorepo docs** — architecture, DI, PHPStan, testing, policy | [packages/semitexa-docs/docs/workspace/README.md](packages/semitexa-docs/docs/workspace/README.md) |
| **Running the app** — Docker, ports, logs | [vendor/semitexa/core/docs/RUNNING.md](vendor/semitexa/core/docs/RUNNING.md) |
| **Adding pages and routes** — modules, Request/Handler | [vendor/semitexa/core/docs/ADDING_ROUTES.md](vendor/semitexa/core/docs/ADDING_ROUTES.md) |
| **Attributes** — AsPayload, AsPayloadHandler, AsResource, etc. | [vendor/semitexa/core/docs/attributes/README.md](vendor/semitexa/core/docs/attributes/README.md) |
| **Service contracts** — contracts:list, active implementation | [vendor/semitexa/core/docs/SERVICE_CONTRACTS.md](vendor/semitexa/core/docs/SERVICE_CONTRACTS.md) |

The repository does not treat a root-level `./docs/` directory as canonical. Project-level AI guidance lives at root (`AGENTS.md`, `AI_ENTRY.md`, `AI_CONTEXT.md`, `AI_NOTES.md`); framework guidance lives in `packages/semitexa-docs/`; per-package reference lives in `packages/<package>/docs/`.

## Structure

- `src/modules/` – your application modules (add new pages and endpoints here). New routes only in modules.
- `packages/semitexa-docs/` – official Semitexa framework and workspace documentation.
- `packages/<package>/docs/` – per-package canonical reference.
- `var/docs/` – working directory for notes, drafts, research, and remediation reports; not canonical.
- `AI_ENTRY.md`, `AI_CONTEXT.md`, `AGENTS.md` – AI entrypoints and rules at project root; `AI_NOTES.md` is your notes (never overwritten).

## Tests

Semitexa is Docker-based. **Tests must run inside the project's test container.** The only supported command is:

```bash
bin/semitexa test:run
```

This wraps PHPUnit with the correct container, environment, and test-path discovery. Pass PHPUnit arguments positionally:

```bash
bin/semitexa test:run --filter MyTest
bin/semitexa test:run tests/Unit/Foo/BarTest.php
```

Running `vendor/bin/phpunit` directly on the host is **not supported** — the environment, service dependencies, and path resolution only match when tests run through `bin/semitexa test:run`.

Configuration lives in `phpunit.xml.dist`; add tests in `tests/` or in `packages/*/tests/` for monorepo packages (both are auto-discovered).
