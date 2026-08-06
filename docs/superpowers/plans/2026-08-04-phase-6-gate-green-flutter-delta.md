# Phase 6 — Gate Green + Flutter Wire Delta Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each task is dispatched to a fresh Opus 4.8 implementer, then an Opus 4.8 spec+quality reviewer.

**Goal:** Close out the CAP 0.5.0 conformance gate (prove it is exhaustively green, including the last lenient-vector direction) and bring the Coqui Flutter client's **wire boundary** into conformance with the CAP 0.5.0 HTTP contract the core now serves.

**Architecture:** Two independent parts in two repos. **Part A** runs in the PHP migration worktree `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration` (branch `feat/cap-0.5-conformance`) — the gate is already green; the only code change is content-validating the one un-exercised `lenient` vector so every vector bucket is asserted. **Part B** runs in a *new* worktree of the Flutter app cut from its `main` branch — it changes only the HTTP client, DTO `fromJson`/`toJson`, the SSE frame parser, and the provider seams that consume frames, keeping the app compiling and every existing `flutter test` green. No net-new feature UI.

**Tech Stack:** PHP 8.4 / Pest / opis-json-schema (Part A). Dart / Flutter / `package:http` / `flutter_test` (Part B).

## Global Constraints

These bind **every** task. A reviewer treats a violation as a spec failure.

- **No-legacy / pre-release.** No installed base. No migration shims, no back-compat aliases, no dual code paths, no deprecation branches. Delete cleanly; do not keep the old wire path "just in case".
- **Part A worktree isolation.** All Part A work happens in `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration`. NEVER modify the primary checkout `/home/carmelo/Projects/CoquiBot/Core/coqui`. NEVER modify the vendored spec `tests/conformance/spec/**` (pinned to spec `5dffc63`) — Part A reads vector/schema files, never writes them. Do NOT push, do NOT open PRs.
- **SAFETY invariant (Part A).** `src/Config/CatastrophicBlacklist.php` and its test stay byte-for-byte unchanged.
- **Closed error catalog (Part A).** `ApiErrorCode` == the closed 23-code `error.json` set. No off-catalog code. Empty JSON objects serialize as `stdClass`, never `[]`.
- **Part B worktree isolation.** All Part B work happens in a NEW worktree cut from the Flutter app's `main` branch. NEVER check out, base on, or touch `feat/discord-redesign`. Do NOT push, do NOT open PRs.
- **Part B = wire boundary only.** Change: request URL paths, request/response JSON field names, query-param names, and SSE frame vocabulary — plus the minimum provider/state code needed to keep the app compiling and existing features working. Do NOT build net-new feature UI (no question-answer screen, no live child-run screen, no schedule-action editor). New endpoints land as client method + DTO + parser, reachable by future UI, not wired to new screens this phase.
- **Part B = keep internal Dart naming.** The wire renames `/profiles`→`/personas` and field `profile`→`persona_id`. Dart class/variable identifiers stay as they are (`CoquiProfile` remains `CoquiProfile`). The app-side identity/persona *symbol* rename is a separately-scoped later effort — out of scope here.
- **Green gate, every task.** Part A: `composer test` and `composer analyse` stay green after every commit. Part B: `flutter analyze` clean and `flutter test` green after every commit. Never commit red. (Known environmental flake `tests/Unit/Support/ProcessSpawnerTest.php::isProcessAlive` is nondeterministic and disregarded for the never-red gate.)
- **Ground every wire shape in the served contract.** The authoritative source for exact field names, paths, and frame types is the migration worktree: route table `src/Command/ApiCommand.php`, handlers under `src/Api/Handler/`, and vendored schemas `tests/conformance/spec/schema/*.json`. When this plan and the served contract disagree, the served contract wins — surface the discrepancy.

---

## Served contract reference (verified against the migration worktree)

Exact shapes the Flutter client must match. Base path prefix is unchanged: every route is under `/api/v1`.

- **List envelope** (`src/Api/CursorPage.php`): `{ "data": [ ... ], "next_cursor": "<opaque string>" | null }`. Replaces every named-key list wrapper (`sessions`, `messages`, `servers`, `child_runs`, `turns`, `events`, `roles`, `profiles`, `models`, `schedules`, loops, tasks).
- **Persona resource** stays name-keyed in its own routes: `GET/POST /personas`, `GET/PATCH/DELETE /personas/{name}`, `GET /config/personas`, `GET /config/personas/{name}`, `GET /config/persona-preferences/schema`. Persona object (`schema/persona.json`) now **requires**: `id`, `version` (integer ≥1, optimistic-concurrency token), `name`, `avatar`, `model`, `allowed_roles`, `soul`, `created_at`, `updated_at`. Mutations honor `If-Match: <version>` → 409 `version_conflict` on mismatch.
- **Persona reference in sessions** = field `persona_id` (NOT `profile`). Session create/resolve/update body carries `persona_id`; session objects echo `persona_id`; members carry `persona_id`. Query params `inspectPrompt`/`inspectBudget` use `persona_id`.
- **Turn SSE frame set** (`src/Api/Handler/MessageHandler.php::mapTurnEvent`) — closed: `token` `{text}`, `tool_call`, `tool_result`, `question`, `done` (carries the turn record), `error` `{error, code}`. The old `connected` frame and `turn_process_id` accessor are GONE; `text_delta`→`token`; `complete`→`done`. Frames use a string `id`; reconnection uses `Last-Event-ID` (accepted; replay is strictly-after).
- **Answer a blocking turn**: `POST /sessions/{id}/turns/{turnId}/answer` (Core path) and `POST /sessions/{id}/questions/{questionId}/answer` (questions path). Question wire object (`schema/question.json`): typed `options: [{value, label?}]`, `status` `open`, answer collapses to array/scalar/null.
- **Schedule** (`schema/scheduled-task.json`) — required: `id`, `name`, `cron`, `persona_id`, `action`, `status`, `created_at`. `action` is a `kind`-discriminated union: `{kind:"loop", definition_name:<Slug>}` or `{kind:"turn", prompt:<string>}`. `status` ∈ {`enabled`,`disabled`}.
- **Child-run**: `GET /sessions/{id}/child-runs` (list, `childRunToWire` shape), `POST /sessions/{id}/child-runs` (202, gated), `GET /sessions/{id}/child-runs/{childRunId}`, `GET /sessions/{id}/child-runs/{childRunId}/events` (SSE; frames `started|token|message|done` + terminal `error`). Child-run wire object (`schema/child-run.json`) carries a lifecycle `status`.

---

# PART A — Gate-green close-out (migration worktree)

Runs in `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration`. The conformance gate is already fully green (all 59 CORE rows real-asserted, 2700 pass, PHPStan clean, preserve+remap roundtrip proven). The one remaining gap is direction coverage: the `lenient` vector bucket is existence-checked but never content-validated. Task A1 closes it so **every** vector bucket (valid/invalid/lenient) is asserted.

### Task A1: Content-validate the lenient vector bucket

**Files:**
- Modify: `tests/conformance/Support/VectorManifest.php` (add a `lenient()` accessor mirroring `valid()`/`invalid()`)
- Modify: `tests/conformance/GoldenVectorsTest.php` (add one dataset-driven `it(...)` asserting lenient vectors validate leniently)
- Read only (NEVER modify): `tests/conformance/spec/conformance/vectors/lenient/persona.future-field.json`, `tests/conformance/spec/conformance/vectors/manifest.json`, `tests/conformance/spec/schema/persona.json`

**Interfaces:**
- Consumes: `VectorManifest::bucket(string $name): array` (existing private helper — the three public accessors are thin wrappers over it); `Support\ConformanceValidator::isValid('<obj>.json', $data): bool`.
- Produces: `VectorManifest::lenient(): array` — a Pest dataset of the `lenient` bucket entries, same entry shape as `valid()`/`invalid()`.

**Context for the implementer:** The `lenient` bucket holds forward-compatibility vectors — a persona document carrying *future* fields (`backstory`, `context`, `reasoning_effort`) that a 0.5.0 client must tolerate, not reject. CAP's forward-tolerance MUST (CORE-36) requires the schema to ACCEPT such a document. `persona.future-field.json` is the sole lenient vector. `manifest.json` already lists it under a `lenient` key (the `valid`/`invalid` buckets are read the same way). The bucket was previously excluded to mirror `validate-vectors.mjs`; we are now closing that parity gap on the produce/consume-coverage side without touching the vendored spec.

- [ ] **Step 1: Confirm the manifest exposes a `lenient` bucket.** Read `tests/conformance/spec/conformance/vectors/manifest.json` and confirm it has a top-level `lenient` array containing `persona.future-field.json` (path relative to the vectors dir). If the manifest key differs, use the actual key — the served/vendored artifact wins.

- [ ] **Step 2: Write the failing test.** In `tests/conformance/GoldenVectorsTest.php`, add after the invalid-bucket block:

```php
it('accepts every lenient (forward-compat) golden vector', function (array $entry) use ($validator) {
    $data = json_decode((string) file_get_contents($entry['path']), true, 512, JSON_THROW_ON_ERROR);
    expect($validator->isValid($entry['schema'], $data))
        ->toBeTrue($entry['name'] . ' must validate leniently (forward-compatible extra fields tolerated)');
})->with(VectorManifest::lenient())->group('conformance');
```

Match `$entry` key names (`path`, `schema`, `name`) to whatever the existing valid/invalid `it()` blocks destructure — read them first and mirror exactly.

- [ ] **Step 3: Run it to verify it fails.** `./vendor/bin/pest tests/conformance/GoldenVectorsTest.php` — Expected: FAIL with "Call to undefined method VectorManifest::lenient()" (accessor not added yet).

- [ ] **Step 4: Add the `lenient()` accessor.** In `tests/conformance/Support/VectorManifest.php`, add alongside `valid()`/`invalid()`:

```php
public static function lenient(): array
{
    return self::bucket('lenient');
}
```

If `bucket()` currently hard-excludes lenient or only knows `valid`/`invalid`, generalize it minimally to read the named bucket from the manifest. Do NOT change what `valid()`/`invalid()` return.

- [ ] **Step 5: Run the test to verify it passes.** `./vendor/bin/pest tests/conformance/GoldenVectorsTest.php` — Expected: PASS, the new `it` shows 1 lenient case.

- [ ] **Step 6: Prove the teeth (negative control, then revert).** Temporarily point the lenient assertion at a schema the document must FAIL (e.g. `session.json`) and confirm the test goes red; then revert to `persona.json`/the entry's schema and confirm green. This proves the assertion discriminates. Leave the file in the passing state.

- [ ] **Step 7: Full gate.** Run `composer test` and `composer analyse`. Expected: all green (test count = prior + 1), PHPStan `[OK]`. Confirm `git status` shows only the two harness files changed and nothing under `tests/conformance/spec/`.

- [ ] **Step 8: Commit.**

```bash
git add tests/conformance/Support/VectorManifest.php tests/conformance/GoldenVectorsTest.php
git commit -m "test(conformance): content-validate the lenient vector bucket (CORE-36 forward-tolerance direction)"
```

**Controller verification gate (not an implementer task; the controller runs this after A1 review-clean):** confirm zero `->todo()` rows; `composer test` green with counts; `composer analyse` `[OK]`; `git log <phase-base>..HEAD -- '*CatastrophicBlacklist*'` empty; `git log <phase-base>..HEAD -- 'tests/conformance/spec/**'` empty (vendored spec untouched). This certifies Part A: the gate is exhaustively green.

---

# PART B — Flutter wire delta (new worktree off app `main`)

**Setup (controller performs before Task B1, per superpowers:using-git-worktrees):** from `/home/carmelo/Projects/CoquiBot/Apps/coqui-app` (currently on `main`, clean), create a worktree on a new branch off `main`:

```bash
cd /home/carmelo/Projects/CoquiBot/Apps/coqui-app
git worktree add -b feat/cap-0.5-wire ../coqui-app-cap main
```

All Part B tasks run in `/home/carmelo/Projects/CoquiBot/Apps/coqui-app-cap`. Confirm the baseline is green there first: `flutter analyze` (clean) and `flutter test` (all pass). If the baseline is already red for reasons unrelated to this work, record it — a task's "green" gate means "no *new* failures".

The wire layer is concentrated in `lib/Services/coqui_api_service.dart` (single `CoquiApiService`, builds every URL via `_url()`, owns the SSE parser), `lib/Models/sse_event.dart` (SSE frame model), and a handful of DTOs under `lib/Models/`. Test anchors: `test/models/sse_event_test.dart`, `test/widgets/profiles_page_test.dart`, `test/models/coqui_session_test.dart`.

---

### Task B1: List endpoints → `{data, next_cursor}` envelope + cursor plumbing

**Files:**
- Create: `lib/Models/cursor_page.dart` (a tiny generic envelope type)
- Modify: `lib/Services/coqui_api_service.dart` (every list-parse site listed below)
- Test: `test/models/cursor_page_test.dart` (new)

**Interfaces:**
- Produces: `class CursorPage<T> { final List<T> data; final String? nextCursor; CursorPage(this.data, this.nextCursor); factory CursorPage.fromJson(Map<String,dynamic> json, T Function(Map<String,dynamic>) item); }` — parses `{data:[...], next_cursor:...}`; later tasks reuse it for any list.
- Consumes: nothing from other tasks.

**Context:** Today every list endpoint reads a named key (`body['sessions']`, `body['messages']`, `body['servers']`, `body['child_runs']`, `body['turns']`, `body['events']`, `body['roles']`, `body['profiles']`+`body['default_profile']`, `body['models']`, `body['schedules']`+`body['stats']`, loops, tasks). The server now returns `{data, next_cursor}` uniformly. No cursor plumbing exists in the app. Keep public method return types stable where callers only need the list — return `List<T>` from existing methods by reading `.data`, and expose `next_cursor` only where a method already supports pagination (`listSessions`, which sends `limit`). Do not invent pagination UI.

- [ ] **Step 1: Write the failing test** in `test/models/cursor_page_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:coqui_app/Models/cursor_page.dart';

void main() {
  test('CursorPage.fromJson parses data + next_cursor', () {
    final page = CursorPage<Map<String, dynamic>>.fromJson(
      {'data': [{'x': 1}, {'x': 2}], 'next_cursor': 'c_abc'},
      (m) => m,
    );
    expect(page.data.length, 2);
    expect(page.data.first['x'], 1);
    expect(page.nextCursor, 'c_abc');
  });

  test('CursorPage.fromJson tolerates null next_cursor and missing data', () {
    final page = CursorPage<Map<String, dynamic>>.fromJson(
      {'data': [], 'next_cursor': null},
      (m) => m,
    );
    expect(page.data, isEmpty);
    expect(page.nextCursor, isNull);
  });
}
```

- [ ] **Step 2: Run to verify it fails.** `flutter test test/models/cursor_page_test.dart` — Expected: FAIL, `cursor_page.dart` not found.

- [ ] **Step 3: Implement `CursorPage`** in `lib/Models/cursor_page.dart`:

```dart
class CursorPage<T> {
  final List<T> data;
  final String? nextCursor;
  const CursorPage(this.data, this.nextCursor);

  factory CursorPage.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic>) item,
  ) {
    final raw = (json['data'] as List<dynamic>?) ?? const [];
    return CursorPage(
      raw.map((e) => item(e as Map<String, dynamic>)).toList(),
      json['next_cursor'] as String?,
    );
  }
}
```

- [ ] **Step 4: Run to verify it passes.** `flutter test test/models/cursor_page_test.dart` — Expected: PASS.

- [ ] **Step 5: Repoint every list-parse site** in `lib/Services/coqui_api_service.dart` from the named key to the `data` envelope. For each site, replace `body['<oldKey>'] as List` (and the map-to-DTO that follows) with `CursorPage<DTO>.fromJson(body, DTO.fromJson).data` (or read `body['data']` directly where a DTO factory isn't used). The sites (find by the old keys — line numbers drift):
  - `sessions` (list) — also keep sending `limit`/`status`; you MAY expose `next_cursor` on this method's result.
  - `messages`, `servers`, `child_runs`, `turns`, `events`, `roles`, `models`, `schedules`, loops list, tasks list.
  - `profiles` list currently also reads `default_profile` as a sibling key — the personas list still exposes a default; read it from wherever the served `/personas` list now carries it (check `body['default_profile']` still present; if the envelope moved it, follow the served shape). This site is finalized in Task B2; in B1 just move the list array to `data` and leave `default_profile` read intact.
  - `schedules` list currently reads a sibling `stats` key — leave `stats` read intact (additive), move only the list array to `data`.
  Do NOT keep a fallback to the old key (no `body['sessions'] ?? body['data']` — no-legacy).

- [ ] **Step 6: Run the suite.** `flutter analyze` (clean) and `flutter test` (all pass, including any widget tests that parse lists). Fix any test fixture that still emits the old named-key list shape by updating it to `{data:[...], next_cursor:null}`.

- [ ] **Step 7: Commit.**

```bash
git add lib/Models/cursor_page.dart lib/Services/coqui_api_service.dart test/models/cursor_page_test.dart
git commit -m "feat(wire): parse list endpoints as {data,next_cursor} envelope"
```

---

### Task B2: Personas rename + `persona_id` references + persona DTO fields

**Files:**
- Modify: `lib/Services/coqui_api_service.dart` (all `/profiles*` paths, `/config/profile-preferences/schema`, session persona-ref bodies, `inspectPrompt`/`inspectBudget` query params)
- Modify: `lib/Models/coqui_profile.dart` (add `id`, `version`, `avatar`; reconcile keys against `persona.json`)
- Modify: `lib/Models/coqui_session.dart` (session persona field `profile`→`persona_id` on read/write)
- Test: `test/widgets/profiles_page_test.dart` (fake service + fixtures), `test/models/coqui_session_test.dart`

**Interfaces:**
- Consumes: nothing new.
- Produces: `CoquiProfile` gains `final String id; final int version; final <Avatar-or-Map> avatar;` (keep the class name `CoquiProfile`). Session persona reference is the wire field `persona_id`.

**Context:** The server renamed `/profiles`→`/personas` (resource still name-keyed: `/personas/{name}`), `/config/profile-preferences/schema`→`/config/persona-preferences/schema`, and backstory routes under `/personas/{name}/backstory...`. The session-level persona reference field is `persona_id` (create/resolve/update body, members, and query params on prompt/budget inspection). Persona objects now REQUIRE `id`, `version`, `avatar` (per `schema/persona.json`). `CoquiProfile` today has no `id`/`version`/`avatar`. Keep internal Dart identifiers as "profile"; only wire strings change. `avatar` per the schema is an object (e.g. `{tint, image_ref}`) — model it as a typed `CoquiAvatar` or a `Map<String,dynamic>`; a Map is acceptable for wire-boundary scope.

- [ ] **Step 1: Write the failing DTO test.** In `test/models/coqui_session_test.dart` (or a focused profile test), add a case asserting the NEW wire fields. For the session:

```dart
test('CoquiSession reads persona_id (not profile)', () {
  final s = CoquiSession.fromJson({
    'id': 's_1', 'model_role': 'orchestrator', 'model': 'anthropic/claude-sonnet-4',
    'persona_id': 'caelum', 'created_at': '2026-08-04T00:00:00Z', 'updated_at': '2026-08-04T00:00:00Z',
  });
  expect(s.personaId, 'caelum');
});
```

And for the persona DTO, a test that `CoquiProfile.fromJson` reads `id` + `version` + `avatar` from a persona.json-shaped object (id `p_1`, version `1`, avatar `{'tint':'#2b3a52','image_ref':null}`). Use the exact required keys from `schema/persona.json`.

- [ ] **Step 2: Run to verify failure.** `flutter test test/models/coqui_session_test.dart` — Expected: FAIL (`personaId` getter / `id`/`version` fields absent).

- [ ] **Step 3: Update the DTOs.**
  - `coqui_profile.dart`: add `id`, `version`, `avatar` fields; read them in `fromJson`; write in `toJson`. Reconcile the remaining keys against `schema/persona.json` (keep `name`, `model`, `soul`, `allowed_roles`; keep app-only prefs fields the server still returns — do not delete a field the served persona still carries). Keep the class name `CoquiProfile`.
  - `coqui_session.dart`: rename the wire read/write of the persona reference from `profile` to `persona_id`. The Dart getter may stay `profile` internally OR become `personaId` — pick `personaId` for the reference to reduce confusion, but this is an internal identifier choice, not a wire change. Update `CoquiSessionMember` similarly (`profile`→`persona_id` on the wire).

- [ ] **Step 4: Update the client paths + bodies** in `coqui_api_service.dart`:
  - Path renames: `/profiles`→`/personas` (list, create), `/profiles/$name`→`/personas/$name` (get, update, delete), `/profiles/$name/backstory*`→`/personas/$name/backstory*`, `/config/profile-preferences/schema`→`/config/persona-preferences/schema`.
  - Session bodies: `payload['profile'] = ...` → `payload['persona_id'] = ...` in `createSession`, `resolveSession`, `updateSession` (including the clear-to-`''` path). Group `members` list: send member `persona_id`s.
  - Query params: `params['profile']` → `params['persona_id']` in `inspectPrompt` and `inspectBudget`.
  - Do NOT leave any `/profiles` string or `'profile'` wire key. Grep to confirm zero remain (Dart identifier names containing "profile" are fine).

- [ ] **Step 5: Update tests + fakes.** `test/widgets/profiles_page_test.dart` builds a fake `CoquiApiService` with in-memory `CoquiProfile` fixtures keyed by name — update those fixtures to include `id`/`version`/`avatar` and any renamed method the fake overrides (e.g. if the fake stubs `getProfilePreferenceSchema`, keep the Dart method name but ensure the DTOs it returns carry the new fields). Update `coqui_session_test.dart` fixtures to `persona_id`.

- [ ] **Step 6: Run the suite.** `flutter analyze` (clean) and `flutter test` (all pass).

- [ ] **Step 7: Commit.**

```bash
git add -A
git commit -m "feat(wire): rename profiles->personas, session persona_id, persona id/version/avatar"
```

---

### Task B3: SSE frame vocabulary + answer-turn endpoint

**Files:**
- Modify: `lib/Models/sse_event.dart` (`SseEventType` enum + `SseEvent.parse` + accessors)
- Modify: `lib/Providers/chat_provider.dart` (SSE consumption seam)
- Modify: `lib/Services/coqui_api_service.dart` (add `answerTurn(...)` method)
- Test: `test/models/sse_event_test.dart`

**Interfaces:**
- Consumes: nothing new.
- Produces: `SseEventType` gains `token`, `question`; drops `connected`. `SseEvent` gains a typed accessor for token text (`data['text']`) and a `question` accessor. `CoquiApiService.answerTurn(String sessionId, String turnId, Object answer)` → `POST /sessions/{id}/turns/{turnId}/answer`.

**Context:** The server's turn stream is a CLOSED set: `token` `{text}`, `tool_call`, `tool_result`, `question`, `done` (carries the turn record), `error` `{error, code}`. The old `connected` frame and `turn_process_id` accessor are GONE. The app currently maps `text_delta`→token deltas and `complete`→completion and consumes `connected` to store `_sessionTurnProcessIds`. Since no HTTP endpoint consumes `turn_process_id` (grep-confirmed: no turn cancel/interrupt route), dropping `connected` orphans that state — remove `_sessionTurnProcessIds` and the `turn_process_id` field write. Map: `text_delta`→`token` (render `data['text']`), `complete`→`done` (read the turn-record shape the `done` frame now carries), keep `tool_call`/`tool_result`/`error`, ADD `question` (parse into provider state; NO answer UI this phase). Unknown frames already fall to `SseEventType.unknown` (safe) — coqui's former rich extras (`iteration`, `reasoning`, `review_*`, `budget_warning`, etc.) are no longer emitted on the turn stream; leave their enum members if other streams (task/loop events) still emit them, otherwise they simply never arrive. Verify which frames the loop/task event streams still emit before deleting any enum member — only delete `connected`.

- [ ] **Step 1: Rewrite the failing SSE test.** In `test/models/sse_event_test.dart`, replace the `connected`-frame assertions with the new contract:

```dart
test('parses token frame', () {
  final e = SseEvent.parse('event: token\ndata: {"text":"hel"}');
  expect(e.type, SseEventType.token);
  expect(e.tokenText, 'hel');
});
test('parses done frame as completion', () {
  final e = SseEvent.parse('event: done\ndata: {"turn_process_id":"tp_1","status":"completed"}');
  expect(e.type, SseEventType.done);
});
test('parses question frame', () {
  final e = SseEvent.parse('event: question\ndata: {"question_id":"q_1","prompt":"Pick one","options":[{"value":"a"}]}');
  expect(e.type, SseEventType.question);
});
test('connected is no longer a known type', () {
  expect(SseEventType.fromString('connected'), SseEventType.unknown);
});
```

Match `SseEvent.parse`'s actual frame format (the existing test shows how `event:`/`data:` lines are fed) and the accessor names it already uses; adapt the assertions to the real API surface.

- [ ] **Step 2: Run to verify failure.** `flutter test test/models/sse_event_test.dart` — Expected: FAIL (`token`/`question` absent; `connected` still known; `tokenText` missing).

- [ ] **Step 3: Update `sse_event.dart`.** Add `token`, `question` to `SseEventType`; remove `connected` and its `'connected' => ...` mapping and the `turnProcessId` accessor. Add `String? get tokenText => data['text'] as String?;` and a `Map<String,dynamic>? get question => ...`. Map string `'token'`/`'question'` in `fromString`/parse.

- [ ] **Step 4: Update `chat_provider.dart` consumption.** In the SSE event loop (the big `switch` around the streaming handler): replace the `case SseEventType.textDelta` token-append with `case SseEventType.token` reading `event.tokenText`; replace `case SseEventType.complete`/`connected` handling — completion now keys off `SseEventType.done` and reads the turn record from the `done` frame; delete the `_sessionTurnProcessIds` map, its writes (`case connected`), reads, and clears, and the `'turn_process_id'` field stamped onto completed turns. Add `case SseEventType.question:` that stores the question payload in provider state (a field like `pendingQuestion`); do NOT build answer UI. Ensure the completion-summary keys the app reads off the old `complete` frame are read off whatever the `done`/turn-record frame now carries (adjust or drop keys the new frame doesn't include — no fabricated defaults).

- [ ] **Step 5: Add the answer client method** in `coqui_api_service.dart`:

```dart
Future<void> answerTurn(String sessionId, String turnId, Object answer) async {
  final res = await http.post(
    _url('/sessions/$sessionId/turns/$turnId/answer'),
    headers: _headers(),
    body: jsonEncode({'answer': answer}),
  );
  _parseResponse(res); // throws CoquiException on catalog error
}
```

Match the request body key the server expects (`schema/question.json` answer shape — array/scalar/null; confirm the handler's expected body field name in `QuestionHandler::submitTurnAnswer`). Use the app's existing header/parse helpers.

- [ ] **Step 6: Run the suite.** `flutter analyze` (clean) and `flutter test` (all pass). Fix any widget/provider test that asserted the old `connected`/`text_delta`/`complete` behavior.

- [ ] **Step 7: Commit.**

```bash
git add -A
git commit -m "feat(wire): CAP turn SSE frames (token/done/question), drop connected, add answerTurn"
```

---

### Task B4: Schedule reshape — `action.kind` union + `persona_id` + `cron`

**Files:**
- Modify: `lib/Models/coqui_schedule.dart` (DTO)
- Modify: `lib/Services/coqui_api_service.dart` (create/update payloads, response parsing)
- Test: `test/models/coqui_schedule_test.dart` (new)

**Interfaces:**
- Consumes: nothing new.
- Produces: `CoquiSchedule` reshaped to `{id, name, cron, personaId, action: ScheduleAction, status, ...}` where `ScheduleAction` is a discriminated union (`kind` `turn`→`prompt`, `loop`→`definitionName`).

**Context:** The app's schedule DTO is flat legacy (`schedule_expression`, `prompt`, `role`, `enabled`, ...). CAP requires `cron` (not `schedule_expression`), `persona_id`, `action:{kind, prompt|definition_name}`, `status` ∈ {`enabled`,`disabled`}. Create/update/enable/disable/trigger responses today read nested `body['schedule']` while list reads flat — the list envelope moved to `data` in B1; the single-object responses may still be `{schedule: {...}}` (additive-live) — follow the served shape (check `ScheduleManager`/schedule handler response). Model the action union in Dart.

- [ ] **Step 1: Write the failing DTO test** in `test/models/coqui_schedule_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:coqui_app/Models/coqui_schedule.dart';

void main() {
  test('parses a turn-action schedule', () {
    final s = CoquiSchedule.fromJson({
      'id': 'sch_1', 'name': 'daily', 'cron': '0 9 * * *', 'persona_id': 'caelum',
      'action': {'kind': 'turn', 'prompt': 'Summarize inbox'}, 'status': 'enabled',
      'created_at': '2026-08-04T00:00:00Z',
    });
    expect(s.cron, '0 9 * * *');
    expect(s.personaId, 'caelum');
    expect(s.action.kind, 'turn');
    expect(s.action.prompt, 'Summarize inbox');
    expect(s.status, 'enabled');
  });

  test('parses a loop-action schedule', () {
    final s = CoquiSchedule.fromJson({
      'id': 'sch_2', 'name': 'nightly', 'cron': '0 2 * * *', 'persona_id': 'caelum',
      'action': {'kind': 'loop', 'definition_name': 'research'}, 'status': 'disabled',
      'created_at': '2026-08-04T00:00:00Z',
    });
    expect(s.action.kind, 'loop');
    expect(s.action.definitionName, 'research');
  });
}
```

- [ ] **Step 2: Run to verify failure.** `flutter test test/models/coqui_schedule_test.dart` — Expected: FAIL (`cron`/`personaId`/`action` absent).

- [ ] **Step 3: Reshape the DTO.** In `coqui_schedule.dart`: add a `ScheduleAction` type (`final String kind; final String? prompt; final String? definitionName;` with `fromJson`/`toJson`), and reshape `CoquiSchedule` to read `cron`, `persona_id`, `action`, `status` (drop `schedule_expression`, flat `prompt`, `role` — no-legacy). Keep additive fields the server still returns (`next_run_at`, `last_status`, `run_count`, etc.) if `scheduled-task.json` / the handler still emit them.

- [ ] **Step 4: Update create/update payloads** in `coqui_api_service.dart`: `createSchedule`/`updateSchedule` now send `{name, cron, persona_id, action:{kind, prompt|definition_name}, ...}`. Update single-object response parsing to the served shape (`body['schedule']` if still nested, else `body` directly — verify).

- [ ] **Step 5: Run the suite.** `flutter analyze` (clean) and `flutter test` (all pass). Update any schedule provider/widget test fixture to the new shape.

- [ ] **Step 6: Commit.**

```bash
git add -A
git commit -m "feat(wire): schedule action.kind union + persona_id + cron"
```

---

### Task B5: Child-run live shape + spawn/get/events client methods

**Files:**
- Modify: `lib/Models/coqui_child_run.dart` (DTO → CAP child-run shape)
- Modify: `lib/Services/coqui_api_service.dart` (add `spawnChildRun`, `getChildRun`, `streamChildRunEvents`; `listChildRuns` already envelope-fixed in B1)
- Test: `test/models/coqui_child_run_test.dart` (new)

**Interfaces:**
- Consumes: `CursorPage` (B1) for the list; `SseEvent` (B3) for event frames.
- Produces: `CoquiChildRun` reshaped to the `child-run.json` wire object (with lifecycle `status`); `CoquiApiService.spawnChildRun(...)` → `POST /sessions/{id}/child-runs` (202), `getChildRun(...)` → `GET /sessions/{id}/child-runs/{childRunId}`, `streamChildRunEvents(...)` → SSE over `GET /sessions/{id}/child-runs/{childRunId}/events`.

**Context:** `listChildRuns` GET already exists (B1 moved it to `data`). But `CoquiChildRun` is a *historical* record shape (`parent_iteration`, `agent_role`, `result`, ...) — the CAP child-run object (`schema/child-run.json`) is a live run with a lifecycle `status` (pending/running/completed/failed). Reconcile the DTO against the schema. Add the three net-new client methods (client + parser only; no live child-run screen). The event stream mirrors the existing task-event streaming pattern (`/tasks/{id}/events`) — frames `started|token|message|done` + terminal `error`; reuse `SseEvent`.

- [ ] **Step 1: Write the failing DTO test** in `test/models/coqui_child_run_test.dart`, asserting `CoquiChildRun.fromJson` reads the `child-run.json` required fields including `status`. Read `tests/conformance/spec/schema/child-run.json` in the migration worktree for the exact required keys, and build the fixture from them (id, parent/session ref, status, created_at, etc.).

- [ ] **Step 2: Run to verify failure.** `flutter test test/models/coqui_child_run_test.dart` — Expected: FAIL.

- [ ] **Step 3: Reshape `CoquiChildRun`** to the schema (add `status` + the CAP fields; drop historical-only fields the served object no longer carries — verify against the schema and the `childRunToWire` producer).

- [ ] **Step 4: Add the three client methods** in `coqui_api_service.dart`:
  - `Future<CoquiChildRun> spawnChildRun(String sessionId, {required String role, String? prompt})` → `POST /sessions/$sessionId/child-runs`, expect 202, parse the returned child-run.
  - `Future<CoquiChildRun> getChildRun(String sessionId, String childRunId)` → `GET /sessions/$sessionId/child-runs/$childRunId`.
  - `Stream<SseEvent> streamChildRunEvents(String sessionId, String childRunId)` → mirror the existing task-events SSE method (`GET /sessions/$sessionId/child-runs/$childRunId/events`, split on `\n\n`, `SseEvent.parse`).
  Match the spawn request body to what `ChildRunHandler::spawnChildRun` expects (check the handler in the migration worktree — role/allowed_roles/prompt).

- [ ] **Step 5: Run the suite.** `flutter analyze` (clean) and `flutter test` (all pass).

- [ ] **Step 6: Commit.**

```bash
git add -A
git commit -m "feat(wire): CAP child-run live DTO + spawn/get/events client methods"
```

---

## Cross-task notes

- **B1 before B2/B4/B5:** the `{data,next_cursor}` helper is shared; personas/schedule/child-run lists depend on it.
- **B3 before B5:** child-run event streaming reuses the `SseEvent` frame model B3 finalizes.
- **Reviewer lens for every Part B task:** wire-boundary-only (no net-new UI); no legacy fallbacks (`old ?? new`); internal Dart naming unchanged; `flutter analyze` clean + `flutter test` green; wire field names/paths match the served contract reference above (and, where in doubt, the migration worktree's schema/handler — the served contract wins).

## Deliverable

After B5 review-clean and the whole-branch review, the controller writes `docs/superpowers/reports/2026-08-04-phase-6-status.md` (in the migration worktree) covering both parts: Part A gate-green certification (exhaustive vector coverage, all 59 rows, safety byte-check) and Part B wire-delta summary (the app now speaks CAP 0.5.0; new endpoints wired as client+DTO+parser; per-task commits; carry-forwards = net-new feature UI for questions/child-runs/schedules deferred to a product phase).

## Self-review notes (author)

- **Spec coverage:** Part A closes the sole gate gap (lenient direction). Part B covers all four existing-touchpoint deltas (envelopes B1, personas+persona_id B2, SSE frames B3, schedule B4) plus net-new endpoints (answer B3; child-run spawn/get/events B5). Every carry-forward from the Phase 4/5 reports' "App-facing wire deltas" list maps to a task.
- **Placeholder scan:** test code is concrete; implementation steps give exact wire strings (`persona_id`, `cron`, `token`, `{data,next_cursor}`) and exact paths. Where a full method body can't be pinned without reading the live file, the step names the exact field/path substitution and points at the served schema/handler — not a vague "handle it".
- **Type consistency:** `CursorPage<T>` (B1) is consumed by name in B5; `SseEvent`/`SseEventType.token|question` (B3) consumed in B5; `persona_id` is the single persona-reference wire field across B2/B4/B5; `CoquiProfile` keeps its class name throughout.
