# Phase 3 Status Report — §D Runtime (D2/D3/D4) to CAP 0.5.0

**Phase:** 3 of 7 (CAP 0.5.0 conformance migration)
**Branch:** `feat/cap-0.5-conformance` (worktree `coqui-cap-migration`, unpushed)
**Range:** `5d74dae..211b0b1` (9 commits: 1 plan doc + 7 `feat(runtime)`/`test` tasks + 1 test-isolation fix)
**Status:** ✅ COMPLETE — whole-branch review "Ready to merge = Yes"; no Critical/Important findings.

## Goal (met)

Make the coqui runtime *behave* per CAP 0.5.0's three runtime disagreements — session-aware model precedence (D2), per-session workspace re-root with stage/child inheritance (D3), and "loops never block on a question; a missing stage role blocks" (D4) — turning the six behavioral gate rows CORE-15, 17, 19, 20, 21, 22, 23 green. Scope was **behavioral-only** (user decision 2026-07-31): the Project HTTP/wire surface stays intact; its teardown is deferred.

## Acceptance evidence

- **7 CORE rows are real `it(...)->group('conformance')` assertions with teeth** (CORE-15, 17, 19, 20, 21, 22, 23). Of these, **5 flipped from todo → green this phase** (17, 20, 21, 22, 23); CORE-15 and CORE-19 were already producible in Phase 2 and were **strengthened** here with behavioral assertions. Todos: **42 → 37**.
- **Green throughout:** `composer test` = **2623 passed** (from 2612 at phase start); `composer analyse` clean. Green after every task — never committed red.
- **Safety preserved:** `src/Config/CatastrophicBlacklist.php` + its test **byte-unchanged across all 9 commits** (`git log 5d74dae..211b0b1 -- CatastrophicBlacklist.php` empty). Vendored `tests/conformance/spec/**` untouched by coqui commits.

## Tasks (each: fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer; review clean)

| # | Scope | Disagreement | CORE | Commit |
|---|---|---|---|---|
| 1 | `RoleResolver::resolveForSession` (session→role→persona→instance default); threaded 4 AgentRunner sites; folded 2 group-path inline checks | D2 | 15 | `f9f0b18` |
| 2 | `SessionStorage::createSession` `workspace` param + `SessionWorkspaceResolver` + AgentRunner re-root (agents + child runs inherit) | D3 | 19 | `9889b1b` |
| 3 | Loop-stage + headless work-scope workspace inheritance; assert pre-existing prior-output threading | D3 | 21 | `0315354` |
| 4 | Delete loop question-block subsystem + `on_question`; `DefaultingQuestionResponder` auto-answers; interface tightened non-null | D4 | 20 | `645f5914` |
| 4-fix | Isolate `on_question` as the sole CORE-20 rejection reason (test-only) | D4 | 20 | `9348ce8` |
| 5 | Missing stage role at dispatch → `escalateBlocked` + Critical (was silent stall) | D4 | 23 | `0df4400` |
| 6 | `artifact_required` persona-gated **422** at loop creation (`Router::errorResponse` status override) | — | 22 | `38ee087` |
| 7 | Session delete cascade-stops non-terminal loops (`LoopStore::getLoopsBySession`) | — | 17 | `211b0b1` |

## Cross-task coherence (whole-branch review confirmed)

- **D2×D3 in AgentRunner:** both `OrchestratorAgent` build sites carry *both* changes (model via `getSession → resolveForSession`, workspace via `effectiveWorkspace($sessionId)`); no site got one without the other. Cost is one extra `getSession` round-trip in `createAgent` (perf only, not correctness).
- **D4×CORE-23 in LoopExecutor:** `pending_guidance` survives; `pending_answer` fully removed (zero dangling refs); the CORE-23 escalation sits after the legitimate "iteration complete" null-return and after the `running` status gate, so a blocked loop returns at the status gate on the next tick — no re-escalation loop.
- **Loop lifecycle:** `blocked` now means only the circuit-breaker + missing-role escalation (the orphaned question-block path is gone). The terminal set `['completed','failed','cancelled']` is used identically in `deleteSession` and the CORE-17 test.
- **Behavioral-only scope preserved:** `ProjectHandler`, `SessionProjectHandler`, `ProjectStore`, `ProjectToolkit`, all seven `/projects` routes still present and functional. Only workspace re-root was added beside them.
- **Naming/type consistency:** `resolveForSession` signature identical across all consumers; `QuestionResponderInterface::ask` tightened to non-null (all three implementers already non-null); `createSession(..., ?string $workspace)` consumed identically by LoopManager + LoopExecutor. Zero references to any deleted symbol anywhere in `src/`.

## Carry-forwards (into Phase 4+)

- **Persona-threading gap (Phase 4, response conformance):** live session/loop/scheduled-task objects can still carry a null `persona_id` (group/headless); thread a default persona at creation so live objects are wire-conformant. CORE-22's "ungated when headless/no-persona" branch is a symptom of the same gap.
- **HTTP `/sessions` workspace write (Phase 4):** plumb the create/PATCH body `workspace` through `SessionScopeResolver` + session-type handlers. Phase 3 persists `workspace` via the `createSession` parameter only; the behavioral gate sets it directly.
- **Error-catalog HTTP-status swap (Phase 4):** Task 6 added a narrow additive 422 override on `Router::errorResponse`; Phase 4 reconciles the full catalog → status map (e.g. whether authoring-op `validation_error` becomes 422 globally). At that time, also move the loop-creation gate's `LoopDefinition::fromArray` inside a guarded path — today a malformed-but-discoverable stored definition would surface as a generic dispatch-level 500 (low risk, pre-release, Router's outer try/catch prevents info leak).
- **Project wire-surface teardown (later phase):** `/projects` routes, `active_project_id` in responses, `ProjectToolkit`/`ProjectStore` — deferred out of Phase 3 by the behavioral-only decision; still owed by D3's full end-state.

## Open decisions / tidy (non-blocking, all triaged defer by the whole-branch review)

- Minor readability/perf items in AgentRunner (4× duplicated `getSession→resolveForSession` block; `doRun` second `getSession` round-trip) — optional consolidation.
- `RoleResolver` override unit test uses a non-aliased model string (alias-expansion is already covered by the existing `resolve()` tests) — optional teeth.
- CORE-17 cancel-then-delete is untransacted (matches the pre-existing untransacted `deleteSession`) — benign.

## Next

Phase 4 (§B API surface): error catalog swap, `{data,next_cursor}` pagination, If-Match/`version_conflict`, typed loop payloads, `InstanceInfo` discovery, SSE string ids + replay, Content endpoints, session PATCH, budget breakdown. Per user cadence, `/compact` before starting Phase 4.
