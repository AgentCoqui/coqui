# Loop Hardening — Design

**Date:** 2026-07-13
**Status:** Approved (design) — pending spec review
**Branch:** `feat/loop-hardening`
**Brief:** #3 — Loop Hardening (adopt superpowers subagent-driven-development patterns into Coqui's loop engine)

## Problem

Coqui's loop engine advances multi-role iteration cycles hands-off until a termination
condition is met. Three weaknesses make it fragile and dishonest under real workloads:

1. **Reviewer judgment is prose string-matching.** `evaluation_bound` loops scan the last
   stage's text for `approved`/`lgtm` vs `rejected`. There is no structured gate, no severity,
   and the producer effectively grades itself.
2. **Non-convergence is invisible.** A loop that never satisfies its reviewer silently burns to
   `max_iterations` and then reports *"completed (limit reached)"* — a false positive that reads
   as success. There is no "this isn't converging — a human needs to change something" signal.
3. **Restart-safety has a hole.** `loop_stages` already persists per-stage status, so *completed*
   stages are never re-run (the expensive failure is already handled). But a stage left `running`
   when the process dies or its task is orphaned hangs forever with no recovery, and a crash
   mid-dispatch can double-dispatch.

Coqui's `LoopStore` already carries the scaffolding for the fix — a `needs_rework` iteration
status and `resetStagesForIteration()` / `resetIterationForRetry()` / `reopenIteration()` helpers.
These are **already wired** to the operator-driven REST `retry` endpoint. What was missing is an
*automatic* producer that sets `needs_rework`, drives a structured gate, and stops on non-convergence.

## Goals

- Replace prose string-matching with a structured, artifact-judging **stage verdict**.
- Make done-vs-continue-vs-stop **deterministic** via a machine-readable status taxonomy.
- Stop non-converging loops early with an honest, actionable **`blocked`** escalation instead of a
  misleading "completed."
- Close the restart-safety hole (orphan-stage recovery, dispatch idempotency).
- Let a stage **require a durable artifact** to pass.
- Keep the interaction model **chat/API-first** and the REPL a basic display+control client — no new
  execution code in the REPL, no new API program.

## Non-goals

- No in-process loop execution in the REPL. Loops run in the API loop manager; the REPL displays and
  controls. (Unchanged.)
- No new REST API *program* (brief #7). We enrich existing responses/endpoints only.
- No artifact or memory redesign (settled and shipped in #155/#156).
- No auto-context fetching for `NEEDS_CONTEXT` — auto-fabricating context is the exact thrash we
  are preventing.
- No in-place reset for *automatic* rework — auto-rework advances a new iteration (existing model);
  in-place reset is reserved for the *operator-driven* retry/unblock gesture.

---

## Design

Three mechanisms, built on the existing rework scaffold. Guiding principle: **the controller decides
from a machine-readable verdict, not from prose or a producer's self-report.**

### Mechanism 1 — Stage Verdict (the shared primitive)

Every stage resolves to a `StageVerdict`. This single primitive serves both the per-stage status
taxonomy (item: machine-readable status) and the two-verdict gate (item: stage gate). **Only gate
stages spend a utility-LLM call** (see "Gate-only judging" below); non-gate producer stages
self-signal cheaply.

**New value objects (`src/Contract/`):**

- `StageStatus` (enum): `Done | DoneWithConcerns | Blocked | NeedsContext`
- `StageSeverity` (enum): `Critical | Important | Minor`
- `StageFinding` (readonly): `{ severity: StageSeverity, summary: string, location: ?string }`
- `StageVerdict` (readonly):
  - `status: StageStatus`
  - `requirementsMet: ?bool` — null for non-gate stages
  - `qualityPass: ?bool` — null for non-gate stages
  - `findings: list<StageFinding>`
  - `rationale: string`
  - `fromArray()` / `toArray()` for JSON persistence; tolerant `fromLegacyText()` for the fallback path.

**Produced by `StageGateEvaluator` (`src/Agent/`), mirroring `GoalEvaluator`:**

- One utility-LLM `provider->chat()` call, no tool use, catches all errors (same shape as
  `GoalEvaluator` / `TitleGenerator`).
- Input: the stage's **actual output** and, for gate stages, the **artifact content** — the reviewer
  judges the artifact, not the producer's self-report.
- Output: a compact JSON verdict parsed tolerantly (json_decode with a keyword fallback), never raw
  JSON surfaced to users.
- `gate = false` → status-only verdict (`requirementsMet`/`qualityPass` null, `findings` empty). This
  exists only to catch `Blocked` / `NeedsContext`.
- `gate = true` → full two-verdict + findings with severities.

**A stage is a gate** if it is the last role of an `evaluation_bound` loop, or a role explicitly
flagged `gate: true` in the definition.

**Gate-only judging (decision).** `StageGateEvaluator` runs **only on gate stages**. Non-gate
producer stages self-signal without a utility call: they **default to `Done`**, and MAY emit
`Blocked`/`NeedsContext` via a cheap, tolerant sentinel scan of their output (e.g. a leading
`STATUS: BLOCKED` / `STATUS: NEEDS_CONTEXT` line) — no LLM call, best-effort, defaulting to `Done`
when absent or malformed.

Rationale: the "producer grades itself" problem bites at the *gate*, not at producer stages; and the
circuit-breaker is already the real non-convergence net (bounds waste to ~3 iterations regardless).
Per-stage judging would catch a mid-iteration stall only a fraction of an iteration sooner, at
2–3× the utility-call cost plus reactor pressure.

**Seam (do not build now, do not design out).** If hands-off loops later prove to stall
mid-iteration, per-stage judging returns behind an opt-in role flag. Keep the `gate`/verdict plumbing
general enough that flipping a per-role "judge me" flag would route a producer stage through the same
`StageGateEvaluator` path — but ship no such flag in this change.

**Fallback (graceful degradation).** When no utility model is configured, `StageGateEvaluator` is
absent. Gate stages fall back to today's keyword string-match; non-gate stages resolve to `Done`.
Existing loops keep working unchanged.

**Persistence.** New column `loop_stages.verdict TEXT` (JSON), migrated via
`SchemaHelper::addColumnIfMissing`. This is the **one** real schema migration — it earns it. All
other new state (`rework_attempts`, `escalation`, `pending_guidance`, `dispatch_attempts`) lives in
existing `metadata` JSON, matching the codebase's own precedent and avoiding schema churn.

**Reactor discipline (fold-in).** The gate evaluator's utility call must not stall other loops' ticks
while in flight. Place and await it exactly the way `GoalEvaluator` is already invoked in the
reconcile/evaluate path (same reactor treatment) — called out explicitly so the implementer matches
the existing pattern rather than introducing a blocking call on the ReactPHP loop.

### Mechanism 2 — Gate & Rework controller + circuit-breaker

Deterministic mapping in `LoopExecutor`, driven by persisted verdicts. No prose parsing.

**Per non-gate stage** (evaluated as stages complete):

| verdict.status      | controller response                                             |
|---------------------|-----------------------------------------------------------------|
| `Done`              | advance to next stage                                            |
| `DoneWithConcerns`  | advance; Minor findings accrue into iteration context           |
| `Blocked`           | halt loop → `blocked` (escalation reason: *stage blocked*)       |
| `NeedsContext`      | halt loop → `blocked` (escalation reason: *missing context*)     |

**At the gate stage** (end of iteration), map the gate verdict:

- `requirementsMet && qualityPass && no Critical/Important findings` → **Complete**
- Minor-only findings (both verdicts true) → **Complete** (Minor accrues, never blocks)
- any `Critical`/`Important`, or either verdict false → **rework**:
  - mark the iteration `needs_rework` (honest — instead of falsely marking a rejected round
    `completed`), carry structured findings forward, advance a **new iteration**
  - increment a `rework_attempts` counter in loop metadata
  - if `rework_attempts >= max_rework_attempts` (default **3**, configurable per definition) →
    **`blocked`** (circuit-breaker: escalation reason *not converging*), instead of grinding to
    `max_iterations`

A loop stops at whichever fires first: gate approves (`Complete`), breaker trips (`blocked`), or
`max_iterations` reached (`LimitReached`).

**Severity taxonomy:** `Critical`/`Important` block (force rework); `Minor` accrues (recorded,
surfaced in context and the final summary, never blocks).

**New contract:** `IterationOutcome::Blocked`.
**New loop status:** `blocked`, carrying a structured `escalation` block in loop metadata
`{ reason, attempts, last_findings }`.

### Mechanism 3 — Durability

**3a-0. Tick skips blocked loops (fold-in).** A `blocked` loop must spend **nothing** until an
operator retry revives it. `LoopManager::tick()`/`reconcile()` iterate `listLoops('running')`, so a
loop moved to `blocked` naturally drops out — but this is made an **explicit** requirement and test,
not an implicit consequence: a blocked loop dispatches no stages, makes no evaluator calls, and only
re-enters the running set when a retry sets it back to `running`.

**3a. Orphan-stage recovery (`LoopManager`).** On tick/reconcile:

- A `running` stage whose task is **terminal but unreconciled** → routed through the existing
  reconcile path (`completeStage`/`failStage`).
- A `running` stage whose task is **missing** (row gone / never persisted) → reset to `pending` and
  re-dispatched, bounded by a `dispatch_attempts` guard in stage metadata. Exceeding the bound →
  fail the stage → iteration fails (surfaces via existing failure path).
- **Dispatch idempotency:** a `pending` stage that already carries a `task_id` (crashed
  mid-dispatch) reconciles the existing task instead of spawning a duplicate.

This closes the only real restart-safety gap; completed stages are already durable.

**3b. Artifact-required stage.** Optional per-role flag `artifact_required: true`. The stage's
verdict cannot resolve to `Done` unless `createStageArtifact` produced a durable artifact; otherwise
the verdict is forced to `Blocked` (missing required artifact) → escalation/rework.

**Memory pointer — soft signal only.** #156 (memory-on-promotion) is **convention-based**: prompt
guidance plus wiring `MemoryToolkit` into artifact-creating children. There is **no queryable
memory-pointer keyed to an artifact id.** Therefore:

- `memory_required: true` is a **non-blocking concern**, not a hard gate: a best-effort check of
  whether the stage session wrote ≥1 new memory record; if not, a `Minor` finding is attached
  (surfaced, never blocks).
- The stage prompt injects the memory-on-promotion guidance so the agent records the pointer by
  convention.

We deliberately do **not** fabricate a structural memory link that #156 does not provide.

---

## Interaction model (chat/API-first, REPL basic)

The interaction surfaces already exist and are already API-first. The hardening reuses them; the
only genuinely new affordance is one small agent-tool action.

**Surfaces today (priority order):**

1. **Chat / agent tools** (`LoopToolkit`): `loop_start`, `loop_list`, `loop_status`,
   `loop_control` (pause/resume/stop). The orchestrator runs these for the user in conversation —
   this is the chatlike interface.
2. **REST** (`LoopHandler`): `/live`, `/events` (SSE), `/history`, `/iterations/{id}/retry`,
   pause/resume/stop. An integrating app renders its own chat UI from these structured responses.
3. **REPL `/loops`**: display of the same state.

Loops **execute in the API loop manager** (ReactPHP tick/reconcile), never in the REPL process.
"Always background"; the REPL is a display+control client. No foreground-blocking, no new REPL
execution code.

**How `blocked` surfaces:** an **actionable notification** into the parent chat session — the same
channel already used for `loop.failed`. The user reads "blocked because X, Y (2 critical findings)"
in chat. `loop_status` / `/live` expose the `escalation` block and per-stage verdicts as **structured
fields** (never raw JSON in chat/REPL).

**How a user un-blocks (chat retry + optional guidance note):**

- Add a **`retry` action to the `loop_control` agent tool**, mirroring the existing REST
  `/iterations/{id}/retry`. The user says *"it's stuck — retry with approach B"* and the agent runs it.
- The retry accepts an **optional free-text `note`**. The note is persisted to loop metadata
  (`pending_guidance`) and injected by `buildStagePrompt` into the reopened iteration's first stage as
  an **`## Operator Guidance`** section, then cleared after dispatch. (Metadata avoids a schema
  migration; a nullable `loop_iterations.guidance` column is the alternative if we prefer
  iteration-scoped storage.)
- Retry reuses the existing `resetStagesForIteration` / `resetIterationForRetry` helpers and **clears
  the breaker counter**. Same loop id, history preserved.
- The REST `/iterations/{id}/retry` endpoint is extended to (a) accept the optional `note` and
  (b) apply when the loop status is `blocked` (today it requires paused/stopped + a
  `failed`/`needs_rework` iteration). No new endpoint.

**Muddy-risk mitigations (explicit plan items):**

- The new `blocked` status must be taught to every status consumer: the `LoopHandler` list/get
  serializers, `/live` snapshot, REPL `/loops` display, and the loop notification `kind` mapping.
  `blocked` is displayed in the terminal/failed status family with the escalation reason as its detail.
- Verdicts stay internal. Chat and REPL render human summaries; REST exposes structured fields.

---

## Data model summary

| Change | Location | Migration |
|--------|----------|-----------|
| `loop_stages.verdict TEXT` | `LoopStore` | `SchemaHelper::addColumnIfMissing` |
| `rework_attempts`, `escalation`, `pending_guidance` | loop `metadata` JSON | none (JSON) |
| `dispatch_attempts` | stage `metadata` JSON | none (JSON) |
| loop status value `blocked` | free-text `status` column | none (value only) |
| iteration status `needs_rework` | already supported | none |
| role flags `gate`, `artifact_required`, `memory_required` | `LoopRoleDefinition` (default false) | none (config) |

## Component changes

- **New** `Contract/StageStatus.php`, `Contract/StageSeverity.php`, `Contract/StageFinding.php`,
  `Contract/StageVerdict.php`.
- **New** `Agent/StageGateEvaluator.php` (mirrors `GoalEvaluator`).
- **Edit** `Contract/IterationOutcome.php` — add `Blocked`.
- **Edit** `Contract/LoopRoleDefinition.php` — add `gate`, `artifact_required`, `memory_required`.
- **Edit** `Agent/LoopExecutor.php` — verdict-driven gate/rework/breaker in `evaluateIteration`;
  per-stage BLOCKED/NEEDS_CONTEXT handling; `blocked` escalation; operator-guidance injection in
  `buildStagePrompt`; artifact-required enforcement.
- **Edit** `Api/LoopManager.php` — invoke `StageGateEvaluator` + persist verdict in reconcile;
  orphan-stage recovery + dispatch idempotency in tick/reconcile; `blocked` notifications.
- **Edit** `Storage/LoopStore.php` — `verdict` column + accessors; breaker-counter helpers;
  `blocked` status handling; guidance persistence.
- **Edit** `Toolkit/LoopToolkit.php` — `loop_control` gains `retry` action (with optional `note`);
  `loop_status` surfaces verdict/escalation summaries.
- **Edit** `Api/Handler/LoopHandler.php` — `/retry` accepts optional `note` and applies to `blocked`;
  serializers learn `blocked` + expose verdict/escalation fields.
- **Edit** `Command/ApiCommand.php` — build `StageGateEvaluator` from the utility model and inject.
- **Docs** `docs/LOOPS.md`, `config/source.json`.

## Testing (TDD)

- `StageVerdict` parse/serialize/map; `StageStatus`/`StageSeverity` mapping.
- `StageGateEvaluator` with a fake provider returning verdicts; fallback path (no utility model);
  **runs only on gate stages** (non-gate stage completing spends no evaluator call).
- Non-gate self-signal: default `Done`; sentinel `STATUS: BLOCKED`/`NEEDS_CONTEXT` parsed; malformed
  sentinel → `Done`.
- Tick skips blocked loops: a `blocked` loop dispatches no stages and makes no evaluator call until
  a retry restores `running`.
- Gate controller: approve→Complete; Critical→rework; Minor-only→Complete; 3×reject→blocked.
- Circuit-breaker threshold (configurable) and metadata counter.
- Per-stage BLOCKED/NEEDS_CONTEXT → blocked with correct reason.
- Orphan recovery: running stage with missing task → recovered/re-dispatched; bound exceeded → fail.
- Dispatch idempotency: pending stage with existing task_id → no duplicate.
- Artifact-required: missing artifact → forced Blocked; present → pass. `memory_required` → Minor
  concern when absent, never blocks.
- Unblock: `loop_control(retry, note)` and REST `/retry` on a `blocked` loop → reset + resume +
  breaker cleared; guidance injected into the reopened stage prompt.
- Back-compat: no utility model → string-match still works; existing loop definitions unaffected;
  status consumers render `blocked` without error.

## Definition of Done (execution phase, after plan approval)

- All three mechanisms implemented via TDD.
- `composer test` and `composer analyse` green.
- `docs/LOOPS.md` and `config/source.json` updated.
