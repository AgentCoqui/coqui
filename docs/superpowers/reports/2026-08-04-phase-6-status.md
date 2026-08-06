# Phase 6 Status Report — Gate green + Flutter wire delta

**Phase:** 6 of 7 (CAP 0.5.0 conformance migration) — final implementation phase.
**Status:** ✅ COMPLETE. Both parts merge-clean; unpushed; no PRs opened (per standing constraint).

Phase 6 has two parts in two repos:
- **Part A** — gate-green close-out in the PHP migration worktree `coqui-cap-migration` (branch `feat/cap-0.5-conformance`).
- **Part B** — the Flutter client's wire-boundary delta in a new worktree `coqui-app-cap` (branch `feat/cap-0.5-wire`, cut from the app's `main`).

Executed via subagent-driven development: a fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer per task, a whole-branch review at the end.

---

## Part A — Gate-green close-out

**Range:** `4b770b5..dde68dc` (plan `7ac731f` + one task `dde68dc`).

Recon confirmed the conformance gate was **already** green after Phase 5 (all 59 CORE rows real-asserted, 2700 pass, PHPStan clean, preserve+remap roundtrip proven). The only coverage gap was direction: the `lenient` vector bucket was existence-checked but never content-validated. Task **A1** closed it.

A1 surfaced a real correction. The brief's premise (from recon) was that the lenient vector `persona.future-field.json` validates under strict `persona.json`. It does **not** — `persona.json` has root `additionalProperties: false` and does not define `reasoning_effort`, so strict validation *rejects* it. That is precisely why the vector is bucketed `lenient`, not `valid`. The implementer stopped rather than guess; the controller approved a three-part, teeth-bearing assertion instead:

1. strict `persona.json` **rejects** the vector, error pinned to `reasoning_effort` (built-in negative control);
2. a leniency-relaxed validator (a deep-copy of the schema dir with `additionalProperties:false`→`true`, living **outside** the repo) **accepts** it;
3. the same relaxed validator **still rejects** a persona missing a required `id` — proving relaxation only tolerates unknown fields, not required/structural validation.

New support: `tests/conformance/Support/LenientSchema.php`. `composer test` → **2702 pass**; `composer analyse` → `[OK]` (415 files). Every vector bucket (valid / invalid / lenient) is now content-asserted.

**Certification:** all 59 CORE rows real-asserted, zero `->todo()`; both directions (produce via `Producers/*` + builders, consume via `GoldenVectorsTest`) exercised; preserve+remap roundtrip proven through the real `ImportService`; `CatastrophicBlacklist` and the vendored spec **byte-unchanged** across Phase 6 and the whole branch from `main`.

---

## Part B — Flutter wire delta

**Repo:** `coqui-app-cap` (worktree off app `main` `2a86aae`; `feat/discord-redesign` never touched, still at `7ff7188`).
**Range:** `2a86aae..52c2a0e` (11 commits).
**Scope:** wire-boundary-only — HTTP client, DTO `fromJson`/`toJson`, SSE frame parser, and the provider seams that consume frames. Internal Dart identifiers unchanged (classes stay `CoquiProfile`/`CoquiSchedule`/`CoquiChildRun`). No net-new feature UI.

**Baseline note:** the local Flutter SDK is newer than the app targets; `flutter test` on a fresh `main` checkout is **67 pass / 10 fail**, all 10 in `test/widgets/profiles_page_test.dart` (Material/ListTile layout assertions). Every task's gate was "no *new* failures". Head is **86 pass / 10 fail** (the same 10; the +19 are new model tests). `flutter analyze` clean throughout.

| Task | Scope | Commits |
|---|---|---|
| B1 | List endpoints → `{data,next_cursor}` — **exactly the 6 cursor-page endpoints** (sessions/roles/personas/schedules/loop-definitions/artifacts); all other lists read their server named key | `28ba8a2`→`87e7076`→`80933b7` |
| B2 | `/profiles`→`/personas` paths; session persona ref `profile`→`persona_id` (value = persona **name**); persona DTO gains `id`/`version`/`avatar`; inspection/toolkit/task keys → `persona`; If-Match on persona mutations | `fa05be1`+`2a1dd82` |
| B3 | SSE turn-stream vocab — `text_delta`→`token`, `complete`→`done` (full turn record), add `question`, drop `connected`/`turn_process_id`; error frame `{error,code}`; add `answerTurn` (`{selected,text}` body) | `acbe62f`+`aa96706`+`e396df7` |
| B4 | Schedule DTO → CAP `{cron, persona_id, action:{kind,prompt\|definition_name}, status}`; create/update send + parse bare toWire; enable/disable/trigger re-fetch canonical via `getSchedule` | `55639a1`+`f894537` |
| B5 | Child-run DTO → CAP shape; `spawnChildRun`/`getChildRun`/`streamChildRunEvents` | `52c2a0e` |

**Whole-branch review (`2a86aae..52c2a0e`): Ready-to-merge = YES**, 0 Critical, 0 Important. All six cross-task coherence areas PASS: `CursorPage`/`SseEvent` have single definitions with consistent consumers; the `persona_id`-is-a-name convention holds at every send/parse site; every removed field/getter has zero surviving readers; the envelope reshape hit exactly the six intended endpoints with no regression; the tolerant-parse defaults are safe as a set; consumer seams compile and stay functionally equivalent. The reviewer flagged a cross-task *win*: B4's re-fetch closes the raw-store-row gap that the tolerant `action` parser would otherwise expose.

---

## Where the review process earned its keep

The app's tests mock at the service level, so **wrong wire keys fail no test** — the per-task review's eyes-on reading of each key was the real correctness gate. It caught, and drove fixes for, defects that would have shipped as silently-broken green:

- **B1 over-conversion.** The brief (from the app-side recon) said convert ~12 list sites; the authoritative server (`CursorPage::build` handler grep) paginates **exactly 6**. B1 initially broke ~7 endpoints (they'd read a null `data` → empty list at runtime). Narrowed to the 6, plus the artifacts endpoint I had myself dropped from the itemization.
- **B2 incomplete rename.** Two more CAP-core `persona` sites (`/toolkits` query, `/tasks` body) were outside the brief's enumeration; the four remaining `profile` sites are non-CAP-core extension endpoints (no route in the CAP server) and correctly deferred.
- **B3 blank errors.** The frame-shape change to `{error, code}` left the app reading `data['message']` → stream errors surfaced blank; fixed, with a verified sanctioned coalesce for the replay endpoint (which the server never CAP-normalized).
- **B4 corrupted toggle state.** enable/disable/trigger return a raw DB row (`enabled` int, flat action columns), not the CAP object; parsing it corrupted the DTO. Fixed by re-fetching the canonical schedule.

Several code-as-truth corrections from implementers were also correct over the plan: the `answer` body is `{selected,text}` not `{answer}`; the inspection query key is `persona` not `persona_id`; `TurnHandler::toWire`'s `done` frame *does* carry the token/duration/tools fields.

## Carry-forwards (product/UI + server follow-ups — not defects)

- **Persona rename, non-CAP-core extension endpoints:** `inspectBackstory`/`/server/backstory`, `createChannelLink`, `createWebhook`/`updateWebhook` stay on `profile` — those routes don't exist in the CAP-core server, so the key can't be verified here.
- **If-Match:** wired on persona update/delete; UI callers don't thread `version` yet.
- **Schedule editor (UI phase):** "who runs this" picker still lists roles though the field feeds `persona_id`; dropped inputs (timezone/max_iterations/max_failures/description) have no CAP home; loop actions round-trip in the DTO/service but aren't authorable in-app. The wire/service layer is correct; in-app schedule *authoring* needs a persona picker.
- **Server-side:** the stored turn-events replay endpoint (`GET /turns/{id}/events`) is not CAP-frame-normalized (returns raw `{message}` errors); the app compensates with the sanctioned coalesce. The HTTP-spawned child is toolkit-less (Phase-5 carry-forward).
- **Tidy:** extract a shared `Stream<SseEvent>` SSE-loop helper (triplicated, one copy pre-existing); parse `child-run.created_at` strictly once child-run UI matures.

## Next

Phase 6 was the final implementation phase of the CAP 0.5.0 migration. Both worktrees are merge-clean and **unpushed**; no PRs were opened, per the standing constraint. Remaining program decisions are the user's: whether to push / open PRs for `feat/cap-0.5-conformance` (core) and `feat/cap-0.5-wire` (app), and whether to schedule a UI phase for the deferred editor/answer/child-run surfaces.
