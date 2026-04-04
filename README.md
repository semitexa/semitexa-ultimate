# Semitexa Ultimate

Full-stack project skeleton for SSR applications with Docker-based setup.

## Purpose

The official starter project for building Semitexa applications. Provides a pre-configured Docker environment, example module, and all essential packages for a production-ready SSR application.

## Role in Semitexa

Entry point for new projects. Pulls Core, Auth, Authorization, Tenancy, Locale, ORM, SSR, and Docs as direct dependencies. Developers create projects from this skeleton.

Documentation files under `semitexa-ultimate` are scaffold assets for generated projects, not the canonical home for framework internals.

## Key Features

- Docker-first setup (no host PHP required)
- Swoole HTTP server entry point (`server.php`)
- Example Hello module with payload/handler/response
- Pre-configured Docker Compose (app, db, redis)
- `bin/semitexa` CLI wrapper for container commands
- Includes core auth/tenancy/i18n/ORM/SSR stack

## Deferred SSE Defaults

Starter projects generated from `semitexa/ultimate` ship with safe SSR deferred defaults:

- `SSR_DEFERRED_PERSISTENT_SSE=false`
- `SSR_DEFERRED_PERSISTENT_SSE_REQUIRE_AUTH=true`

This means deferred SSR streams late HTML blocks once and closes the SSE connection. Persistent reconnect-capable SSE must be enabled explicitly and still requires an authenticated session by default.

## robots.txt Fallback

Starter projects also inherit the SSR `robots.txt` fallback. If you do not add a real `robots.txt` file, Semitexa serves a minimal one automatically with crawler hints for machine-readable page documents.

Optional env hints:

- `ROBOTS_SITEMAP_URL=https://example.com/sitemap.xml`
- `ROBOTS_HOST=example.com`
- `AI_SITEMAP_URL=https://example.com/sitemap.json`

## Package Registry

`semitexa/ultimate` pins exact Semitexa package versions, but it intentionally does not hardcode a private Composer repository URL into `composer.json`. Generated projects are expected to resolve these internal packages through the Composer repository and credentials configured in the installation environment.

## Notes

Run `docker run --rm -v "$(pwd)":/app semitexa/installer install` to scaffold a new project, then `bin/semitexa server:start` to start.
