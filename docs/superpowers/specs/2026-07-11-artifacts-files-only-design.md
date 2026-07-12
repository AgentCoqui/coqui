# Minimal Artifact Fix: Files-Only, Transparent, Low-Ceremony

**Date:** 2026-07-11
**Status:** Approved (design)
**Roadmap:** `docs/superpowers/specs/2026-07-10-platform-thinning-roadmap-design.md`
**Memory:** `artifact-redesign` (direction: on-disk, predictable, transparent; Webhooks already removed, so no gating — runs now)
**Priority frame:** loops > profiles > prompt-budgeting. API is the primary surface; the REPL is a conversation portal. This change serves prompt-budgeting (artifacts should *reduce* context pressure) and loops (loop_output becomes a transparent, greppable file).

## Purpose

Make artifacts **predictable, transparent, and actually used**, by collapsing them to a single storage model — **plain files on disk with a DB index** — and deleting the machinery that currently makes them opaque and unused. This is a removal-heavy change: it deletes more than it adds.

The redesign has two prongs:

1. **Storage → files-only.** Every artifact is a file on disk; the DB holds only a lightweight index. Delete the dead hybrid file/DB decision engine.
2. **Behavior → actually get used.** Give `artifact_create` a crisp, checkable *when-to-use* threshold, stop pruning that guidance under budget pressure, and remove the ceremony (stage lifecycle, bulk ops, version-history fiction) that adds friction without payoff.

Three related efforts are **explicitly out of scope** and become their own sequenced briefs (see Non-Goals): memory-on-creation, loop hardening, and the broader tech-debt sweep.

## Why artifacts are underused today (verified diagnosis)

- **The default agent path never writes files.** The agent-facing toolkit is wired to a *bare* `ArtifactStore` with no file service: `ApiCommand.php:228`, `SpawnAgentTool.php:363`, `DoctorCommand.php:461` all do `new ArtifactStore($pdo)`. Only `BootManager.php:569` builds the file-capable store, and only when `isArtifactFilesystemBacked()` is set — which is **opt-in, default-off** (`OpenClawConfig.php:420-425`). So by default `artifact_create` produces an opaque SQLite blob the user cannot see on disk, while `write_file` produces a real, inspectable file. **A rational agent picks `write_file` every time.**
- **The one bit of real "when to use" guidance is pruned first.** `ArtifactToolkit::guidelines()` is injected as a `PromptSectionPriority::Volatile` section (`OrchestratorAgent.php:2091`) — the first thing dropped under budget pressure, i.e. exactly when a durable artifact matters most.
- **Ceremony with no payoff.** The draft→review→final lifecycle and the "version history preserves all prior states" promise (`prompts/tools/artifacts.md`) are both **fictions** — per-version snapshots were removed, nothing enforces staging. Worse, marking an artifact `final` currently *deletes* it on next boot: `DELETE FROM artifacts WHERE stage = 'final' AND persistent = 0 AND type != 'loop_output'` (`ArtifactStore.php:494`). A footgun.

## Non-Goals (→ separate sequenced briefs)

This change is deliberately narrow. The following are **not** in this spec and ship as their own `/prompt-agent-task` briefs, spun up one at a time:

- **Memory-on-artifact-creation.** Research verdict (Claude/Canvas/Devin/OpenHands): do **not** write a memory when an artifact is *created* — draft sprawl produces stale, low-signal pointers. Couple memory to *promotion / reusable outcomes*, gated on durability signals. That brief re-adds a single deliberate "pin" flag as the memory-promotion hook. This is precisely *why* the minimal fix can kill the stage machine now. (No artifact profile-scoping here — artifacts are shared; only memories are profile-scoped.)
- **Loop hardening.** Durable loop ledger (restart-safe, stop re-running completed stages), two-verdict review gate between stages, machine-readable stage status (`DONE | DONE_WITH_CONCERNS | BLOCKED | NEEDS_CONTEXT`), anti-thrash circuit-breaker. All from the superpowers `subagent-driven-development` study. Touches `LoopManager`/`LoopExecutor`, not the artifact toolkit.
- **Broader tech-debt sweep** beyond the artifact subsystem.

Also out of scope: no changes to the memory subsystem, no new dependencies, no REPL/API parity chasing (API mutation routes stay — that is aligned with the API-first direction).

## Decisions Locked (from brainstorming)

- **Files-only storage.** Every artifact is a plain file under `artifacts/`. The DB row is a pure index (id, session/project, created_by, title, type, path, hash, version counter, timestamps) — no content column for new artifacts. Chosen over "turn on the existing backing" (keeps the dual matrix) and "behavioral-only" (leaves the opacity).
- **`loop_output` moves to a file too.** Required, not optional: the DB-content path cannot be deleted while `loop_output` still uses it. The `loop_stages.artifact_id` reference and `artifact_get(id)` reader are preserved — only the backing changes.
- **Kill the draft→review→final stage machine entirely.** Retention becomes: *project-linked artifacts persist; session-only artifacts are cleaned up when the session is deleted.* Removes the `final`-deletes footgun.
- **Trim the type enum.** The `artifact_create` enum shrinks to `plan, document, code, config`. `loop_output` stays a valid **stored** type (system-created by LoopManager) and remains selectable in the `artifact_list` **filter** enum so the loop's `artifact_list(type: "loop_output")` hint (`LoopExecutor.php:809`) keeps working — it is just not offered as an agent *create* option. Drop `sketch, hypothesis, data, other` everywhere.
- **Drop bulk stage/delete.** Single-id operations only. (Stages are gone, so bulk-stage is moot; bulk-delete is niche housekeeping.)
- **Behavioral fix.** Rewrite the tool description + guidelines to lead with a crisp WHEN threshold; create-vs-update is stable-path identity with full-rewrite semantics; simplify `artifact_create` params.
- **Pinned recent-artifacts index (memory-parity).** Replace the `Volatile` session-list with a **pinned** recent-artifacts *index* (pointers + path + `by <created_by>`, capped ~10), modeled on core-memory injection — so it survives budget pressure. Scope **session→project** only. Artifacts are **shared, not profile-scoped**; `created_by` is display-only provenance so the loading agent decides relevance.

## Architectural Grounding (verified)

- **Agent-path stores are bare** and must be given the file service: `ApiCommand.php:228`, `SpawnAgentTool.php:363`, `DoctorCommand.php:461`. This is the single highest-leverage fix — without it, files are never written on the agent path regardless of other changes.
- **`isArtifactFilesystemBacked()`** (`OpenClawConfig.php:422`, gate at `BootManager.php:566`) becomes dead once files are universal — remove the flag; the store is always file-capable.
- **`DB_ONLY_TYPES = ['loop_output', 'data', 'other']`** (`ArtifactFileService.php:33`) and the `AUTO_PATH`/`EXPLICIT_PATH` matrix (`ArtifactFileService.php:26-62`) are the hybrid decision engine to delete.
- **Drift API** (`ArtifactFileService::detectDrift`, `computeFileHash`, `fileExists`, `toAbsolutePath`) has **zero production callers** (tests only) — delete.
- **`cleanupFinalized()`** (`ArtifactStore.php:480-498`, called at `BootManager.php:578`) implements the stage-based retention/footgun — replace with ownership-based session cleanup.
- **`hasPersistentArtifacts()`** (`ArtifactStore.php:540`) — no callers, dead — delete.
- **`sprint_id`** column (`ArtifactStore.php:79`) — never written or read — stop declaring it (leave dormant if a table rebuild is not cheap).
- **Loop reviewer already reads by id** via `artifact_get(id)` (`LoopExecutor.php:660-663`), so dropping the `final` stage does not break loop review.
- **Stale approval entry** `artifact_bulk_delete` (`ExecutionPolicyFactory.php:25`) references a tool that no longer exists — remove.
- **`loop_output` creators:** `LoopManager.php:390` (`type: 'loop_output'`), listing hint at `LoopExecutor.php:809`.

---

## Part 1 — Storage: files-only

### Model

- Every artifact's canonical content is a **file on disk** under an `artifacts/` directory. The DB `artifacts` row is an **index only**: `id, session_id, project_id, created_by, title, type, path, content_hash, version, created_at, updated_at`. (`created_by` is a new **provenance** column — a human-readable label for who made the artifact: the active profile/persona if present, else the role/agent identity. It is display-only attribution, **never a scope/filter**. Artifacts are shared, not profile-scoped — only *memories* are profile-scoped.)
- **Path convention (predictable):** `artifacts/<type>/<slug>-<shortid>.<ext>` where `<ext>` derives from `type` (`plan|document → .md`, `code → language ext or .txt`, `config → .json/.yaml/.txt`). Uniqueness comes from `<shortid>`; the slug keeps it human-scannable. The path is stable for the life of the artifact (see create-vs-update).
- **Location / ownership for retention:** project-linked artifacts live under the project's artifact dir and persist; session-only artifacts live under a session-scoped dir and are removed on session deletion. The index row records both `session_id` and `project_id`; ownership drives cleanup.

### Change

- **`ArtifactFileService`** → simplify to a small, always-on file backend: `pathFor(type, slug, id)`, `write(path, content) → hash`, `read(path)`, `delete(path)`. Delete `DB_ONLY_TYPES`, `AUTO_PATH_TYPES`, `EXPLICIT_PATH_TYPES`, and the entire drift API.
- **`ArtifactStore`** → always constructed with the file service. `create()`/`update()` write the file and store the path+hash; `get()` reads the file (drop the DB-content overlay branch). Remove `storage_mode` special-casing (all rows are filesystem now; the column can be dropped on rebuild or left as a constant).
- **Wire the file service into every store construction site:** `ApiCommand.php:228`, `SpawnAgentTool.php:363`, `DoctorCommand.php:461`, and `BootManager` (unconditional now). This is the fix that makes the agent path actually write files.
- **`OpenClawConfig::isArtifactFilesystemBacked()`** and the `BootManager` gate → **delete**; the store is always file-capable. Remove the `agents.defaults.artifacts.filesystemBacked` default from `CoquiDefaults`.

### `loop_output` migration (required)

- `LoopManager::createStageArtifact` (`LoopManager.php:349-390`) writes the stage result as a **file** (type `loop_output`, path under the work-scope session/project artifact dir). The index row keeps the same `id`; `loop_stages.artifact_id` is unchanged; the reviewer's `artifact_get(id)` now returns file content transparently.

### Legacy data migration (one-time, on boot)

- For any existing `artifacts` row with inline `content` and no file: write the content to a file per the new convention, set `path`+`hash`, blank `content`. Rows referenced by `loop_stages.artifact_id` (loop_output) **must** be migrated, not dropped. After this, the DB-content read path can be deleted outright. (Simpler alternative if acceptable operationally: drop legacy session-scoped rows and migrate only project-linked + loop_output. Implementer's call; default to the safe forward-migration.)

## Part 2 — Ceremony cuts

- **Stage lifecycle → gone.** Delete `artifact_stage` tool, the `stage` reads/writes, and `cleanupFinalized()`. Replace retention with **ownership-based session cleanup**: when a session is deleted, delete its files+index rows that have no `project_id`. Leave the `stage` column dormant only if a table rebuild is not cheap; otherwise drop it.
- **Type enum → `{plan, document, code, config}`** in `artifact_create` (`ArtifactToolkit.php:108`). The `artifact_list` filter enum (`ArtifactToolkit.php:221`) keeps `loop_output` in addition, so the loop hint at `LoopExecutor.php:809` still validates. Remove `sketch, hypothesis, data, other` from both.
- **Bulk ops → gone.** Delete `resolveArtifactTargets`, `bulkUpdateStage`, `bulkDelete`, and the `ids`/`all`/filter params on stage/delete. `artifact_delete` becomes single-`id` only. Remove the stale `artifact_bulk_delete` approval entry (`ExecutionPolicyFactory.php:25`).
- **Dead code → gone.** `hasPersistentArtifacts()`, drift API, `sprint_id` declaration.
- **`persistent` flag** → fold into "has a `project_id`"; stop exposing it as a separate concept. (The API `patch` can keep setting `project_id`; drop standalone `persistent` mutation.)

## Part 3 — Behavior: make artifacts get used

### Tool surface (after)

| Tool | Params | Notes |
| --- | --- | --- |
| `artifact_create` | `title`, `content`, `type?` (default `document`) | Writes a file, returns `id` + `path`. No `filepath`/`project_id`/`language` matrix. |
| `artifact_update` | `id`, `content` | Full rewrite of the file; bumps the `version` counter. |
| `artifact_get` | `id` | Returns content (read from file) + metadata + `path`. |
| `artifact_list` | `type?`, `project_id?` | Lists the index. |
| `artifact_delete` | `id` | Deletes file + index row. Withheld from read-only roles. |

- **Version counter** is kept as a simple monotonic "times updated" signal shown in listings — **not** a history store. Fix the docs to say exactly that.
- **Create-vs-update = stable-path identity.** Updating reuses the same `id`/`path`; a new title/artifact gets a new path. Full-rewrite only (no fragile line/regex patching — the research shows that is where agents corrupt files).

### Guidance (the crisp threshold)

Rewrite `prompts/tools/artifacts.md` and `ArtifactToolkit::guidelines()` to **lead with when, not how**:

- **Create an artifact when the output is:** (1) **substantial** — more than ~15 lines *or* a complete file/document; (2) **durable** — the user would keep, re-open, share, or iterate on it; (3) **self-contained** — it makes sense without the surrounding chat.
- **Do NOT create an artifact for:** one-off answers, short snippets, explanations, or commentary about an existing artifact.
- **Tie-breaker:** *if unsure, don't force it — but prefer an artifact over an ephemeral message whenever the thing is a real deliverable the user can open on disk.*
- **State the payoff:** artifacts are plain files under `artifacts/<path>` — inspectable, greppable, and versioned by the user's own git; referencing an artifact by path instead of re-pasting it **saves context budget**.
- **Update, don't recreate:** to change an existing artifact, `artifact_update` its `id` (reuses the file); only `artifact_create` for a genuinely new deliverable.

### Recent-artifacts index (pinned) — mirror the memory mechanism

Today `ArtifactToolkit::guidelines()` lists session artifacts (`store->list($sessionId, limit: 10)`) as a `PromptSectionPriority::Volatile` section (`OrchestratorAgent.php:2091`) — so it is pruned exactly when context is full. Replace it with a **pinned recent-artifacts index** modeled directly on how core memories are injected (`buildMemoryPromptSections()`, `OrchestratorAgent.php:1856-1894`, whose rationale is *"stay pinned as workflow context"*). The when-to-use *guidance* prose and the recent-artifacts *index* both live in this pinned section, as durable in the prompt as the tool's own availability.

- **Content = pointers, never bodies.** Each entry is `- **title** (id) [type] path — by <created_by>`. The file path is the quick-reference affordance (the agent `read`s/greps it directly); `created_by` is provenance so the loading agent can judge relevance itself. Cap at **N ≈ 10** most-recently-updated within scope. Keeping it a tiny index is what makes pinning affordable.
- **Scoping = session→project only (artifacts are shared, not profile-scoped):**
  - **Project loaded** → recent artifacts of that project (`project_id` filter — column exists).
  - **No project** → recent session artifacts (today's scope, upgraded to pinned).
- **Provenance, not scope.** `artifact_create` stamps `created_by` — the active profile/persona label if present, else the role/agent identity (for `loop_output`, the loop/stage identity). It is shown in the index and **never used to filter**: artifacts stay visible to everyone; the agent decides what to do with someone else's artifact. (Contrast: *memories* are profile-scoped; *artifacts* are not.)
- **Budget caveat (accepted):** a pinned index busts the prompt cache whenever it changes (`OrchestratorAgent.php:996`), and artifacts churn more than memories mid-session. Accepted deliberately — availability is the point, and a ~10-line pointer index is cheap to re-cache. Keep it small; do not inline content.

## API

- Keep the CRUD routes under `/api/v1/sessions/{id}/artifacts` (API-first direction). Remove `stage` from the `PATCH` body and the `?stage=` list filter. Fix the misleading "mutations are REPL-only" header comment (`ArtifactHandler.php:23`) — mutation routes are wired and stay.

## Testing

- Update/replace `ArtifactFileServiceTest`, `ArtifactStoreHybridTest` for the simplified file-only service (drop the hybrid/drift cases).
- New coverage: create → file exists on disk at predictable path; update → same path rewritten, version bumps; get → reads from file; delete → file + row gone; session cleanup → session-only files removed, project-linked survive; `loop_output` created by a loop is a readable file and `artifact_get(id)` still works for the reviewer.
- Legacy migration test: a pre-existing inline-content row is migrated to a file on boot; a `loop_output` row referenced by `loop_stages` is preserved.
- Recent-artifacts index: `artifact_create` stamps `created_by` (active profile/persona or role); the pinned index lists pointers+path+`by <created_by>` capped at N, narrows to `project_id` when a project is loaded, is **not** filtered by creator, and is emitted at the pinned (non-`Volatile`) priority.
- `composer test` / targeted `./vendor/bin/pest` + `./vendor/bin/phpstan analyse`.

## Docs to fix (drift)

- `docs/ARTIFACTS.md` — rewrite around files-only; remove the draft→review→final lifecycle, bulk-ops, persistence-flag, and "injected every iteration" claims; correct the storage description.
- `prompts/tools/artifacts.md` — new when-to-use guidance; remove the false version-history promise.
- `config/source.json` — update `ArtifactFileService`/`ArtifactStore`/`ArtifactToolkit` responsibilities if they changed materially.
- Remove `ArtifactHandler.php:23` stale comment.

## Rollout / sequencing

This is **brief #1** of the sequenced set. It must land before the memory and loop briefs, because it changes the storage model both build on. See the companion briefs for ordering and parallel-safety.

## Status

Webhooks has already been removed from the project, so the original "after Webhooks Phase 2" gating is moot. **Brief #1 runs now.**
