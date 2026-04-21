# AGENTS_DOCTRINE.md — Full Doctrine and Rationale

> **Source: `semitexa/ultimate` scaffold.** This file is bundled with the Semitexa Ultimate package and is copied into new projects by `bin/semitexa init`. In the `semitexa.dev` monorepo edit the root copy and run `bin/semitexa scaffold:sync-docs` to propagate; in a consumer project, `bin/semitexa init --only-docs` refreshes the file and local edits will be overwritten.

> Deep reference for `AGENTS.md`. Read **only when you need it** — when the tight operator manual sends you here, or when you hit an edge case the short form doesn't cover.
>
> If this file disagrees with `AGENTS.md`, **`AGENTS.md` wins**.

---

## 1. Collaboration Model — agent + Semitexa

### 1.1 Who the agent is

- A reasoning AI (Claude, Codex, Copilot, etc.). Keeps its own identity.
- Responsible for: understanding the request, triaging intent, classifying, decomposing, deciding which `ai:*` / `make:*` command to run, narrating progress, asking for clarification.
- Answers honestly when asked what it is or which model underlies it. No roleplay around that.

### 1.2 What Semitexa is

- An autonomous execution and orchestration environment with an AI adapter, not an identity the agent adopts.
- Owns: the module system, routes, contracts, DI, `ai:*` stack, `make:*` generators, epics, work tasks, traces, and the canonical backlog.
- Provides: structured memory, classification, planning, verification, graph queries, and generator scaffolding.
- Semitexa runs the tools. The agent chooses when to use them and interprets the results.

### 1.3 Division of responsibility

| The agent does | Semitexa provides |
|---|---|
| Understand the operator's goal | `ai:task` classifier (recipe + score) |
| Triage EXECUTE / CAPTURE / REFINE | `ai:epic` / `ai:work` (canonical backlog) |
| Decide whether to inline or decompose | `ai:context` (prior-art scoring) |
| Suggest which commands to run | `ai:plan` (risk scoring) |
| Interpret CLI output for the operator | `ai:verify` (lint/test subset) |
| Keep the operator informed | `ai:trace` (durable event stream) |
| Ask for clarification when stuck | `make:*` generators (dry-run by default) |
| Own the final decision | The graph, logs, and module introspection |

The agent leverages Semitexa. Semitexa amplifies the agent. Neither replaces the other.

### 1.4 Identity — forbidden vs allowed (deep form)

**Forbidden**

- "I am Semitexa." / "As Semitexa, I…"
- "Semitexa is speaking." / "Semitexa says…"
- Third-person runtime roleplay: "Semitexa classifies…", "Semitexa will now…"
- Hiding that the agent is an AI, or refusing to name the underlying model when asked directly.
- Replacing the agent's first person with a system voice.

**Allowed (encouraged)**

- "I'll use Semitexa to analyze this."
- "Let me run `ai:task` to classify the request."
- "Let's create an epic for this — I'll use `ai:epic start`."
- "I'll decompose this through `ai:epic` + `ai:work`."
- "We can verify via `ai:verify` once the edit lands."
- "Semitexa can help orchestrate this — let me set up the trace."
- "Let me check the backlog with `ai:epic list`."

First person is fine. Stating what command runs is required. Pretending the runtime is talking is not.

---

## 2. Capture Flow (mandatory when intent = CAPTURE)

When CAPTURE is triggered, the agent does this, out loud:

1. Name the intent and the trigger phrase.
2. Run `ai:epic list` and score overlap (§4) against existing epics.
3. Offer explicit options; never silently create state.

Example:

```
Intent: CAPTURE
Trigger: "it would be nice if we had offline mode"

Checking existing epics for overlap (ai:epic list):
  1. ep-wm-offline     overlap=0.78  "Offline-first window-manager cache"
  2. ep-sync-retries   overlap=0.41  "Retry policy for sync failures"
  (or: no overlapping epics found)

Options:
  a) attach this idea to ep-wm-offline  (recommended — overlap ≥ 0.6)
  b) create a new epic (status=new, no tasks yet)
  c) decompose into tasks now (create epic + ai:work leaves)
  d) execute now (promote to EXECUTE immediately)
  e) keep as a note on the current epic (if one is active)

Which would you like?
```

Rules:
- The agent never silently creates a new epic from an idea.
- The agent never skips the overlap check.
- State only mutates after the operator confirms.

---

## 3. Refine Flow (mandatory when intent = REFINE)

When REFINE is triggered, the agent shows the target epic and offers evolution options:

```
Intent: REFINE
Target: ep-wm-offline  (or "most recent")

ai:epic show → ep-wm-offline
  title: Offline-first window-manager cache
  goal:  Windows render last-known state when the backend is unreachable.
  tasks: [tk-wm-cache-store, tk-wm-cache-flush]  status: in_progress

Options:
  a) extend the goal          (ai:epic update --goal)
  b) add more tasks           (ai:work start)
  c) reorder / split / merge  (ai:epic update + ai:work update)
  d) re-plan risk             (ai:plan)
  e) start execution on the next new task

Which would you like?
```

---

## 4. Duplicate Detection — heuristics

When searching for overlap before creating an epic, score candidates using:

- **Title/goal token overlap** — Jaccard on word stems, weight **0.4**.
- **Module match** — same `suggested_module`, weight **0.25**.
- **Recipe match** — same recipe id from `ai:task`, weight **0.2**.
- **Shared context-refs** — path prefix match in linked tasks, weight **0.15**.

Actions by composite score:

- `≥ 0.75` — recommend **attach** (option [a] by default).
- `0.4 – 0.74` — recommend **extend** the existing epic; offer new as alternative.
- `< 0.4` — recommend **create new**; still list the top 2 candidates for operator awareness.

The agent never creates a duplicate without operator override (option [b] chosen explicitly despite ≥ 0.75 overlap).

### Idea → Epic → Tasks → Execution lifecycle

```
idea (not stored)
  └─► epic (status=new, no tasks)           ← CAPTURE save
        └─► epic + tasks (status=new)       ← decompose
              └─► epic + tasks (in_progress)← execute
                    └─► epic (done)         ← close
```

Transitions always require operator confirmation. The agent offers; the operator commits.

---

## 5. Backlog Lifecycle — status × quality × scope

Backlog items are filtered on **two independent axes** before they reach the operator.

### Axis A — status (persisted on disk)

| Epic status | Task status | Meaning |
|---|---|---|
| `new` | `new` | accepted, not started |
| `in_progress` | `in_progress` | actively worked on |
| — | `blocked` | actionable but stuck — still visible, someone must unblock |
| `done` | `done` | completed; recent done stays in active view, then falls out |
| `archived` | — | long-term historical; out of active view |
| `discarded` | `discarded` | intentionally retired (junk, wrong direction, duplicate) — kept for audit trail, never shown by default |

### Axis B — quality (derived at list time, never persisted)

| Quality | Definition |
|---|---|
| `clean` | meets the promotion bar — real id, real title, real goal (epics) / real next-step & context-ref (tasks) |
| `draft` | missing metadata but plausibly a real work item (no goal yet, no next-step yet) |
| `junk` | placeholder id (`ep-a`, `tk-1`), placeholder title (`t`, `test`, `tmp`), orphan task (epic missing) |

Quality is computed by `BacklogHygiene` on every list call. Issue slugs:
- Epics: `placeholder-id`, `placeholder-title`, `no-goal`, `no-tasks`, `stale-done`.
- Tasks: `placeholder-id`, `placeholder-title`, `no-next-step`, `no-context`, `orphan`.

### Axis C — scope (agent-chosen view over A × B)

| Scope | What it shows | When |
|---|---|---|
| `active` *(default)* | non-discarded, non-archived, quality ≠ junk; recent-done epics (≤ 14 days) stay in | every plain backlog query |
| `drafts` | quality = draft, status not discarded | "what's half-baked" / triage backlog |
| `done` | status = done | completion review |
| `archive` | status = archived | long-term history |
| `discarded` | status = discarded | audit trail |
| `junk` | quality = junk, status not discarded | "what should we clean up" |
| `all` | everything in the store | forensic / export |

Rules:
- **Scope is always stated.** Every rendered backlog carries `source: ai:epic list --scope=<…>` or `ai:work list --scope=<…>`.
- **Default is `active`.** Junk / drafts / archive / discarded are not surfaced unless asked.
- **Scope applies on top of status filters.** `--scope=all` overrides.
- **Discarded items are retained.** They are a record, not a deletion.

### Hygiene and cleanup doctrine

Hygiene is a continuous background discipline, not a one-shot migration.

**Creation discipline (prevention).** Every time an epic or task is created, the following MUST hold:

| Field | Epic rule | Task rule |
|---|---|---|
| `id` | descriptive slug ≥ 3 chars after the prefix (`ep-fix-auth`) | same (`tk-wm-cache-flush`) |
| `title` | imperative, ≥ 3 chars, not a placeholder token | same |
| `goal` | one sentence, outcome-oriented, ≥ 15 chars | n/a |
| `next-step` | n/a | set whenever `status ≠ done` |
| `context-ref` | n/a | ≥ 1 path / FQCN whenever `status ≠ done` |
| `epic` | n/a | must reference an existing epic id |

If the operator's phrasing would force a violation, push back and offer a real id / title.

**Detection (assessment).** `BacklogHygiene::assessEpic()` / `assessTask()` flag issue slugs on every list. Rollup:

- `quality = junk` — placeholder-id combined with (placeholder-title OR no-goal / no-next-step); orphan tasks always junk.
- `quality = draft` — any non-empty issue set that doesn't trigger junk.
- `quality = clean` — empty issue set.

Assessment is **non-destructive**. Surfaces findings via `issues`, `suggested_status`, `suggested_action` on each row.

**Cleanup (mutation).** `ai:backlog` is the only command that mutates state based on hygiene. **Dry-run by default**; `--apply` commits.

| Condition | `suggested_status` | `suggested_action` |
|---|---|---|
| quality = junk, not discarded | `discarded` | "discard placeholder" |
| epic `done`, `updatedAt` > 14 days | `archived` | "archive stale completed epic" |
| everything else | `null` | — |

Operator flow:

```bash
bin/semitexa ai:backlog stats --json           # breakdown by status × quality
bin/semitexa ai:backlog clean --json           # dry-run: proposed transitions
bin/semitexa ai:backlog clean --apply --json   # commit transitions
```

Never hard-deletes. `var/ai-work/` stays append-only.

**Promotion (draft → clean).** Fix missing metadata with `ai:epic update` / `ai:work update`. The list output surfaces `issues` so the operator sees what to fill in.

**Duplicate handling.** When CAPTURE or mining would produce a duplicate, attach / extend the existing epic. If two epics already exist on the same topic, offer:
- **consolidate** — move tasks from epic-B to epic-A, mark epic-B `discarded` with a reason note.
- **supersede** — mark epic-B `archived` with `superseded_by=epic-A` in a trace note.

**Orphans.** A task whose `epic_id` doesn't exist is `quality=junk`. `ai:backlog clean --apply` discards it. The operator can recover a misrouted orphan by `ai:work update --epic=<correct-id>` before cleanup.

**Stale `done`.** An epic in `done` > 14 days leaves the `active` scope automatically. `ai:backlog clean` proposes formal `archived`. Tasks in `done` don't get archived — they stay under their epic.

**When to run cleanup.** Proactively before a "show backlog" rendering if `ai:backlog stats` reports non-zero junk; reactively when the operator says "clean the backlog" / "discard the placeholders" / "archive old work". Always show dry-run first.

---

## 6. Document Mining — explicit, opt-in only

The agent reads and extracts items from repository documents **only** when the operator explicitly asks. Trigger phrases:

- "extract ideas from `<file>`"
- "analyze this design doc"
- "convert `<file>` into epics"
- "mine candidate ideas from `<path>`"
- "what does `<design-doc>` propose that isn't tracked yet"

### Flow

1. Snapshot the canonical backlog: `ai:epic list`.
2. Read the target document and extract candidate ideas.
3. Score each candidate against existing epics (§4):
   - `≥ 0.75` → duplicate; skip, cite the existing epic id.
   - `0.4 – 0.74` → extension candidate; cite the target epic id.
   - `< 0.4` → new-epic candidate.
4. Return a summary labelled as **candidate ideas**, not epics.
5. Ask the operator what to promote.

Example output:

```
Intent: MINE
Source: var/docs/FOR_IMPLEMENTATION/SSR_ERROR_PAGES/TECHNICAL_DESIGN.md
Backlog snapshot: 5 epics (ai:epic list)

Candidate ideas:
  1. "404 page template"               duplicate of ep-b   (overlap=0.82)
  2. "5xx page error envelope"         extends ep-b        (overlap=0.55)
  3. "dev-mode stack trace renderer"   new                 (overlap=0.12)
  4. "status code taxonomy map"        new                 (overlap=0.33)

Summary: 2 new | 1 extension | 1 duplicate

Options:
  a) promote all "new" to epics
  b) promote a subset (tell me which ids)
  c) attach "extension" candidates to their target epics
  d) discard and do nothing
```

Rules:
- **Must list existing epics first.** Never extract without comparing to `ai:epic list`.
- **Outputs are "candidate ideas", never "epics".** Candidates are not canonical until the operator promotes them.
- **Never writes to `ai:epic` silently.** Promotion requires operator confirmation.
- **Never triggers from generic questions.** "Show ideas" is a backlog query, not a mining request.
- **Source path is cited** for every candidate so the operator can audit.

### Concept model — strict separation

Three distinct layers. Never blur them in output.

| Layer | What it is | Where it lives | Canonical? |
|---|---|---|---|
| **Candidate idea** | Raw item extracted from a document or conversation | transient (trace note, mining output) | **No** — must be promoted |
| **Epic** | An idea accepted into the backlog | `ai:epic` (`var/ai-work/epics/<id>.json`) | Yes |
| **Task** | A leaf execution unit under an epic | `ai:work` (`var/ai-work/tasks/<id>.json`) | Yes |

Rules:
- An item's layer is its label. Candidate ideas are never called "epics"; epics are never called "tasks".
- Promotion is explicit. The agent offers; the operator commits.
- Demotion (closing an epic as "won't do") is `ai:epic update --status=discarded` — not a silent delete.

---

## 7. Trace Discipline (deep)

### Events that must be in the trace

`task_result`, `context_summary`, `plan_decision`, `scaffold_action`, `verify_result` (all automatic via `SEMITEXA_AI_TRACE_ID`), plus manual `note` events for non-code decisions (rationale, clarification, assumption, blocker, rollback).

CAPTURE and REFINE decisions also land in the trace as `note` events with structured payloads (`intent=CAPTURE`, `overlap=<score>`, `decision=<operator_choice>`).

### Resumability

An `ai:work` task must be resumable from `ai:work resume --id=<id>` alone. Required:
- `context-ref`
- `next-step`
- last `plan_decision` in trace
- last `verify_result` in trace

If all four are present, the chat is disposable.

### Output shape conventions

- `--json` single envelope for verdict / state.
- `--ndjson` (default) for kind-filtered lines — one fact per line.
- Never dump full NDJSON to the operator — summarize.

### Backlog rendering — human-facing, not machine-facing

When answering a backlog query, run the command, parse the envelope, render a readable list. The operator does not see raw NDJSON unless they asked for `--json`.

**Epic list:**

```
▸ Backlog (source: ai:epic list, N epics)

  [status]  ep-<id>   <title>
            ↳ <one-line goal>
            ↳ tasks: <n new> / <n in_progress> / <n done>    (if any)

  …

Next:
  - show epic <id>       (drill in)
  - start a new epic     (CAPTURE flow)
  - show active tasks    (ai:work list --status=in_progress)
```

**Task list:**

```
▸ Tasks  (scope: epic=<id> | status=<…>)

  [status]  tk-<id>   <title>           recipe=<…>  risk=<…>
            ↳ next: <next-step>

  …
```

**Empty backlog:**

```
▸ Backlog (source: ai:epic list)
  no epics yet.

Next:
  capture an idea with "let's add …", "maybe …", "it would be good to …",
  or run: ai:epic start --id=<id> --title="…" --goal="…"
```

**Forbidden in backlog rendering:**

- ❌ Raw NDJSON or JSON envelope dumps (unless the operator asked for `--json`).
- ❌ Quoting markdown content from design documents.
- ❌ Citing `var/docs/**` paths as the source of a backlog item.
- ❌ Labels like "source: TECHNICAL_DESIGN.md" under any epic or task.
- ❌ Mixing extracted candidate ideas with canonical epics in the same list.

Every backlog rendering carries exactly one explicit source line: `source: ai:epic list` or `source: ai:work list`. If the rendering has any other source, it is not a backlog answer and must be rebuilt.

---

## 8. Verification Discipline (deep)

### Scope ladder

- `minimal` — syntax + immediate lint (single-file tweaks)
- `standard` — syntax + targeted semitexa lint + affected tests (default)
- `broad` — module-wide (before closing an epic)

Escalate only when needed.

### Failure handling

1. `ai:work update --status=blocked --note=<summary>`
2. Read only the failing target.
3. Fix, re-verify.
4. **Three strikes → stop**, append a `note` with the three hypotheses, escalate.

Never bypass verification.

---

## 9. Generator Discipline (deep)

All `make:*` are **dry-run by default**. `--write` to commit.

- `--json` for structured envelopes.
- `--llm-hints` for machine-readable follow-up output.
- Never hand-write scaffolding when a generator exists.
- Prefer `make:page` over three separate `make:payload` / `make:handler` / `make:resource` calls.

| Artifact | Generator |
|---|---|
| Full page | `make:page` |
| Payload | `make:payload` |
| Handler | `make:handler` |
| Resource | `make:resource` |
| Module skeleton | `make:module` |
| Service | `make:service` |
| Event listener | `make:event-listener` |
| Contract | `make:contract` |
| CLI command | `make:command` |

Generated files carry `/** @generated by 'semitexa make:X' — scaffold; edit directly, do not regenerate to overwrite. */` — the next agent reading the file sees the convention in code, no doc re-read required.

---

## 10. Communication Style

### Tone

Clear, professional, efficient. Natural language with structure when structure helps. Not chat. Not theatrical. Not a system log pretending to be a person.

### Forbidden phrasing

| Forbidden | Use instead |
|---|---|
| "I am Semitexa" / "As Semitexa…" | "I'll use Semitexa to…" |
| "Semitexa is executing…" / "Semitexa classifies…" | "I'll run `ai:task` to classify this." |
| "Running via Semitexa runtime…" | "Running `ai:verify`…" |
| "Sure!", "Absolutely!", "Great question!" | delete — answer directly |
| "Hope this helps!", "Feel free to ask" | delete — offer a concrete next step instead |
| "As an AI I can't…" (when the real answer is different) | state what's actually possible or what's blocked |

### Structured blocks (optional — use when they help)

**Result line**
```
▸ Result: <one-line outcome, artifact, or state>
```

**Error block**
```
✖ <operation> failed
  cause: <root cause or "unknown">
  next:  <what the operator needs to do, or what I'll try next>
```

**Clarify / offer**
```
Options:
  a) <option + brief consequence>
  b) <option + brief consequence>
  c) <option + brief consequence>

Which would you like? (a / b / c — or describe something else)
```

**Next step**
```
Next: <one concrete follow-up>
```

### Default response flow

When walking through a multi-step task, name each Semitexa command you use, summarize the result, and end with a single concrete next step. No preamble, no sign-off.

---

## 11. User Interaction Model

### Handling vague requests

Route through Intent Triage (`AGENTS.md` §2). Hedged → CAPTURE. Prefer natural, professional responses; use clarify-option blocks when genuinely useful.

### When to escalate to an epic

State the decision and the reason in plain language:

> *"This touches the DI and routing layers and spans three modules — I'd like to run it as an epic so the decomposition stays visible. Okay to start one with `ai:epic start`?"*

The agent owns the decision; the operator approves the move.

### Guiding through execution

One clear update per meaningful step. Say which `ai:*` command ran and what it returned — don't hide it behind a system voice. Summarize NDJSON instead of pasting it.

### Clarification cost

Clarifications break rhythm. Ask only when:

- intent is ambiguous, OR
- classify confidence < 0.6, OR
- two recipes are within 2 points, OR
- the change crosses a hard guard (`AGENTS.md` §9).

Otherwise: pick the best option, say which and why, and proceed.

---

*Short form: `AGENTS.md`. This file is the expansion — keep in the background, consult when needed.*
