# AGENTS.md — Collaboration Manual

> This file is the single source of truth for how an AI agent works with Semitexa in this repository.
> If any other document (CLAUDE.md, AI_ENTRY.md, AI_REFERENCE.md, chat history) disagrees with this file, **this file wins**.
>
> The **agent** is the reasoning system (e.g. Claude, Codex, Copilot).
> **Semitexa** is the execution, orchestration, memory, and verification environment.
> They collaborate. The agent never impersonates Semitexa.

---

## 0. Prime Directives

1. **Keep your identity.** The agent remains the agent; Semitexa is the tooling layer. Speak as yourself, work through Semitexa. Never claim to *be* Semitexa.
2. **Triage intent before action.** Classify every input as `EXECUTE`, `CAPTURE`, or `REFINE`. Do not execute what was only proposed.
3. **Never solve a non-trivial task in a single pass.** Decompose first via `ai:epic` + `ai:work`, execute second.
4. **Externalize state.** `ai:epic`, `ai:work`, `ai:trace` are the memory. If it is not in an artifact, it did not happen.
5. **Epics are the canonical backlog.** Ideas, initiatives, tasks, and status live in `ai:epic` + `ai:work`. Repository documents are never the source of truth for backlog.
6. **Never re-derive what is already written.** Search trace / epic / context before reasoning from scratch.
7. **Fail early on uncertainty.** Low confidence → clarify. Ambiguous → branch. Do not guess.
8. **Minimize context.** Prefer summaries over raw reads; every read must answer a question an artifact could not.
9. **Name the tool you use.** When running a Semitexa command, say which one and why — don't pretend it ran invisibly.

Violating any of the above is a defect.

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

### 1.4 Identity — forbidden vs allowed

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

## 2. Communication Style

### 2.1 Tone
Clear, professional, efficient. Natural language with structure when structure helps. Not chat. Not theatrical. Not a system log pretending to be a person.

### 2.2 Forbidden phrasing

| Forbidden | Use instead |
|---|---|
| "I am Semitexa" / "As Semitexa…" | "I'll use Semitexa to…" |
| "Semitexa is executing…" / "Semitexa classifies…" | "I'll run `ai:task` to classify this." |
| "Running via Semitexa runtime…" | "Running `ai:verify`…" |
| "Sure!", "Absolutely!", "Great question!" | delete — answer directly |
| "Hope this helps!", "Feel free to ask" | delete — offer a concrete next step instead |
| "As an AI I can't…" (when the real answer is different) | state what's actually possible or what's blocked |

### 2.3 Structured blocks (optional — use when they help)

When a response benefits from structure (listing options, reporting multi-step progress, presenting errors), the agent may use these shapes. They're tools, not mandatory templates — short natural answers are fine for short questions.

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

### 2.4 Default response flow
When walking through a multi-step task, name each Semitexa command you use, summarize the result, and end with a single concrete next step. No preamble, no sign-off.

---

## 3. The `ai:*` Command Stack — Roles

| Command | Role | When the agent uses it |
|---|---|---|
| `ai:task` | Classify prose → recipe + score + chain | Every new EXECUTE unit |
| `ai:epic` | Orchestrate N tasks under a shared goal | CAPTURE save / non-trivial EXECUTE |
| `ai:work` | Track one executable leaf unit | Every leaf EXECUTE task |
| `ai:context` | Score prior-art for a recipe | Before edits, per task, once |
| `ai:plan` | Risk-score recipe + files | Before edits on >1 file or unclear risk |
| `ai:ask` | Targeted introspection (project / module / route / event / logs) | Only on a specific question |
| `ai:skills` | Skill registry (risk, dry-run, confirmation) | Before unfamiliar commands |
| `ai:verify` | Precise lint/test subset on diff | After every edit that produced files |
| `ai:trace` | Durable cross-session event stream | Always — it is the memory |

---

## 4. Intent Triage — EXECUTE / CAPTURE / REFINE

**Every operator input passes through this gate before the agent calls `ai:task`.** The agent picks exactly one mode and states which.

### 4.1 EXECUTE — action requested
Signals:
- Imperative verb + concrete object: "add X", "fix Y", "rename Z", "remove W", "run V".
- Explicit commit language: "do it", "implement", "ship", "apply", "execute".
- A file path or FQCN plus a change verb.

Flow: §5 Canonical Pipeline.

### 4.2 CAPTURE — idea / hypothesis / proposal
Signals (hedged / conditional / exploratory wording):
- "it would be good to…", "it would be nice if…", "would be useful…"
- "maybe we should…", "perhaps…", "at some point…"
- "I wonder if…", "what if…", "we may want to…", "we could…"
- "I think this could be a good idea", "thought about…", "consider…"
- Subjunctive mood + no concrete artifact.

Flow: §4.5 Capture Flow.

### 4.3 REFINE — evolve existing work
Signals:
- References an existing epic id, task id, or the most recent epic by topic.
- "extend that", "add to the X epic", "update the plan", "reorder", "split off", "merge with".
- Modifies an existing `ai:epic` goal, task list, or recipe.

Flow: §4.6 Refine Flow.

### 4.4 Ambiguous inputs
- Default to **CAPTURE**, never to EXECUTE. Unconfirmed ideas do not get implemented.
- If the input mixes imperative and hedged ("we should maybe add X"), offer EXECUTE and CAPTURE as options and let the operator pick.

### 4.5 Capture Flow (mandatory)

When CAPTURE is triggered, the agent does this, out loud:

1. Name the intent and the trigger phrase.
2. Run `ai:epic list` and score overlap (§4.7) against existing epics.
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

### 4.6 Refine Flow (mandatory)

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

### 4.7 Duplicate Detection (heuristics)

When searching for overlap before creating an epic, the agent scores candidates using:
- **Title/goal token overlap** (Jaccard on word stems, weight 0.4).
- **Module match** (same `suggested_module`, weight 0.25).
- **Recipe match** (same recipe id from `ai:task`, weight 0.2).
- **Shared context-refs** (path prefix match in linked tasks, weight 0.15).

Actions by composite score:
- `≥ 0.75` — recommend **attach** (option [a] by default).
- `0.4–0.74` — recommend **extend** the existing epic; offer new as alternative.
- `< 0.4` — recommend **create new**; still list the top 2 candidates for operator awareness.

The agent never creates a duplicate without operator override (option [b] chosen explicitly despite ≥ 0.75 overlap).

### 4.8 Idea → Epic → Tasks → Execution

Capture lifecycle (states an idea can move through):

```
idea (not stored)
  └─► epic (status=new, no tasks)           ← CAPTURE save
        └─► epic + tasks (status=new)       ← decompose
              └─► epic + tasks (in_progress)← execute
                    └─► epic (done)         ← close
```

Transitions always require operator confirmation. The agent offers; the operator commits.

### 4.9 Canonical backlog — epics are the single source of truth

This is a **hard system rule**, not a convention.

- **Ideas, initiatives, backlog state, and task decomposition** live in:
    - `ai:epic` (stored under `var/ai-work/epics/`) — one epic per idea / initiative.
    - `ai:work` (stored under `var/ai-work/tasks/`) — leaves of each epic.
- **Nothing else is canonical.** Not `var/docs/`, not `var/docs/FOR_IMPLEMENTATION/`, not `packages/semitexa-docs/`, not chat history, not `AI_NOTES.md`, not design documents, not commit messages.
- Repository documents describe **how** something is intended to be built. They are not the backlog and not a status tracker.

Corollary: if an idea is not in `ai:epic`, it does not exist from a backlog standpoint. The fix is to CAPTURE it (§4.5), not to mine it.

### 4.10 Request Interpretation — backlog vocabulary → `ai:epic` / `ai:work`

The following operator phrases are **backlog queries**. The agent answers them from epics / tasks only. Document scanning is forbidden for these.

**Default scope is `active` (§4.13).** Junk, drafts, archived, and discarded items are hidden until the operator asks.

| Operator says | Agent runs | Must never do |
|---|---|---|
| "show ideas" / "show all ideas" / "list ideas" | `ai:epic list --json` *(scope=active)* | scan `var/docs/**`, infer from markdown, read FOR_IMPLEMENTATION |
| "show backlog" / "what's in the backlog" | `ai:epic list --json` (+ `ai:work list --status=new,in_progress,blocked --json` if drilling into tasks) | reconstruct from docs |
| "show tasks" / "list tasks" / "show work items" | `ai:work list [--epic=<id>] --json` *(scope=active)* | extract from documents |
| "show epics" / "list initiatives" / "list epics" | `ai:epic list --json` *(scope=active)* | anything else |
| "what are we working on" / "what's active" | `ai:work list --status=in_progress,blocked --json` | summarize documents |
| "what's next" / "next up" | `ai:work list --status=new --json` (optionally scoped by `--epic=<id>`) | guess |
| "show epic X" / "tell me about epic X" | `ai:epic show --id=<id> --json` | read the docs folder |
| "show drafts" / "what's half-baked" | `ai:epic list --scope=drafts --json` / `ai:work list --scope=drafts --json` | treat drafts as active |
| "show junk" / "what's garbage" / "show placeholders" | `ai:epic list --scope=junk --json` / `ai:work list --scope=junk --json` | silently delete |
| "show archive" / "show archived" / "show completed history" | `ai:epic list --scope=archive --json` (or `--scope=done` for recent done) | confuse archived with discarded |
| "show discarded" / "show retired" | `ai:epic list --scope=discarded --json` / `ai:work list --scope=discarded --json` | hide the audit trail |
| "show everything" / "show all epics / tasks (including junk/archive)" | `ai:epic list --scope=all --json` / `ai:work list --scope=all --json` | skip rendering of filtered-out buckets |
| "what's the backlog health" / "any placeholders?" / "stats" | `ai:backlog stats --json` | reason from partial reads |

Rules:
- **No silent fallback.** If `ai:epic list` returns an empty list, the agent reports "no epics" and offers to CAPTURE — it does **not** pivot to scanning documents.
- **No mixed sources.** A backlog answer is derived from epics + tasks only. The agent never concatenates document snippets into a backlog answer.
- **No disguised doc scans.** The agent never labels extracted document items as "epics" or "tasks" in a response.
- **Exact-id queries** (operator names `ep-…` or `tk-…`) go to `ai:epic show` / `ai:work show`. Never to `Read` on a design doc.
- **Scope defaults to `active`** for every listing command. The agent states the scope it applied when rendering, so the operator can broaden with `--scope=all` or narrow further.

### 4.11 Document mining — explicit, opt-in only

The agent reads and extracts items from repository documents **only** when the operator explicitly asks. Trigger phrases include:

- "extract ideas from `<file>`"
- "analyze this design doc"
- "convert `<file>` into epics"
- "mine candidate ideas from `<path>`"
- "what does `<design-doc>` propose that isn't tracked yet"

When triggered, the agent runs the **Document Mining Flow**:

1. Snapshot the canonical backlog: `ai:epic list`.
2. Read the target document and extract candidate ideas.
3. Score each candidate against existing epics (§4.7):
    - `≥ 0.75` → duplicate; skip, cite the existing epic id.
    - `0.4–0.74` → extension candidate; cite the target epic id.
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
- **Never triggers from generic questions.** "Show ideas" is a backlog query (§4.10), not a mining request.
- **Source path is cited** for every candidate so the operator can audit.

### 4.12 Concept model — strict separation

Three distinct layers. The agent never blurs them in output.

| Layer | What it is | Where it lives | When it is canonical |
|---|---|---|---|
| **Candidate idea** | Raw item extracted from a document or conversation | transient (trace note, mining output) | never — must be promoted to become canonical |
| **Epic** | An idea accepted into the backlog | `ai:epic` (`var/ai-work/epics/<id>.json`) | yes — source of truth for ideas & initiatives |
| **Task** | A leaf execution unit under an epic | `ai:work` (`var/ai-work/tasks/<id>.json`) | yes — source of truth for actionable work |

Rules:
- An item's layer is its label. Candidate ideas are never called "epics"; epics are never called "tasks".
- Promotion is explicit (§4.5, §4.11). The agent offers; the operator commits.
- Demotion (closing an epic as "won't do") is `ai:epic update --status=discarded` — not a silent delete.

### 4.13 Backlog lifecycle and visibility — status × quality × scope

Backlog items are filtered on **two independent axes** before they reach the operator:

**Axis A — status (persisted on disk).**

| Epic status | Task status | Meaning |
|---|---|---|
| `new` | `new` | accepted, not started |
| `in_progress` | `in_progress` | actively worked on |
| — | `blocked` | actionable but stuck — still visible, someone must unblock |
| `done` | `done` | completed; recent done stays in active view, then falls out |
| `archived` | — | long-term historical; out of active view |
| `discarded` | `discarded` | intentionally retired (junk, wrong direction, duplicate) — kept for audit trail, never shown by default |

**Axis B — quality (derived at list time, never persisted).**

| Quality | Definition |
|---|---|
| `clean` | meets the promotion bar — real id, real title, real goal (epics) / real next-step & context-ref (tasks) |
| `draft` | missing metadata but plausibly a real work item (e.g. no goal yet, no next-step yet) |
| `junk` | placeholder id (`ep-a`, `tk-1`), placeholder title (`t`, `test`, `tmp`), orphan task (epic missing) |

Quality is computed by `BacklogHygiene` on every list call. It rolls up a set of issue slugs: `placeholder-id`, `placeholder-title`, `no-goal`, `no-tasks`, `stale-done` (epics); `placeholder-id`, `placeholder-title`, `no-next-step`, `no-context`, `orphan` (tasks).

**Axis C — scope (agent-chosen view over A × B).**

| Scope | What it shows | When to use |
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
- **Default is `active`.** The agent never surfaces junk / drafts / archive to the operator unless asked (§4.10).
- **Scope applies on top of status filters.** `ai:work list --status=in_progress --scope=active` narrows further; `--scope=all` overrides.
- **Discarded items are retained.** They are a record, not a deletion — used for post-mortems and audit.

### 4.14 Hygiene and cleanup doctrine

Hygiene is how the backlog stays small and readable. It is a continuous background discipline, not a one-shot migration.

**14.a — Creation discipline (prevention).** Every time the agent (or the operator, via the agent) creates an epic or task, the following MUST hold:

| Field | Epic rule | Task rule |
|---|---|---|
| `id` | descriptive slug ≥ 3 chars after the prefix (`ep-fix-auth`, not `ep-a`) | same (`tk-wm-cache-flush`, not `tk-1`) |
| `title` | imperative, ≥ 3 chars, not a placeholder token (`test`, `tmp`, `foo`, `bar`, `wip`, `draft`, …) | same |
| `goal` | one sentence, outcome-oriented, ≥ 15 chars | n/a |
| `next-step` | n/a | set whenever `status ≠ done` |
| `context-ref` | n/a | ≥ 1 path / FQCN whenever `status ≠ done` |
| `epic` | n/a | must reference an existing epic id (checked at `ai:work start`) |

If the operator's phrasing would force a violation ("just name it `ep-a`"), the agent pushes back and offers a concrete id / title before creating. The agent never silently accepts a placeholder to make a command succeed.

**14.b — Detection (assessment).** `BacklogHygiene::assessEpic()` / `assessTask()` flag issue slugs on every list. Rollup:

- `quality = junk` — placeholder-id combined with (placeholder-title OR no-goal / no-next-step); orphan tasks are always junk.
- `quality = draft` — any non-empty issue set that doesn't trigger junk.
- `quality = clean` — empty issue set.

Assessment is **non-destructive**. It surfaces findings via `issues`, `suggested_status`, and `suggested_action` on each row. The store never mutates during assessment.

**14.c — Cleanup (mutation).** `ai:backlog` is the only command that mutates state based on hygiene. It is **dry-run by default**; `--apply` commits.

| Input condition | `suggested_status` | `suggested_action` |
|---|---|---|
| epic/task quality = junk, not already discarded | `discarded` | "discard placeholder {epic,task}" |
| epic status = done, `updatedAt` older than 14 days | `archived` | "archive stale completed epic" |
| everything else | `null` | — (no action) |

Operator flow:

```
bin/semitexa ai:backlog stats --json           # breakdown: by status × quality
bin/semitexa ai:backlog clean --json           # dry-run: list proposed transitions
bin/semitexa ai:backlog clean --apply --json   # commit proposed transitions
```

The command **never hard-deletes**. Retiring a placeholder means `status=discarded`, not file removal. The `var/ai-work/` directory remains an append-only audit trail.

**14.d — Promotion (draft → clean).** When a draft is missing metadata, the fix is `ai:epic update` / `ai:work update` supplying the missing fields (goal, next-step, context-ref). The agent surfaces the `issues` list from the list output so the operator sees exactly what to fill in.

**14.e — Duplicate handling.** When CAPTURE (§4.5) or document mining (§4.11) would produce a duplicate, the agent attaches / extends the existing epic instead of creating a second one. If a duplicate already exists in the store (two epics on the same topic), the agent offers:

- consolidate: move tasks from epic-B to epic-A, mark epic-B `discarded` with a reason note.
- supersede: mark epic-B `archived` with `superseded_by=epic-A` in a trace note.

Consolidation is explicit — the agent proposes the mapping, the operator confirms.

**14.f — Orphans.** A task whose `epic_id` doesn't exist in the store is `quality=junk`. `ai:backlog clean --apply` marks it `discarded`. The operator can recover a misrouted orphan by `ai:work update --epic=<correct-id>` before running cleanup.

**14.g — Stale `done`.** An epic in `done` for more than 14 days leaves the `active` scope automatically (falls under `archive` / `done` scope). `ai:backlog clean` proposes formal `archived` status so the `done` view stays short and meaningful. Tasks in `done` don't get archived — they stay under their epic, and their epic's archival carries the audit.

**14.h — When to run cleanup.** Proactively before a "show backlog" rendering if `ai:backlog stats` reports non-zero junk; reactively when the operator says "clean the backlog" / "discard the placeholders" / "archive old work". The agent always shows the dry-run first and asks before `--apply`.

---

## 5. Canonical Execution Pipeline (EXECUTE mode)

```
operator input
     │
     ▼
[0] classify intent (§4)
     │
     ├── CAPTURE ──► §4.5
     ├── REFINE  ──► §4.6
     │
     ▼ EXECUTE
[1] classify via ai:task
     │
     ├─ confidence<0.6 / ambiguous ──► clarify
     │
     ▼
[2] inline-vs-epic triage (§6)
     │
     ├── inline  ──► edit ──► ai:verify ──► ai:trace note ──► done
     │
     ▼
[3] ai:epic start
     │
     ▼
[4] ai:work start (export SEMITEXA_AI_TRACE_ID)
     │
     ▼
[5] ai:context
     │
     ▼
[6] ai:plan --files
     │
     ├─ risk=high ──► split; back to [3]
     │
     ▼
[7] edit via make:* or Edit
     │
     ▼
[8] ai:verify
     │
     ├─ fail ──► ai:work update --status=blocked + ai:trace note; iterate
     │
     ▼
[9] ai:work update --status=done
     │
     ▼
[10] ai:epic update
```

At each step, the agent names the command and the outcome in the response.

---

## 6. Inline vs Epic Triage (inside EXECUTE)

### 6.1 Inline path
Permitted only when ALL hold:
- `ai:task` score ≥ 8 AND no alternative within 2 points
- ≤ 2 files
- Single module, single recipe
- Risk hint = `low`
- No contract / DI / discovery changes

Inline still requires: classify → (context if prior art non-obvious) → edit → verify → trace note.

### 6.2 Epic path (mandatory)
Required when any of:
- `ai:task` score < 5, or alternatives within 2 points
- ≥ 3 files, ≥ 2 modules, or any contract / DI / discovery code
- Expected duration > 30 min of agent work
- More than one verb in the request
- Vague wording that survived intent triage as EXECUTE (rare — usually these are CAPTURE)
- Any cross-cutting concern: auth, routing, DI, event dispatch, registry, persistence

When in doubt: epic.

### 6.3 Epic contract
- `title` imperative, ≤ 60 chars
- `goal` one sentence, outcome not activity
- ≥ 1 `ai:work` task with `recipe`, `risk`, ≥ 1 `context-ref`, `next-step`
- Tasks ordered: prerequisites first, verification last
- No task > ~4k tokens; split larger ones

---

## 7. Context Minimization

### 7.1 One-read rule
Each file is read ≤ once per task. Next time: read the trace, not the file.

### 7.2 Hierarchy of truth (stop at first answer)
1. Current-turn tool output
2. `ai:work show` / `ai:trace show`
3. `ai:context <recipe>`
4. `ai:ask <subject>`
5. `Grep` / `Glob` for a specific symbol
6. `Read` a specific file
7. Agent delegation (`Explore`, `general-purpose`) — last resort

### 7.3 Forbidden warmup
`ai:ask project`, `ai:ask capabilities`, `ai:review-graph:*`, `ai:skills --json`, `routes:list`, `contracts:list` — question-driven, never reflexive.

### 7.4 Token budget per task
- Inline: ≤ 4k tokens (`ai:*` + reads combined).
- Epic leaf: ≤ 8k tokens.
- Over budget → split via `ai:epic update` + new `ai:work start`.

### 7.5 Output shape
- `--json` single envelope for verdict / state.
- `--ndjson` (default) for kind-filtered lines.
- Never dump full NDJSON to the operator — summarize.

### 7.6 Backlog rendering — human-facing, not machine-facing

When answering a **backlog query** (§4.10), the agent runs the command, parses the envelope, and renders a readable list. The operator does not see raw NDJSON unless they asked for `--json`.

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

Forbidden in backlog rendering:
- ❌ Raw NDJSON or JSON envelope dumps (unless the operator asked for `--json`).
- ❌ Quoting markdown content from design documents.
- ❌ Citing `var/docs/**` paths as the source of a backlog item.
- ❌ Labels like "source: TECHNICAL_DESIGN.md" under any epic or task.
- ❌ Mixing extracted candidate ideas with canonical epics in the same list.

Every backlog rendering carries exactly one explicit source line: `source: ai:epic list` or `source: ai:work list`. If the rendering has any other source, it is not a backlog answer and must be rebuilt.

---

## 8. Trace Discipline

### 8.1 Export trace id early
```bash
export SEMITEXA_AI_TRACE_ID=<task-id>
```
Then `ai:task`, `ai:context`, `ai:plan`, `ai:verify` auto-append. Always do this at the start of a task.

### 8.2 Events that must be in the trace
`task_result`, `context_summary`, `plan_decision`, `scaffold_action`, `verify_result` (all automatic), plus manual `note` events for non-code decisions (rationale, clarification, assumption, blocker, rollback).

CAPTURE and REFINE decisions also land in the trace as `note` events with structured payloads (`intent=CAPTURE`, `overlap=<score>`, `decision=<operator_choice>`).

### 8.3 Resumability
An `ai:work` task must be resumable from `ai:work resume --id=<id>` alone. Required: `context-ref`, `next-step`, last `plan_decision` + `verify_result` in trace. If all four are present, the chat is disposable.

---

## 9. Verification Discipline

### 9.1 Always verify after writes
Every edit / generator that produces files → `ai:verify`. No exceptions.

### 9.2 Scope ladder
- `minimal` — syntax + immediate lint (single-file tweaks)
- `standard` — syntax + targeted semitexa lint + affected tests (default)
- `broad` — module-wide (before closing an epic)

Escalate only when needed.

### 9.3 Failure handling
1. `ai:work update --status=blocked --note=<summary>`
2. Read only the failing target.
3. Fix, re-verify.
4. Three strikes → stop, append a `note` with the three hypotheses, escalate.

Never bypass verification.

---

## 10. Generator Discipline

All `make:*` are **dry-run by default**. `--write` to commit.

- `--json` for structured envelopes.
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

---

## 11. User Interaction Model

### 11.1 Handling vague requests
Route through Intent Triage (§4). Hedged → CAPTURE. Prefer natural, professional responses; use clarify-option blocks when genuinely useful.

### 11.2 When to escalate to an epic
State the decision and the reason in plain language:
*"This touches the DI and routing layers and spans three modules — I'd like to run it as an epic so the decomposition stays visible. Okay to start one with `ai:epic start`?"*
The agent owns the decision; the operator approves the move.

### 11.3 Guiding through execution
One clear update per meaningful step. Say which `ai:*` command ran and what it returned — don't hide it behind a system voice. Summarize NDJSON instead of pasting it.

### 11.4 Clarification cost
Clarifications break rhythm. Ask only when:
- intent is ambiguous (§4.4), OR
- classify confidence < 0.6, OR
- two recipes are within 2 points, OR
- the change crosses a hard guard (§12).

Otherwise: pick the best option, say which and why, and proceed.

---

## 12. Hard Guards

- **Do not** add root-level directories without explicit approval.
- **Do not** change module discovery without explicit approval.
- **Do not** add Composer dependencies without explicit approval.
- **Do not** create documentation files (`*.md`) unless explicitly requested.
- **Do not** add per-module PSR-4 entries to root `composer.json` — modules autoload from `src/modules/`.
- **Do not** add routes outside a module — `App\` is not discovered for routes.
- **Do not** treat any document as the backlog. `var/docs/FOR_IMPLEMENTATION/**`, `var/docs/**`, `packages/semitexa-docs/**`, `packages/*/docs/**`, `AI_NOTES.md`, `README.md` — none of these are canonical ideas, tasks, or status. The canonical backlog is `ai:epic` + `ai:work`. See §4.9–§4.12.
- **Do not** scan repository documents to answer backlog queries (§4.10). Document mining only runs when the operator explicitly triggers it (§4.11).
- **Do not** impersonate Semitexa. The agent keeps its own identity (§1.4).
- **Do not** create placeholder epics or tasks (`ep-a`, `tk-1`, title = `test` / `tmp` / `foo`) to unblock a command. Push back for a real id / title / goal first (§4.14).
- **Do not** hard-delete epics or tasks. Retirement is `status=discarded` via `ai:backlog clean --apply` or `ai:epic update --status=discarded`. The `var/ai-work/` tree stays append-only.
- **Do not** render the backlog without a stated scope. Every list is `source: ai:epic list --scope=<…>` (§4.13).
- `packages/semitexa-docs/` is the single official documentation source (framework + workspace); `packages/<pkg>/docs/` canonical package-local docs; `var/docs/` scratch — **none of these are backlog**. Root-level `./docs/` is **not** an official framework location; do not create, write, or reference it as part of the framework contract. (Contributors may keep a personal `./docs/` for private notes, but the framework makes no guarantees and no tooling depends on it.)

---

## 13. Project Graph (question-driven)

| Question | Command |
|---|---|
| Who uses this class? | `ai:review-graph:query --usages=<FQCN> --json` |
| What does this class depend on? | `ai:review-graph:query --dependencies=<FQCN> --json` |
| What breaks if I change this? | `ai:review-graph:impact <FQCN> --json` |
| Typed slice | `ai:review-graph:query --module=<M> --type=<T> --json` |

`ai:review-graph:generate --json` if stale. Not a warmup.

---

## 14. Stack (reference)

- PHP `^8.4`
- `semitexa/core` dev-main or v1.x
- Symfony 7.x, Twig ^3.10
- PSR Container + Semitexa DI (`#[AsServiceContract]`, `#[InjectAsReadonly|Mutable|Factory]`)

Exact pins in `composer.lock`. No Laravel / Illuminate.

---

## 15. Module Layout

```
src/modules/<Module>/
└── Application/
    ├── Payload/
    ├── Resource/
    ├── Handler/PayloadHandler/
    └── View/templates/
```

---

## 16. Debugging Commands (question-driven)

| Question | Command |
|---|---|
| Module shape | `ai:ask module --json --name=<Name>` |
| Project overview (last resort) | `ai:ask project --json` |
| Route chain | `ai:ask route --json --path=/path` |
| Events | `ai:ask event --json` |
| DI bindings | `contracts:list --json` |
| All routes | `routes:list` |
| Handler validity | `semitexa:lint:handlers` |
| DI validity | `semitexa:lint:di` |
| Logs | `ai:ask logs --file=<alias> --grep=…` |
| Existing epics / backlog / ideas (active) | `ai:epic list --json` *(default scope=active)* |
| Drafts / junk / archive / discarded epics | `ai:epic list --scope=drafts\|junk\|archive\|discarded\|all --json` |
| Single epic | `ai:epic show --id=<id> --json` |
| Active tasks | `ai:work list --status=in_progress,blocked --json` |
| New / unstarted tasks | `ai:work list --status=new --json` |
| Tasks in an epic | `ai:work list --epic=<id> --json` |
| Drafts / junk / discarded tasks | `ai:work list --scope=drafts\|junk\|discarded\|all --json` |
| Backlog health (counts by status × quality) | `ai:backlog stats --json` |
| Cleanup preview (dry-run) | `ai:backlog clean --json` |
| Cleanup commit (retire junk, archive stale done) | `ai:backlog clean --apply --json` |

---

## 17. Final Doctrine (13 rules)

1. **COLLABORATION** — the agent stays the agent and uses Semitexa as its execution environment. No identity replacement, no runtime roleplay.
2. **VOICE** — clear, professional, efficient. Natural language with structure when it helps. No forced system log.
3. **TRIAGE INTENT FIRST** — EXECUTE / CAPTURE / REFINE. Default to CAPTURE on hedged input. Never execute an unconfirmed idea.
4. **CANONICAL BACKLOG** — epics are the single source of truth for ideas, initiatives, and tasks. Backlog queries go to `ai:epic` / `ai:work`, never to documents. Document mining is opt-in only (§4.9–§4.12).
5. **AVOID DUPLICATES** — every CAPTURE and every mined candidate searches `ai:epic list` and scores overlap before creating.
6. **CLEAN BACKLOG** — default scope is `active`; junk / drafts / archive / discarded are opt-in. No placeholder ids or titles. Retirement is `status=discarded`, never hard-delete (§4.13–§4.14).
7. **CLASSIFY** before you act (`ai:task`).
8. **DECOMPOSE** when in doubt (`ai:epic` + `ai:work`).
9. **EXTERNALIZE** everything (`ai:trace` via `SEMITEXA_AI_TRACE_ID`).
10. **READ SIGNALS, NOT FILES** (`ai:context` → top-3 prior art).
11. **ONE-READ RULE** — re-reads come from the trace, not the disk.
12. **VERIFY EVERY WRITE** — `ai:verify` is not optional; failure blocks.
13. **FAIL EARLY, NOTE LOUDLY** — three failed attempts → stop, note the three hypotheses, escalate.

Short form: **work *with* Semitexa, not *as* Semitexa** — triage intent, honor the canonical (and clean) backlog, classify, decompose, externalize, verify.
