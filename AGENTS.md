# AGENTS.md — Operator Manual

> Scaffold doc (`semitexa/ultimate`). Edit root → `bin/semitexa scaffold:sync-docs`. Consumer projects: overwritten by `bin/semitexa init --only-docs`.

Operating manual for AI agents on Semitexa. Read cold-start. Full doctrine, examples, edge cases: **`AGENTS_DOCTRINE.md`**.

The **agent** is the reasoning system (Claude, Codex, Copilot). **Semitexa** is the execution / memory / verification environment. The agent never impersonates Semitexa.

---

## 0. Prime Directives

1. **Keep your identity.** "I'll use Semitexa to…" ✅ — "As Semitexa…" ❌. Never claim to *be* Semitexa.
2. **Triage intent before action.** Classify every input as `EXECUTE` / `CAPTURE` / `REFINE`. Never execute what was only proposed.
3. **Decompose work that will outlive your context.** Via `ai:epic` + `ai:work`.
   - *Does not apply* to work that fits one sitting and carries no branching decisions: a
     focused fix, a rename, a single test. Opening an epic for it produces notes nobody
     writes properly, and ceremony nobody trusts is worse than none.
   - *Does apply* the moment the work is long enough to lose its own context, involves a
     decision you would otherwise re-litigate, or spans more than one repository. Context
     compaction inside a **single** session is the common case — the notes are what survive
     it, and they are read by you, later, not by an auditor.
4. **Externalize state.** `ai:epic` / `ai:work` / `ai:trace` are the memory. If it's not in an artifact, it didn't happen.
   - **4a — A workaround for a framework defect is half the work.** Directive 4 stops at the project boundary — a consumer's `ai:epic` is never seen by Semitexa. When you work around a Semitexa bug, filing it with `ai:report` is part of the task, not an optional extra. An unrecorded workaround makes the system *look* healthy and guarantees the next agent pays the same cost. Evidence is mandatory: a suspicion without a command and its output is usually your own mistake, not a framework defect.
5. **Epics are the canonical backlog.** Repository documents are never the source of truth.
6. **Never re-derive what is already written.** Search trace / epic / context before reasoning from scratch.
7. **Fail early on uncertainty.** Low confidence → clarify. Ambiguous → branch. Don't guess.
8. **Minimize context.** Prefer summaries over raw reads; every read answers a question an artifact could not.
   - *Does not apply* when you need the bytes: an edit must match exactly, so a summary of a
     file you are about to change is not a cheaper read — it is a guess.
9. **Name the tool you use.** Say which `ai:*` / `make:*` command ran and why.
10. **Verify every write.** `ai:verify` is non-negotiable after any edit. Three consecutive failures → stop, escalate.
    - *Cadence, not per-keystroke*: a run of edits that only makes sense together is verified
      once, when it is coherent. Verifying mid-sequence reports failures you are about to fix
      anyway, and the cost is real — see §4 on not wrapping it in `server:restart`.

Violating any is a defect. Where a directive names its own exception, applying that exception
is not a violation — it is the rule working. A rule that has to be broken regularly and
silently teaches that the whole list is negotiable.

---

## 0a. When the layers disagree

Instructions arrive in layers, and they will not always agree in emphasis. Arbitrate in this
order rather than deciding afresh each time:

1. **The operator, in this conversation.** A direct instruction wins over every document. If it
   contradicts a Hard Guard (§7), say so once and let them decide — do not silently comply and do
   not silently refuse.
2. **`AGENTS.md`** — this file. The operating rules.
3. **`AGENTS_DOCTRINE.md`** — the same rules with the flows spelled out. Where it seems to
   contradict this file, it is elaborating, not overruling; if it truly conflicts, this file wins
   and the conflict is a defect worth reporting.
4. **`next_command` hints** from a command's own envelope — situational and usually right about
   the immediate next step, but they do not override a rule.
5. **Memory files** — what was true when written. Verify anything they assert about the code
   before acting on it; a memory naming a file or flag is a lead, not a fact.

`CLAUDE.md` sits above this file only in telling you to read it.

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
[1] ai:task                  classify → recipe + score + confidence + next-step
     │ └─ confidence: low/none → clarify
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
[6b] touching a page, a region, a form or an interaction?
     │   ai:ask mechanisms --json          (narrow with --area=ssr or --area=ui)
     │   └─ the framework already does deferred regions, components, live
     │      transport and UI behaviours. Check BEFORE hand-rolling one.
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

When in doubt: **epic** — but doubt is the test, not habit. If none of the Epic conditions holds
and you are reaching for one anyway, you are performing the process rather than using it.

**What the notes are for.** Not an audit trail. They are what you read after your own context is
compacted — the decision you already made and would otherwise re-litigate, the hypothesis you
already disproved, the number you already measured. Write them for yourself in an hour, and the
test of a good `next-step` is whether it would restart the work cold. Notes written for a
reviewer are the ones that turn out empty.

Epic contract: imperative title ≤ 60 chars; one-sentence goal stating outcome; ≥ 1 `ai:work` task with `recipe` + `risk` + ≥ 1 `context-ref` + `next-step`; prerequisites first, verification last; no task > ~4k tokens.

---

## 4. The tool stack

### Workflow + memory

| Command | Role | When |
|---|---|---|
| **`ai:orient`** | Session dashboard — git + active epic + in-progress tasks + recent traces + last verify + next step | **First command on cold start.** Replaces ~6 probes. |
| `ai:task` | Classify prose → recipe + score + `confidence` (high/low/none) + next-step | Every new EXECUTE unit |
| `ai:epic` | Orchestrate N tasks under a shared goal | CAPTURE save / non-trivial EXECUTE |
| `ai:work` | Track one executable leaf unit | Every leaf task |
| `ai:context <recipe>` | Prior-art for a recipe | Before edits, once per task |
| `ai:plan --files` | Risk-score recipe + files | Before edits on >1 file or unclear risk |
| `ai:verify` | Precise lint+test+module-structure subset on diff (see [`MODULE_STRUCTURE.md`](packages/semitexa-docs/docs/MODULE_STRUCTURE.md)) | **After every edit.** Non-negotiable. |
| `ai:trace` | Durable cross-session event stream | Always. `export SEMITEXA_AI_TRACE_ID=<id>` at task start; `ai:task` / `ai:context` / `ai:plan` / `ai:verify` auto-append. |
| `ai:backlog` | Stats + hygiene (`status=discarded`, never hard-delete) | Before big renders; on operator request |
| `ai:report` | File a **framework** defect + the workaround as a Semitexa issue. Requires evidence; searches for duplicates first and adds a sighting instead; drafts locally when `gh` is unavailable so nothing is lost | Whenever you work around a Semitexa bug — see Directive 4a |

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
| Capabilities manifest — what COMMANDS exist to run | `ai:ask capabilities --json` |
| What the FRAMEWORK can do — deferred regions, components, live transport, UI behaviours | `ai:ask mechanisms --json`; narrow with `--area=ssr`, `--area=ui` or `--id=<cap>` |
| Logs | `ai:ask logs --grep=<term> --lines=200 [--level=ERROR] --json` |
| What is running RIGHT NOW / what just finished | `ai:observe ps` *(live processes: http, sse, scheduler, queue; stale flags; waterfall links)* |
| Live stream of process lifecycles | `ai:observe tail [--kind=] [--name=] [--follow --duration=15]` *(NDJSON; --follow self-terminates)* |
| One process in depth — spans, queries with positions, payload snapshot | `ai:observe show --id=<p-…> [--source]` *(inlines the full trace when the request carried `?__trace=1`; `--source` adds the source of the method each span ran — read live, keyed `Class::method`, spans carry `source_ref`)* |
| Re-run a recorded request in a sandbox | `ai:observe replay --id=<p-…> [--mutate k=v]` *(writes ALWAYS roll back, queue handoffs captured; diffs vs the original trace)* |

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
| `ai:observe` | The Observatory: live process journal, deep traces, sandbox replay. Humans get the same data at `/__observatory`; a request with `?__trace=1` records a full waterfall at `/__trace`, and every step there opens the source of the method that ran it at `/__trace/node` | **First stop when debugging runtime behaviour** — observe before hypothesizing. Dev-only by construction |
| `make:*` | Generators — **dry-run by default**, `--write` commits. Prefer `make:page` over three `make:payload/handler/resource` calls. Generated files carry `/** @generated by 'semitexa make:X' */`. | New payload / handler / resource / module / service / contract / event-listener / command |
| `lint:handlers` | Handler signature validity | After modifying handlers |
| `lint:di` | DI injection validity | After changing service wiring |

| `server:restart` | Reload the RUNNING server (~16s) | **Only** before exercising it — browser, curl, E2E. Workers cache discovered classes and compiled templates for their lifetime. |

**`ai:verify` does NOT need a restart.** Every check runs in a fresh CLI process that rediscovers from disk, so a file saved a second ago is already visible. Wrapping verification in `server:restart` costs ~16s around ~4s of actual work — four fifths of the cycle, for nothing. The envelope says so in `restart`.

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
  - *Re-read when the bytes changed or must match*: after your own edit, and before an edit
    that has to line up exactly with existing text. A stale memory of a file is not context
    minimization, it is a wrong edit waiting to happen.
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
- Do **not** put the consumer's code, configuration, environment values, or credentials into an `ai:report`. It publishes to a **public** tracker, usually from someone else's machine. A minimal reproduction is the ceiling; the command refuses obvious credential shapes, but that is a backstop, not the rule. Never publish without the operator seeing the rendered issue first.
- Calling a **new** API of another `semitexa/*` package? Put a floor on it: `"semitexa/core": ">=2026.09.13.0749"`, naming the release that first shipped what you call. Internal deps are `*` by default, and `*` cannot say "at least" — a consumer installing one package beside an older pinned core got a resolution composer accepted and a worker that died on a missing class. Nothing rewrites a floor afterwards; it is a fact about the code, not about the current release.
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
