# Phase 4 Status Report — §B API surface to CAP 0.5.0

**Phase:** 4 of 7 (CAP 0.5.0 conformance migration)
**Branch:** `feat/cap-0.5-conformance` (worktree `coqui-cap-migration`, unpushed)
**Range:** `4ed4e8c..beaea0a` (22 commits: 1 plan doc + 15 tasks + 5 per-task fixes + 1 whole-branch cleanup)
**Status:** ✅ COMPLETE — whole-branch review's single blocker resolved; no Critical, no open Important.

## Goal (met)

Bind the CAP 0.5.0 contract over HTTP — a closed error catalog, cursor pagination, optimistic-concurrency versioning (If-Match → 409), typed create/PATCH/PUT bodies, typed loop/model/budget producers, content + attachment endpoints, string-cursor SSE with replay and typed frames, an aggregated `InstanceInfo` discovery document, and `Idempotency-Key` dedup — turning **26 gate rows** green.

## Acceptance evidence

- **26 CORE rows flipped to real `it(...)->group('conformance')` assertions with teeth** (todos **37 → 11**): CORE-**4, 5, 6, 7, 9, 10, 11, 18, 30, 31, 35, 36, 37, 38, 39, 40, 41, 43, 44, 45, 46, 51, 52, 53, 54, 55**. Remaining 11 todos are Phase-5/6 (CORE-2, 12, 29, 32, 47, 48, 49, 50, 56, 57, 58).
- **Green throughout:** `composer test` = **2687 passed** (from 2623 at phase start); `composer analyse` clean (407 files). Green after every task — never committed red. (One nondeterministic environmental flake, `ProcessSpawnerTest::isProcessAlive`, a spawn→liveness-check race in an untouched subsystem, was confirmed pre-existing on the stashed base and disregarded per the never-red gate — it is not a migration defect.)
- **Safety preserved:** `src/Config/CatastrophicBlacklist.php` + its test **byte-unchanged across all 22 commits** (`git log 4ed4e8c..beaea0a -- CatastrophicBlacklist.php` empty). Vendored `tests/conformance/spec/**` untouched by coqui commits.
- **Closed error catalog:** `ApiErrorCode` == the exact 23-code `error.json` set; no off-catalog code emitted anywhere; 422 is always `validation_error` + status override.

## Tasks (each: fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer; review clean)

| # | Scope | CORE | Commit(s) |
|---|---|---|---|
| 1 | Error-catalog swap (+content_not_found/version_conflict, −credential_not_found/persona_session_active) + coverage | 4, 40 | `8ac23c0` |
| 2 | Strict authoring/PATCH body helpers + persona create/patch CAP shape + version (`Precondition`/`ObjectVersionStore`) | 9, 37 | `38a8dd1`+`6fcf01c` |
| 3 | CAP session PATCH + workspace/model write + version/If-Match 409 | 54, 10 | `691ae00`+`5effc53` |
| 4 | Loop-definition PUT create/update + version (mechanism) | — | `066e9c4` |
| 5 | Role PUT create/update + version (flip) | 38 | `042b251` |
| 6 | Cursor pagination `{data,next_cursor}` + declared sort | 18 | `250f059` |
| 7 | Typed loop-live + verdict producers | 6, 7 | `29ac774` |
| 8 | Typed model catalog | 11 | `d188126` |
| 9 | Budget breakdown at `/sessions/{id}/budget` | 55 | `69ef2ff`+`c845343` |
| 10 | Content bytes/read/Range + binary upload + export content collection | 44, 45 | `bd168f6` |
| 11 | Message attachments + content-path reunification | 43 | `4a9e3b7` |
| 12 | SSE string cursor + typed per-channel frames (`complete`→`done`) | 51, 52 | `cee8317`+`825e74c` |
| 13 | SSE Last-Event-ID replay + catalog error frame | 5, 41 | `fc58895` |
| 14 | Aggregated `InstanceInfo` discovery + forward tolerance | 30, 31, 35, 36, 39, 46 | `7f82683` |
| 15 | Idempotency-Key dedup for creators | 53 | `fb70dc4`+`70d9c26` |
| WB | Whole-branch cleanup: delete orphaned `LoopLive*` dead code | — | `beaea0a` |

## Cross-task coherence (whole-branch review confirmed)

- **Shared primitives, single definition each:** `Precondition`, `ObjectVersionStore`, `DecodesRequestBody::{decodeAuthoringBody,decodePatchBody,readPrecondition}`, `CursorPage`, `SseCursor`, and the `*Producer` classes are consumed by name everywhere; a Task-3 reimplementation of `decodePatchBody` was caught and refactored to the shared helper; no other divergent copy exists; zero dangling references to deleted symbols.
- **Versioning (T2–T5):** persona/session/role/loop-def all carry `version` and honor If-Match → 409 `version_conflict`. `object_versions` backs the three file-authored objects; `sessions.version` backs the session; one PATCH bumps by exactly 1; delete-clears-counter wired where an HTTP delete exists (persona, loop-def). The role REPL-delete counter drift is a cross-surface carry-forward, not a live HTTP defect (no HTTP role delete).
- **Content path:** T10 opened a store split (content-addressed upload beside a per-session store nothing wrote); T11 closed it — `ContentStore` is the sole reader+writer, `FileUploadStorage` reduced to a MIME/size policy, message attachments flow via `content_ref`, and the superseded file list/delete routes were removed. No reader-without-writer remains.
- **SSE (T12–T13):** one shared zero-padded `SseCursor` (lexicographically order-preserving) across all three streams; internal cursor stays numeric; turn stream's closed set realized (token/message/tool_call/tool_result/done/error; `question` deferred to Phase 5; `connected` dropped as CAP-correct); replay is strictly-after; error frames catalog-coded and no-info-leak.
- **Discovery + idempotency:** `InstanceInfo.profiles` is an OPEN never-filtered set (CORE-30 checklist/schema contradiction resolved to open per CORE-39); `bindings`/`mcp.transports`/`auth.scheme` closed; `auth` omitted when embedded. `IdempotencyMiddleware` passes streaming (`text/event-stream`/unknown-length) responses through un-recorded — the flagship SSE message POST is not broken.
- **Behavioral-only Project decision (Phase 3) preserved;** producers strict / live responses additive; empty JSON objects `stdClass` not `[]` throughout.

## Carry-forwards (Phase 5/6)

- **App-facing wire deltas (Phase 6, Flutter, own branch):** list envelopes now `{data,next_cursor}`; the turn stream dropped the `connected`/`turn_process_id` frame; file list/delete routes removed; `persona_id` threading gap (from Phase 2/3). All contained; none a Phase-4 correctness defect.
- **Test-coverage follow-ups:** coqui-side closed-enum DROP path (InstanceInfo) not unit-tested (schema-side rejection is covered generically by `GoldenVectorsTest`); production `buildInstanceInfo` `models` path not exercised end-to-end.
- **Resource/tidy:** `materializeAttachment` scratch files are never reaped (and are written before the `agent_busy` check) — the correct fix is to resolve `content_ref` from `ContentStore` directly in `AgentRunner` and drop materialization; `idempotency_keys` has no TTL; role `gate` authoring field is accepted but unwired; the `normalizePersonaDetail` GET-detail surface still reads `preferences.json` not the new `identity.json`.
- **Phase-5 profile ops** (mcp/schedules/questions/child-run/artifacts) deepen what Phase 4 only *declared* in `InstanceInfo`.

## Next

Phase 5 (§C new ops + profiles): child-run ops + `child_run` SSE channel + export (CORE-29), questions Core answer path + SSE question frames (CORE-48/49), artifacts/mcp/schedules profiles (CORE-31/33/50), host+builtin toolkit declaration (CORE-30/32), `x-profile` op binding parity (CORE-47), in-process typed thrown errors (CORE-57/58). No plan yet — write via `superpowers:writing-plans`, then execute via `superpowers:subagent-driven-development`. Per user cadence, `/compact` before starting Phase 5.
