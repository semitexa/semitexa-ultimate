# semitexa

> **Philosophy & ideology** — [Why Semitexa: vision and principles](../semitexa-docs/README.md). Detailed docs live in each sub-package (core, frontend, docs) according to what it does.

Meta-package: no code, only a dependency list. Use it to pull the full Semitexa stack in one go:

- **semitexa/core** — framework
- **semitexa/module-core-frontend** — SSR with Twig (HTML pages)
- **semitexa/docs** — AI reference and conventions

```bash
composer require semitexa
```

Then run `semitexa init` (or follow the scaffold from core) and build your site.
