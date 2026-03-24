# semitexa/ultimate

Full-stack project skeleton for SSR applications with Docker-based setup.

## Purpose

The official starter project for building Semitexa applications. Provides a pre-configured Docker environment, example module, and all essential packages for a production-ready SSR application.

## Role in Semitexa

Entry point for new projects. Pulls Core, Auth, Authorization, Tenancy, Locale, ORM, SSR, and Docs as direct dependencies. Developers create projects from this skeleton.

## Key Features

- Docker-first setup (no host PHP required)
- Swoole HTTP server entry point (`server.php`)
- Example Hello module with payload/handler/response
- Pre-configured Docker Compose (app, db, redis)
- `bin/semitexa` CLI wrapper for container commands
- Includes core auth/tenancy/i18n/ORM/SSR stack

## Notes

Run `docker run --rm -v $(pwd):/app semitexa/installer install` to scaffold a new project, then `docker compose up -d` to start.
