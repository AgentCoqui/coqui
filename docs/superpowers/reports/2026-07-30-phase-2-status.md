# Phase 2 Status Report — §A storage reshape to CAP 0.5.0

**Phase:** 2 of 7 (CAP 0.5.0 conformance migration)
**Branch:** `feat/cap-0.5-conformance` (worktree `coqui-cap-migration`, unpushed)
**Range:** `a7263ef..e06623f` (13 commits: 1 plan doc + 12 `feat(storage)` tasks)
**Status:** ✅ COMPLETE — whole-branch review "Ready to merge = Yes"; no Critical/Important findings.

## Goal (met)

Reshape coqui's three SQLite stores to the CAP 0.5.0 data shape — recreate-from-empty (no migrations), add the `meta.schema_version` marker with fail-closed-open — and make every in-scope Core object **producible** as a schema-valid instance validated against the vendored `schema/*.json`. Producibility (a schema-valid instance *can* be emitted) is Phase 2's bar; exact live-response conformance and the behavioral rules are later phases.

## Acceptance evidence

- **17 CORE rows flipped green** (todos 59→42): CORE-**1, 3, 8, 13, 14, 15, 16, 19, 24, 25, 26, 27, 28, 33, 34, 42, 59**. Each is a real `it(...)->group('conformance')` that produces an object and asserts `ConformanceValidator::isValid('<obj>.json', $produced)` — verified non-vacuous per-task and at the whole-branch review (CORE-8 includes a negative discrimination case; CORE-3 proves raw `+00:00`→`Z` normalization; CORE-13 asserts the integer job-event id).
- **Green throughout:** `composer test` = 2612 passed (from 2551 at phase start); `composer analyse` clean (401 files). Green after every task — never committed red.
- **Safety preserved:** `src/Config/CatastrophicBlacklist.php` + its test **byte-unchanged across all 12 commits** (`git log a7263ef..e06623f -- CatastrophicBlacklist.php` empty). Vendored `tests/conformance/spec/**` untouched by coqui commits.
- **Marker + fail-closed:** `meta.schema_version='0.5.0'` seeded on the primary store; `assertSchemaVersion` refuses any differing stamp (no in-place migration). `PRAGMA foreign_keys=ON` confirmed connection-wide.

## Tasks (each: fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer; review clean)

| # | Scope | CORE | Commit |
|---|---|---|---|
| 1 | `meta.schema_version` marker + fail-closed-open (FK-ON already applied by `SqlitePragmas`) | — | `ae0b8b2` |
| 2 | `personas` index table + `PersonaSnapshotStore::toWire` | 1 | `e1682c8` |
| 3 | `sessions` reshape (kind/pinned/workspace/version; model nullable; derive status/members) + `SessionHandler::toWire` | 15, 19 | `d75bb22` |
| 4 | `turns` reshape (actor_persona_id, status) + `TurnHandler::toWire` | 34 | `04646a9` |
| 5 | `child_runs` realignment (parent_session_id/parent_turn_id/role/nullable model/status/token split) | 28 | `908aa0d` |
| 6 | `content` table + `ContentStore` (sha256 addressing) | 42 | `ece2839` |
| 7 | `skills` catalog table + Skill producer (origin/execution objects) | 26, 27 | `e5f020e` |
| 8 | `scheduled_tasks` reshape (`schedule_expression`→`cron`, +persona_id, derived action) | 33 | `126d10a` |
| 9 | `loops` diagnostics (rework_attempts/dispatch_state/last_dispatch_error/origin) + drop `project_id` | 16 | `2e37cda` |
| 10 | `artifacts` drop dead columns + Artifact producer | 25 | `3dc1e2f` |
| 11 | Question producer + `memories.version` column | 24 | `b1796b6` |
| 12 | LoopDefinition + internal-collection + export typing + timestamp audit (`src/Export/`) | 8, 13, 14, 3, 59 | `e06623f` |

## Cross-task coherence (whole-branch review confirmed)

- **Persona-FK guardrail applied uniformly:** `persona_id`/`actor_persona_id` are plain `TEXT` with NO FK to `personas` on sessions/turns/child_runs/loops/scheduled_tasks — deliberate, because `personas` is a file-sourced snapshot and `foreign_keys=ON` would reject every insert. Every real FK (session/turn/parent-session/loop) targets an existing table with correct CASCADE/SET NULL.
- **Strict-producer / additive-response split (consistent):** each object has a schema-only `toWire` conformance producer beside an additive/backward-compatible live response. No task made a live response strict (which could break clients) or left a producer non-strict. Exact response-shape conformance is Phase 4.
- **Recreate-from-empty:** sessions/turns/child_runs fold accreted columns into base DDL and drop `migrateAddColumn`; no ALTER-chains reintroduced. (One outlier — `ArtifactStore` — see below.)
- **Empty-object gotcha handled uniformly:** avatar/preferences/metadata/origin/execution/action/termination_condition/configuration emit `stdClass`, never `[]`.
- **Phase-1 identity rename not regressed; no `profiles[]` capability collision.**

## Carry-forwards (surface into Phase 3/4/5/6)

**SYSTEMIC — persona threading (Phase 4, response conformance):** `session.json`/`scheduled-task.json`/`loop.json` REQUIRE a non-null `persona_id`, but runtime creation can leave it null/empty — group sessions (`persona:null`), schedules (persona stashed in metadata), and headless loops (auto-provisioned persona-less work-scope session). Producers validate when a persona is supplied (met Phase-2 bar), but **live** objects are non-conformant until a `persona_id` is threaded at creation (default persona for headless/group). Bounded to one field on three collections; documented in-code at each site.

**Memory Core reshape (later phase):** coqui `memories` keeps `area/tags/importance/memory_type` (vs CAP `name/description/type`). Phase 2 added only `memories.version`. A faithful `memory.json` producer needs the reshape; memory is still *typed* in the export map and validated via the golden `export.roundtrip.json` vector.

**Phase 4:** exact wire-shape/response conformance; the `session.persona_id` FK-strictness (reference SQL wants NOT NULL RESTRICT); the DELETE-member `{persona}` vs body `persona_id` asymmetry.

**Phase 5:** `scheduled action.kind='loop'` + schedules-profile endpoints/dialect; `content_ref` sha256 dedup + blob ops (CORE-44/45).

**Phase 6:** export preserve+remap roundtrip **import** (Phase 2 = per-collection typing + envelope structure only); Flutter wire-boundary delta.

**Phase 3 (behavioral):** `on_question` is a `LoopDefinition` JSON field, not a DB column — its deletion is behavioral (D4). `kind='loop_workscope'` set at loop-workscope session creation.

## Open decisions / tidy (non-blocking)

- **USER DECISION:** `src/Contract/ScheduleFileDefinition.php:88` accepts triple-key `schedule_expression`/`expression`/`cron` on the workspace file-authoring surface (pre-existing; DB + wire are cron-only). The whole-branch reviewer judged this a separate file-authoring surface (lenient input spelling, not a contract alias) and non-blocking. Decide whether to make it `cron`-only in its own commit.
- **Tidy (own commit, pattern parity):** fold `ArtifactStore`'s 4 remaining `migrateAddColumn` calls (`project_id`/`path`/`content_hash`/`created_by`) into base DDL; drop the dead `$artifact['stage']` read at `PersonaSessionLifecycleManager.php:134`; fix the cosmetic `:session_id`-bind-vs-`parent_session_id`-column name at `SessionStorage.php:791`; add the `meta/schema_version` marker to `memory.db`/`history.db` for parity (only `coqui.db` has it).

## Next

Phase 3 (§D runtime — D2/D3/D4). No plan yet — write via `superpowers:writing-plans`, then execute via `superpowers:subagent-driven-development`. Per user cadence, `/compact` before starting Phase 3.
