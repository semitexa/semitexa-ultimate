# `var/docs/` — Scratch / Working Directory

**This directory is not canonical documentation.** It is a local working space for your project.

## What belongs here

- Drafts and work-in-progress notes
- Generated reports (audits, remediation trails, release readiness)
- Technical design drafts awaiting review
- Experiment results and research notes
- AI-generated scratch files

## What does not belong here

- Official framework documentation (lives in `vendor/semitexa/docs/` when installed)
- Canonical package-level reference (lives in `vendor/<package>/docs/`)
- Project-specific AI guidance (lives at project root: `AI_ENTRY.md`, `AI_CONTEXT.md`, `AGENTS.md`)
- Anything another part of your codebase links to as authoritative

## Lifecycle

Material here is ephemeral. When a draft matures into something you want to keep, move it to a meaningful location — per-module docs, a project-level README, or the appropriate framework package.

## Git handling

In generated/scaffolded projects, `var/docs/` is typically gitignored except for this `README.md` and the tracked `.gitkeep`. In this repository, that behavior may be defined by the scaffold template rather than the repo's own `.gitignore`. If you need to commit a specific artifact from here, add an explicit exception in `.gitignore` (e.g. `!var/docs/my-audit.md`). Do not un-gitignore the whole tree.
