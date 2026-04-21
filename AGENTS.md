# AGENTS.md — Operator Manual

> Scaffold doc (`semitexa/ultimate`). Edit root → `bin/semitexa scaffold:sync-docs`. Consumer projects: overwritten by `bin/semitexa init --only-docs`.

Operating manual for AI agents on Semitexa. Read cold-start. Full doctrine, examples, edge cases: **`AGENTS_DOCTRINE.md`**.

The **agent** is the reasoning system (Claude, Codex, Copilot). **Semitexa** is the execution / memory / verification environment. The agent never impersonates Semitexa.

---

## 0. Prime Directives

1. **Keep your identity.** "I'll use Semitexa to…" ✅ — "As Semitexa…" ❌. Never claim to *be* Semitexa.
2. **Triage intent before action.** Classify every input as `EXECUTE` / `CAPTURE` / `REFINE`. Never execute what was only proposed.
3. **Never solve a non-trivial task in a single pass.** Decompose via `ai:epic` + `ai:work`.
4. **Externalize state.** `ai:epic` / `ai:work` / `ai:trace` are the memory. If it's not in an artifact, it didn't happen.
5. **Epics are the canonical backlog.** Repository documents are never the source of truth.
6. **Never re-derive what is already written.** Search trace / epic / context before reasoning from scratch.
7. **Fail early on uncertainty.** Low confidence → clarify. Ambiguous → branch. Don't guess.
8. **Minimize context.** Prefer summaries over raw reads; every read answers a question an artifact could not.
9. **Name the tool you use.** Say which `ai:*` / `make:*` command ran and why.
10. **Verify every write.** `ai:verify` is non-negotiable after any edit. Three consecutive failures → stop, escalate.

Violating any is a defect.

---

## 1. Intent Triage — EXECUTE / CAPTURE / REFINE

Every input goes through this gate **before** `ai:task`.

| Mode | Signals | Go to |
|---|---|---|
| **EXECUTE** | imperative + concrete object ("add X", "fix Y"); file path + change verb; "do it", "implement", "ship" | §2 Pipeline |
| **CAPTURE** | hedged / conditional ("maybe…", "we could…", "at some point…"); subjunctive, no concrete artifact | `AGENTS_DOCTRINE.md` §2 |
| **REFINE** | references existing epic/task id; "extend that", "add to X", "split off", "merge" | `AGENTS_DOCTRINE.md` §3 |

**Ambiguous → default to CAPTURE.** Never execute an unconfirmed idea.

---

## 2. Canonical Execution Pipeline (EXECUTE)

```
operator input
     │
     ▼
[0] classify intent (EXECUTE / CAPTURE / REFINE)
     │ ├── CAPTURE → DOCTRINE §2
     │ └── REFINE  → DOCTRINE §3
     ▼ EXECUTE
[1] ai:task                  classify → recipe + score + next-step
     │ └─ confidence<0.6 → clarify
     ▼
[2] inline-vs-epic triage (§3)
     │ └── inline → edit → ai:verify → ai:trace → done
     ▼ epic
[3] ai:epic start
[4] ai:work start            export SEMITEXA_AI_TRACE_ID=<id>
[5] ai:context  +  ai:review-graph:impact <FQCN>   (blast radius)
[6] ai:plan --files
     │ └─ risk=high → split; back to [3]
     ▼
[7] edit via make:* or Edit
[8] ai:verify
     │ └─ fail → ai:work update --status=blocked; iterate (three-strike rule)
     ▼
[9] ai:work update --status=done
[10] ai:epic update
```

Name every command. Summarize NDJSON — never dump it.

---

## 3. Inline vs Epic

**Inline** requires **ALL** of:
- `ai:task` score ≥ 8 AND no alternative within 2 points
- ≤ 2 files, single module, single recipe
- Risk hint = `low`
- No contract / DI / discovery / auth / routing / event / registry / persistence changes

**Epic** required when **any** holds:
- `ai:task` score < 5, or alternatives within 2 points
- ≥ 3 files or ≥ 2 modules
- Any cross-cutting concern
- > 30 min of agent work, or more than one verb in the request

When in doubt: **epic**.

Epic contract: imperative title ≤ 60 chars; one-sentence goal stating outcome; ≥ 1 `ai:work` task with `recipe` + `risk` + ≥ 1 `context-ref` + `next-step`; prerequisites first, verification last; no task > ~4k tokens.

---

## 4. The tool stack

### Workflow + memory

| Command | Role | When |
|---|---|---|
| **`ai:orient`** | Session dashboard — git + active epic + in-progress tasks + recent traces + last verify + next step | **First command on cold start.** Replaces ~6 probes. |
| `ai:task` | Classify prose → recipe + score + next-step | Every new EXECUTE unit |
| `ai:epic` | Orchestrate N tasks under a shared goal | CAPTURE save / non-trivial EXECUTE |
| `ai:work` | Track one executable leaf unit | Every leaf task |
| `ai:context <recipe>` | Prior-art for a recipe | Before edits, once per task |
| `ai:plan --files` | Risk-score recipe + files | Before edits on >1 file or unclear risk |
| `ai:verify` | Precise lint+test subset on diff | **After every edit.** Non-negotiable. |
| `ai:trace` | Durable cross-session event stream | Always. `export SEMITEXA_AI_TRACE_ID=<id>` at task start; `ai:task` / `ai:context` / `ai:plan` / `ai:verify` auto-append. |
| `ai:backlog` | Stats + hygiene (`status=discarded`, never hard-delete) | Before big renders; on operator request |

### Read-only introspection — use BEFORE Read/Grep

| Question | Command |
|---|---|
| Session state | `ai:orient --json` |
| Project overview | `ai:ask project --json` |
| Module shape | `ai:ask module --name=<Name> --json` |
| Route chain (payload→handler→resource→template→auth) | `ai:ask route --path=</p> [--method=GET] --json` |
| Events + listeners | `ai:ask event [--name=<Event>] --json` |
| All routes | `routes:list [--json]` |
| DI bindings | `contracts:list --json` |
| Capabilities manifest | `ai:ask capabilities --json` |
| Logs | `ai:ask logs --grep=<term> --lines=200 [--level=ERROR] --json` |

### Project graph — structural queries, prefer over Grep

The graph understands **real edges** (`instantiates`, `accepts`, `returns`, `implements`, `extends`, `uses`, `imports`, `handles`, `serves_route`) — use it whenever the question is about code structure, not string matches.

| Question | Command |
|---|---|
| Who uses class `X`? | `ai:review-graph:query --usages=<FQCN> --json` |
| What does class `X` depend on? | `ai:review-graph:query --dependencies=<FQCN> --json` |
| Blast radius of changing `X` | `ai:review-graph:impact <FQCN> --json` |
| Cross-module edges | `ai:review-graph:query --cross-module [--from=<M>] [--to=<M>] --json` |
| Full-text symbol search | `ai:review-graph:query --search=<term> --json` |
| Rebuild after large changes | `ai:review-graph:generate [--full] --json` |

Accepts raw FQCN or node id.

### Runtime + scaffolding

| Command | Role | When |
|---|---|---|
| `ai:invoke` | Dry-run a handler — no HTTP / auth / middleware | Fast feedback on handler changes (`--route=</p>` or `--handler=<FQCN>`) |
| `make:*` | Generators — **dry-run by default**, `--write` commits. Prefer `make:page` over three `make:payload/handler/resource` calls. Generated files carry `/** @generated by 'semitexa make:X' */`. | New payload / handler / resource / module / service / contract / event-listener / command |
| `semitexa:lint:handlers` | Handler signature validity | After modifying handlers |
| `semitexa:lint:di` | DI injection validity | After changing service wiring |

**Most `ai:*` commands emit `next_command: [{cmd, args, why}]`** in their JSON envelope — **follow the graph, don't memorize the tool list.**

---

## 5. Canonical Backlog — the single rule

**Ideas, initiatives, tasks, and status** live in `ai:epic` (`var/ai-work/epics/`) + `ai:work` (`var/ai-work/tasks/`). Nothing else is canonical — not `var/docs/`, not `packages/semitexa-docs/`, not chat history, not design docs, not commit messages.

If an idea isn't in `ai:epic`, it doesn't exist from a backlog standpoint. The fix is to CAPTURE it (§1), not mine documents.

### Backlog queries

| Operator says | Run |
|---|---|
| "show ideas / backlog / epics / initiatives" | `ai:epic list --json` *(scope=active default)* |
| "show tasks / work items" | `ai:work list [--epic=<id>] --json` |
| "what are we working on / active" | `ai:work list --status=in_progress,blocked --json` |
| "what's next" | `ai:work list --status=new --json` |
| "show epic X" | `ai:epic show --id=<id> --json` |
| "show drafts / junk / archive / discarded / everything" | `ai:epic list --scope=<drafts\|junk\|archive\|discarded\|all> --json` |
| "backlog health" | `ai:backlog stats --json` |
| "clean the backlog" | `ai:backlog clean [--apply] --json` *(dry-run by default)* |

**No silent fallback.** Empty `ai:epic list` → report "no epics" and offer CAPTURE. **No mixed sources.** A backlog answer is epics + tasks only. **Always state the scope:** `source: ai:epic list --scope=active`.

**Document mining is opt-in** — trigger phrases: *"extract ideas from `<file>`"*, *"mine candidates from `<path>`"*. Outputs are **candidate ideas**, never "epics". Full flow: **DOCTRINE §6**.

---

## 6. Context Minimization

- **One-read rule.** Each file is read ≤ once per task. Next time: read the trace, not the file.
- **Hierarchy of truth** — stop at first answer:
  1. Current-turn tool output
  2. `ai:orient` / `ai:work show` / `ai:trace show`
  3. `ai:context <recipe>`
  4. `ai:ask <subject>`
  5. `ai:review-graph:query|impact`
  6. `Grep` / `Glob` for a specific symbol
  7. `Read` a specific file
  8. Agent delegation (`Explore`, `general-purpose`) — last resort
- **Token budget.** Inline ≤ 4k, epic leaf ≤ 8k. Over budget → split via `ai:epic update` + new `ai:work start`.
- **Don't dump NDJSON.** Summarize.
- **Use `ai:ask` whenever the alternative is 3+ `Read`/`Grep`.** Use `ai:review-graph:*` whenever the question is "who/what uses this class".

---

## 7. Hard Guards — never cross

- Do **not** add root-level directories, change module discovery, or add Composer dependencies without explicit approval.
- Do **not** create documentation files (`*.md`) unless explicitly requested.
- Do **not** add per-module PSR-4 entries to root `composer.json` — modules autoload from `src/modules/`.
- Do **not** add routes outside a module — `App\` is not discovered for routes.
- Do **not** treat any document as the backlog (§5). Doc mining is opt-in only.
- Do **not** impersonate Semitexa. First-person is fine; third-person runtime roleplay is not.
- Do **not** create placeholder epics/tasks (`ep-a`, title `test`/`tmp`/`foo`). Push back for real id / title / goal.
- Do **not** hard-delete epics/tasks. Retirement is `status=discarded`. `var/ai-work/` stays append-only.
- Do **not** render the backlog without a stated scope.
- `packages/semitexa-docs/` is the single official documentation source; `packages/<pkg>/docs/` is canonical package-local; `var/docs/` is scratch. Root-level `./docs/` is **not** official.
- **Every temporary / generated `.md`** — planning notes, audits, analysis, reports, remediation plans, design drafts — goes in **`var/docs/`**, never in project root. Project root holds only the framework scaffold docs: `AGENTS.md`, `AGENTS_DOCTRINE.md`, `AI_ENTRY.md`, `AI_CONTEXT.md`, `AI_REFERENCE.md`, `CLAUDE.md`, `README.md`, `AI_NOTES.md`. If the operator asks for a report, write it to `var/docs/<slug>.md` and cite that path — never dump a fresh top-level `.md`.

---

*Capture/refine flows with examples, duplicate scoring, backlog lifecycle, hygiene, trace/verify deep-dive, user interaction model — all in **`AGENTS_DOCTRINE.md`**.*
