<!--
Source: semitexa/ultimate scaffold. This file is copied into new projects by `bin/semitexa init`.
In the semitexa.dev workspace edit the root copy and run `bin/semitexa scaffold:sync-docs` to propagate;
in a consumer project, `bin/semitexa init --only-docs` refreshes it and local edits are overwritten.
-->

# Semitexa

Semitexa is a modular PHP framework with a Swoole-first runtime: server-side rendered pages, typed request payloads and handlers, an ORM and an event bus, all running in long-lived workers inside Docker. It is for PHP developers who want an application that stays fast under load and that an AI coding agent can work on safely: the framework describes its own structure and checks every change.

This repository is **Semitexa Ultimate**, the project skeleton a new Semitexa application starts from.

## Three ideas

- **Project Graph.** Semitexa scans your code into a graph of classes and the real edges between them (instantiates, implements, handles, serves route, and so on). You, or an agent, can ask who uses a class or what a change would affect instead of grepping: `bin/semitexa ai:review-graph:query --usages=<Class>`, `bin/semitexa ai:review-graph:impact <Class>`.
- **ai:verify.** After an edit, `bin/semitexa ai:verify` runs the lints, tests and module-structure checks that apply to the changed files, and writes a receipt that records what it checked, so a "verified" claim can be checked rather than believed.
- **Swoole SSR with live updates over SSE.** Pages are rendered on the server by long-lived Swoole workers. Slow parts of a page can be deferred and streamed into it over Server-Sent Events, and server-side changes can be pushed to pages that are already open the same way.

## Quickstart

Prerequisites: Docker with Compose v2, and a user in the `docker` group. You do not need PHP or Composer on the host; the runtime (PHP 8.4 + Swoole 6.x) runs inside the container.

```bash
curl -fsSL https://semitexa.com/install.sh | bash -s my-project
cd my-project
bin/semitexa server:start      # first run takes about a minute (Composer runs in the setup container); prints the URL
# open http://localhost:9502   (the default; if 9502 is busy, a free port in 9501-9599 is picked and written to .env)
bin/semitexa orm:sync          # create the database tables (the stack runs MySQL, Redis and NATS)
```

What the installer does outside the project directory: it registers the app in `~/.semitexa/router/registry/apps/` so that several Semitexa projects on one machine get different ports. A local `.test` domain is optional and off by default. If you ask for one (`--local-domain`, or answer yes to the prompt), it starts shared router containers on host port 80 and, with sudo, changes your system DNS (systemd-resolved, `/etc/resolv.conf`) or `/etc/hosts`.

To stop the stack: `bin/semitexa server:stop`. To see every command: `bin/semitexa list`.

## What to open next

- **The Hello page.** The page at `/` of a new project is the `Hello` module in `src/modules/Hello/`. Read it as a working example of a module: a payload declares the route, a handler fills a resource, and a Twig template renders it.
- **Your first page.** `make:page` generates the payload, handler, resource and template in one go. It is a dry run by default; add `--write` to create the files, then restart the server:

  ```bash
  bin/semitexa make:page --module=Hello --name=About --path=/about --method=GET --access=public
  bin/semitexa make:page --module=Hello --name=About --path=/about --method=GET --access=public --write
  bin/semitexa server:restart
  ```

- **Schema and data.** `bin/semitexa orm:sync` creates and updates tables from your entities; `orm:diff`, `orm:status` and `orm:seed` sit next to it.
- **Logs.** `bin/semitexa logs:app`.

## Project layout

- `src/modules/<Module>/` holds your application code (namespace `App\Modules\<Module>\`). Modules are picked up automatically; routes live only in modules.
- `AGENTS.md`, `AI_ENTRY.md`, `AI_CONTEXT.md` are the instructions for AI coding agents. `AI_NOTES.md` is yours and is never overwritten.
- `var/docs/` is a scratch folder for notes and drafts.
- `.env.default` is the committed baseline; put local overrides in `.env`.

## Tests

Tests run inside the project's containers:

```bash
bin/semitexa test:run
bin/semitexa test:run --filter MyTest
```

Module tests live next to the module they cover, in `src/modules/<Module>/tests/`.

## Learn more

- Documentation: https://semitexa.com/docs
- Live demo of the framework: https://framework.semitexa.com
