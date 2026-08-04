# Phase 4 — §B API Surface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task (fresh Opus 4.8 implementer per task, Opus 4.8 spec+quality reviewer per task, whole-branch review at the end). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bind the CAP 0.5.0 contract over HTTP — a closed error catalog, cursor pagination, optimistic-concurrency versioning (If-Match → 409), typed create/PATCH/PUT bodies, typed loop/model/budget producers, content + attachment endpoints, string-cursor SSE with replay and typed frames, an aggregated `InstanceInfo` discovery document, and `Idempotency-Key` — turning the 26 gate rows CORE-4, 5, 6, 7, 9, 10, 11, 18, 30, 31, 35, 36, 37, 38, 39, 40, 41, 43, 44, 45, 46, 51, 52, 53, 54, 55 green.

**Architecture:** Phases 2–3 made every Core object *producible* and made the runtime *behave*. Phase 4 makes the HTTP wire *conform*. The work is layered: (1) an error-catalog swap gives later tasks their `version_conflict`/`content_not_found` codes and the "422 = `validation_error` code + 422 status" convention; (2) shared strict-body + precondition helpers on `DecodesRequestBody` carry authoring/PATCH/PUT strictness and If-Match parsing; (3) a single `object_versions` store backs optimistic concurrency for the three file-authored mutable objects (persona/role/loop-definition) while `sessions.version` backs the session; (4) new `toWire` producers (loop-live, verdict, model, budget-breakdown, message, InstanceInfo) emit strictly-schema-valid instances; (5) a shared SSE cursor + typed per-channel frame layer replaces the raw int-id passthrough; (6) an `IdempotencyMiddleware` dedups creators. Every change is **additive-response / strict-producer** per the Phase-2 split, except where a body shape is being *replaced* outright (no-legacy).

**Tech Stack:** PHP 8.4 (`declare(strict_types=1)`, `final` by default, constructor injection), SQLite via PDO, ReactPHP HTTP (`React\Http\Message\{ServerRequest,Response}`), Pest (`composer test`), PHPStan (`composer analyse`). Conformance harness: `tests/conformance/` (`ConformanceValidator::isValid('<obj>.json', $data)` + `errorText(...)`, `CoreChecklistTest.php` scoreboard, `GoldenVectorsTest` already validates every vendored vector against its schema).

## Global Constraints

Every task's requirements implicitly include this section.

- **Worktree only.** All work in `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration` (branch `feat/cap-0.5-conformance`, unpushed). NEVER touch the primary checkout `/home/carmelo/Projects/CoquiBot/Core/coqui` or the vendored spec under `tests/conformance/spec/**` (pinned to spec `5dffc63`). Do NOT push, do NOT open PRs.
- **No-legacy / pre-release.** No installed base. No migration shims, no back-compat aliases, no dual code paths. When a served body shape diverges from the CAP schema (persona-create, session-patch, scheduled-task-patch field sets), **replace it outright** — do not alias old field names.
- **The closed error catalog is authoritative.** `schema/error.json` defines exactly 23 codes. coqui's `ApiErrorCode` enum MUST be brought to that identical set. **There is no `unprocessable_entity`/422 code** — a 422 response is emitted as the `validation_error` **code** with HTTP **status 422** via the Phase-3 `Router::errorResponse(ApiErrorCode $code, string $msg, mixed $details, ?int $status)` override (exactly as CORE-22 does). Never invent an off-catalog code.
- **`details` is always an object.** `error.json` types `details` as `object`; any `details` passed to `errorResponse` must be an associative array/`stdClass`, never a scalar (empty ⇒ `stdClass`, never `[]`).
- **Empty JSON objects** emit as `stdClass`, never `[]` (Phase-2 gotcha) — applies to every new producer (`avatar`, `metadata`, `budget`, `rate_limit`, `mcp`, …).
- **Producers are strict; live responses are additive.** New `toWire` conformance producers validate against `schema/*.json` (`additionalProperties:false` shapes). A separate live/list response MAY carry extra keys, but the strict producer is the gate. Do not make an existing broad live response strict in a way that breaks unrelated consumers unless the task explicitly replaces that shape.
- **Safety invariants.** `src/Config/CatastrophicBlacklist.php` and its test stay byte-for-byte unchanged (verify `git log <base>..HEAD -- src/Config/CatastrophicBlacklist.php` is empty each task). Audit logging, shell/fs sandboxing, destructive-op gating, and the `AuthMiddleware` bearer gate stay intact.
- **Do NOT touch the capability sense of "profile".** `ToolProfileResolver`, `toolProfile`, `TOOL_PROFILE_*`, perf `test:profile` / `COQUI_TEST_PROFILE_*`, `LeanDefaultProfilePrecedenceTest` are out of scope. The CAP `profiles[]` (InstanceInfo) sense is a **new, open string set** and MUST NOT reuse any renamed identity/tool-profile code.
- **Behavioral-only Project decision still holds (Phase 3).** Do not tear down the `/projects` routes / `ProjectStore` / `ProjectToolkit` this phase. Only add beside them.
- **Green per task.** `composer test` and `composer analyse` MUST pass at the end of every task. Never commit red. Commit once per task with a `feat(api):` / `feat(sse):` / `test(cap):` message.
- **Conformance test style.** Flipping a row green = removing its string from the `$rows` array (lines ~772–813) of `tests/conformance/CoreChecklistTest.php` and adding a real `it('CORE-N: …', …)->group('conformance')`. Namespace `CoquiBot\Coqui\Tests\Conformance`; `use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;`. Behavioral HTTP rows build handlers directly over a temp SQLite DB + temp workspace and assert on a `React\Http\Message\Response` (pattern: the existing CORE-22 test at `CoreChecklistTest.php:235`). Use the temp-db/temp-tree cleanup helpers already in the suite (`cleanupSqliteTestDb`, `cleanupTestTree`).

## Adjudications (resolved before execution — see Pre-Flight)

- **CORE-30 "profiles are a closed set" is a vendored-spec self-contradiction.** `schema/instance-info.json` puts **no `enum`** on `profiles.items` (plain `{"type":"string"}`, root `additionalProperties:true`); `valid/instance-info.future-profile.json` (`"telepathy"`) MUST validate; CORE-39 requires the open set; and the `invalid/instance-info.bad-profile.json` vector the checklist references **does not exist** in the vendored spec. The schema + CORE-39 govern: **`profiles` is OPEN.** The CORE-30 assertion asserts *host_toolkits are declared* + *profiles is an open array (an unknown profile validates)*, not a bad-profile rejection.
- **Memory HTTP CRUD is out of Phase-4 scope.** coqui has no memory HTTP endpoint and the internal `MemoryStore` shape (`area/tags/importance/memory_type`) ≠ CAP `memory.json` (`name/content/type`). CORE-9 (PATCH strictness) is satisfied via the real **persona** PATCH handler; the `memory-patch.json` schema is already validated generically by `GoldenVectorsTest`. A CAP-shaped memory store/endpoint is a documented carry-forward (needs its own reshape), not a §B binding row.
- **Version storage.** `sessions.version` (a real DB column) backs the session. A single new `object_versions(object_type, object_name, version, updated_at)` store backs the three **file-authored** mutable objects (persona/role/loop-definition) as an optimistic-concurrency counter — the faithful realization of design D1 ("the same hybrid applies to roles and loop-definitions; persisted rows expose `version` for the PUT/If-Match contract"). The Phase-2 `personas` snapshot table's `version` is an internal sync artifact; the API's optimistic-concurrency `version` is served from `object_versions`.

---
### Task 1: Error-catalog swap + coverage (CORE-4, CORE-40)

Bring `ApiErrorCode` to the spec's exact 23-code closed set and prove per-operation coverage.

**Files:**
- Modify: `src/Api/ApiErrorCode.php` (enum cases + `httpStatus()` match)
- Modify: `src/Api/Handler/CredentialHandler.php:91` (drop `CREDENTIAL_NOT_FOUND` throw)
- Modify: `src/Support/InteractiveSessionService.php:258` (`personaSessionActiveConflict()`)
- Modify: `tests/Unit/Api/Handler/SessionHandlerTest.php:675,777` (assertions on the dropped code)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-4 and CORE-40)

**Interfaces:**
- Consumes: `schema/error.json` closed enum (23 codes); `conformance/error-coverage.json` (44 operations → arrays of HTTP status strings `401,403,404,409,412,413,415,422`).
- Produces: `ApiErrorCode` cases `CONTENT_NOT_FOUND='content_not_found'`(404), `VERSION_CONFLICT='version_conflict'`(409); the enum's `->value` set == `error.json` enum set.

The spec's 23 codes: `not_found, validation_error, conflict, unauthorized, forbidden, internal_error, agent_busy, missing_field, invalid_format, role_not_found, role_builtin, role_reserved, session_not_found, session_closed, turn_not_found, content_not_found, question_not_found, question_invalid_answer, group_session_active, version_conflict, rate_limited, payload_too_large, unsupported_media_type`. coqui today has all of these EXCEPT `content_not_found` + `version_conflict`, and has TWO extras not in the set: `credential_not_found`, `persona_session_active`.

- [ ] **Step 1: Write the failing scoreboard tests** in `tests/conformance/CoreChecklistTest.php` (remove the `'CORE-4: …'` and `'CORE-40: …'` strings from `$rows`; add):

```php
it('CORE-4: the ApiErrorCode catalog is exactly the closed error.json code set', function () {
    $schema = json_decode(file_get_contents(__DIR__ . '/spec/schema/error.json'), true, flags: JSON_THROW_ON_ERROR);
    $catalog = $schema['properties']['code']['enum'];
    sort($catalog);
    $coqui = array_map(fn (ApiErrorCode $c) => $c->value, ApiErrorCode::cases());
    sort($coqui);
    // Exact set equality: complete (every catalog code exists) AND closed (no extras).
    expect($coqui)->toBe($catalog);
})->group('conformance');

it('CORE-40: every HTTP status documented in error-coverage.json is produced by some catalog code', function () {
    $coverage = json_decode(
        file_get_contents(__DIR__ . '/spec/conformance/error-coverage.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $documented = [];
    foreach ($coverage as $statuses) {
        foreach ($statuses as $s) {
            $documented[(int) $s] = true;
        }
    }
    // Every documented status is reachable from the closed catalog. 412 is the If-Match
    // precondition status emitted with the version_conflict code + a ?int status override.
    $reachable = [412 => true];
    foreach (ApiErrorCode::cases() as $c) {
        $reachable[$c->httpStatus()] = true;
    }
    foreach (array_keys($documented) as $status) {
        expect($reachable)->toHaveKey($status, "status {$status} has no catalog code");
    }
})->group('conformance');
```

- [ ] **Step 2: Run — expect failure** (`credential_not_found`/`persona_session_active` still present, `content_not_found`/`version_conflict` absent):

Run: `./vendor/bin/pest tests/conformance/CoreChecklistTest.php --filter='CORE-4|CORE-40'`
Expected: FAIL on set inequality.

- [ ] **Step 3: Swap the enum.** In `src/Api/ApiErrorCode.php`: delete `case CREDENTIAL_NOT_FOUND = 'credential_not_found';` and `case PERSONA_SESSION_ACTIVE = 'persona_session_active';`; add `case CONTENT_NOT_FOUND = 'content_not_found';` and `case VERSION_CONFLICT = 'version_conflict';`. In the `httpStatus()` match (`:64-75`): remove the two dropped arms; add `self::CONTENT_NOT_FOUND => 404,` and `self::VERSION_CONFLICT => 409,`.

- [ ] **Step 4: Repoint the two dropped call sites** (no-legacy — the credentials/persona-conflict surfaces stay, only their code changes):
  - `CredentialHandler.php:91`: throw/emit `ApiErrorCode::NOT_FOUND` instead of `CREDENTIAL_NOT_FOUND` (message unchanged).
  - `InteractiveSessionService.php:258` `personaSessionActiveConflict()`: construct the `SessionTypeException` with `ApiErrorCode::CONFLICT` (409, the catalog's generic conflict) instead of `PERSONA_SESSION_ACTIVE`. Keep the `details` payload (`persona_id`, `active_session_id`, `active_session_count`, `confirm_field`) — it's an object, schema-legal.
  - `SessionHandlerTest.php:675,777`: change the two `expect($body['code'])->toBe('persona_session_active')` assertions to `->toBe('conflict')`.

- [ ] **Step 5: Run the full suite + analyse.**

Run: `composer test && composer analyse`
Expected: green; CORE-4 + CORE-40 pass; the existing `GoldenVectorsTest` case for `valid/error.content-not-found.json` still validates (schema unchanged; the code just became emittable).

- [ ] **Step 6: Commit** — `feat(api): align ApiErrorCode with the closed CAP catalog (CORE-4/40)`.

---

### Task 2: Strict authoring/PATCH bodies + persona create/patch CAP shape + persona version (CORE-9, CORE-37)

Add reusable strict-body helpers and apply them to the persona surface, which is where CAP's authoring-vs-persisted split first bites. Reshape persona create/patch to the CAP field sets (no-legacy replacement) and serve/enforce persona `version`.

**Files:**
- Modify: `src/Api/Handler/DecodesRequestBody.php` (add `decodeAuthoringBody`, `decodePatchBody`, `readPrecondition`)
- Create: `src/Api/Precondition.php` (tiny value object for If-Match / If-None-Match)
- Create: `src/Storage/ObjectVersionStore.php` (optimistic-concurrency counter for file-authored objects)
- Modify: `src/Storage/SessionStorage.php` (add the `object_versions` table to `createTables()`)
- Modify: `src/Api/Handler/ConfigHandler.php` (`createPersona` ~L421, `updatePersona` ~L501, `normalizePersonaSummary` ~L697)
- Test: `tests/Unit/Api/PreconditionTest.php`, `tests/Unit/Storage/ObjectVersionStoreTest.php`, `tests/Unit/Api/Handler/ConfigHandlerTest.php` (extend)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-9, CORE-37)

**Interfaces:**
- Produces:
  - `Precondition` (readonly): `::fromRequest(ServerRequest): self`; `bool $isCreate` (If-None-Match: `*`), `?int $expectedVersion` (If-Match integer), `bool $isUnconditional` (neither header).
  - `ObjectVersionStore(PDO $db)`: `current(string $type, string $name): int` (0 = absent), `create(string $type, string $name): int` (→1; throws if present), `bump(string $type, string $name): int`, `delete(string $type, string $name): void`.
  - `DecodesRequestBody::decodeAuthoringBody(ServerRequest $r, array $required, array $optional): array` — decodes JSON; rejects any key ∉ `required∪optional` (server-owned field ⇒ 422 `validation_error`), rejects a missing `required` key (422), returns the assoc body. Throws a `RequestBodyException` carrying an `ApiErrorCode` + status the handler renders via `Router::errorResponse(code, msg, details, 422)`.
  - `DecodesRequestBody::decodePatchBody(ServerRequest $r, array $allowed): array` — decodes; rejects unknown keys (422); rejects empty `{}` (422 `validation_error`, "at least one field required"); returns the assoc body.
- Consumes: `schema/persona.create.json` (required `name,avatar,model,allowed_roles,soul`; optional `backstory,context,preferences`; `additionalProperties:false`), `schema/persona-patch.json` (props `name,avatar,model,allowed_roles,soul,backstory,context,preferences`; `additionalProperties:false`, `minProperties:1`), `schema/persona.json` (served shape, requires `version`).

**`object_versions` DDL** (add to `SessionStorage::createTables()`, recreate-from-empty — no ALTER):

```sql
CREATE TABLE IF NOT EXISTS object_versions (
    object_type TEXT NOT NULL,
    object_name TEXT NOT NULL,
    version     INTEGER NOT NULL DEFAULT 1,
    updated_at  TEXT NOT NULL,
    PRIMARY KEY (object_type, object_name)
);
```

- [ ] **Step 1: Write failing helper unit tests.** `PreconditionTest`: `If-None-Match: *` ⇒ `isCreate` true; `If-Match: 3` ⇒ `expectedVersion===3`; neither ⇒ `isUnconditional`. `ObjectVersionStoreTest`: `current` on absent ⇒ 0; `create` ⇒ 1; second `create` throws; `bump` ⇒ 2,3,…. Run: `./vendor/bin/pest tests/Unit/Api/PreconditionTest.php tests/Unit/Storage/ObjectVersionStoreTest.php` → FAIL (classes undefined).

- [ ] **Step 2: Implement `Precondition`, `ObjectVersionStore`, the `object_versions` table, and the two `DecodesRequestBody` helpers.** `readPrecondition` reads `$r->getHeaderLine('If-None-Match')` / `$r->getHeaderLine('If-Match')`. The strict helpers reject via a `RequestBodyException` (new small exception in `src/Exception/`) carrying `ApiErrorCode::VALIDATION_ERROR` + intended status 422 + a `details` object naming the offending field(s). Run Step-1 tests → PASS.

- [ ] **Step 3: Reshape persona create/patch (no-legacy) + serve version.**
  - `createPersona`: replace the old free-form read with `decodeAuthoringBody($r, ['name','avatar','model','allowed_roles','soul'], ['backstory','context','preferences'])`. Reject server-owned fields (`id`/`version`/`created_at`/`updated_at`) automatically (they're not in the allow-set ⇒ 422). Write the persona files as today (soul.md etc.), map `avatar`/`preferences`/`allowed_roles`/`model` into the authoring files, then `ObjectVersionStore::create('persona', $name)`. Return 201 with the served persona including `version:1`.
  - `updatePersona`: replace with `decodePatchBody($r, ['name','avatar','model','allowed_roles','soul','backstory','context','preferences'])` (rejects unknown + empty). Read `Precondition::fromRequest`; if `expectedVersion` is set and ≠ `ObjectVersionStore::current('persona',$name)` ⇒ `errorResponse(VERSION_CONFLICT, …, null, 409)`. Apply the patch, then `bump`. Serve the new version.
  - `normalizePersonaSummary` (`:697`): include `version` from `ObjectVersionStore::current('persona', $name)` (default 1 for pre-existing file personas — treat absent as version 1 and lazily `create` on first write). Wire an `ObjectVersionStore` into `ConfigHandler` via its constructor (thread from `ApiCommand` where `ConfigHandler` is built).

- [ ] **Step 4: Write the failing conformance rows.** Remove `'CORE-9: …'` + `'CORE-37: …'` from `$rows`; add real tests that drive the **real** `ConfigHandler` over a temp workspace+db (mirror the CORE-22 harness):

```php
it('CORE-9: persona PATCH rejects unknown fields and an empty body', function () {
    [$handler, $workspace, $dbPath] = makePersonaConfigHandler(); // helper builds ConfigHandler + ObjectVersionStore over temp fs/db and seeds one persona "caelum"
    try {
        $unknown = $handler->updatePersona(personaPatchRequest('caelum', ['telepathy' => true]));
        expect($unknown->getStatusCode())->toBe(422);
        expect(json_decode((string) $unknown->getBody(), true)['code'])->toBe('validation_error');

        $empty = $handler->updatePersona(personaPatchRequest('caelum', []));
        expect($empty->getStatusCode())->toBe(422);
    } finally { cleanupTestTree($workspace); cleanupSqliteTestDb($dbPath); }
})->group('conformance');

it('CORE-37: persona create rejects a server-owned field (422) and accepts the authoring shape', function () {
    [$handler, $workspace, $dbPath] = makePersonaConfigHandler();
    try {
        $bad = $handler->createPersona(personaCreateRequest([
            'id' => '01J000000000000000000PERSONA', // server-owned ⇒ reject
            'name' => 'nova', 'avatar' => new \stdClass(),
            'model' => 'anthropic/claude-sonnet-4', 'allowed_roles' => ['orchestrator'], 'soul' => 'x',
        ]));
        expect($bad->getStatusCode())->toBe(422);
        expect(json_decode((string) $bad->getBody(), true)['code'])->toBe('validation_error');

        $ok = $handler->createPersona(personaCreateRequest([
            'name' => 'nova', 'avatar' => new \stdClass(),
            'model' => 'anthropic/claude-sonnet-4', 'allowed_roles' => ['orchestrator'], 'soul' => 'x',
        ]));
        expect($ok->getStatusCode())->toBe(201);
        $body = json_decode((string) $ok->getBody(), true);
        $v = new ConformanceValidator();
        // The served persona is a schema-valid persona.json with version 1.
        expect($v->isValid('persona.json', $handler->servedPersonaWire('nova')))
            ->toBeTrue($v->errorText('persona.json', $handler->servedPersonaWire('nova')));
        expect($body['version'] ?? ($handler->servedPersonaWire('nova')['version']))->toBe(1);
    } finally { cleanupTestTree($workspace); cleanupSqliteTestDb($dbPath); }
})->group('conformance');
```

  Define the `makePersonaConfigHandler()` / `personaCreateRequest()` / `personaPatchRequest()` closures at the top of the test file (or a shared `tests/conformance/Pest.php` helper) — small factories over the real handler. If serving a strict `persona.json` requires a helper not on `ConfigHandler`, add a thin `ConfigHandler::servedPersonaWire(string $name): array` that returns the strict producer output (reuse `PersonaSnapshotStore::toWire` semantics). Keep the live list response additive.

- [ ] **Step 5: Run + commit.** `composer test && composer analyse` green. Commit `feat(api): strict persona create/patch bodies + version (CORE-9/37)`.

---

### Task 3: Session PATCH CAP fields + workspace/model write + version + If-Match 409 (CORE-54, CORE-10)

Replace the session PATCH body with the CAP field set, wire the Phase-3-deferred `workspace`/`model` write plumbing, reject empty patches, and add optimistic concurrency on `sessions.version`.

**Files:**
- Modify: `src/Api/Session/SessionUpdateRequestResolver.php` (CAP fields; reject empty)
- Modify: `src/Api/Session/SessionUpdateRequest.php` (DTO: `workspace`/`model`/`pinned`/`status` + `updates*` flags + null-clear distinction via `array_key_exists`)
- Modify: `src/Api/Handler/SessionHandler.php:185` (`update` — read `Precondition`; 409 on stale version)
- Modify: `src/Support/InteractiveSessionService.php:130` + `src/Api/Session/GroupSessionTypeHandler.php:56` (apply workspace/model/pinned/status)
- Modify: `src/Storage/SessionStorage.php` (add `updateSessionWorkspace(id, ?string)`, `updateSessionModelDirect(id, ?string)`, `setSessionPinned(id, bool)`, `setSessionStatus(id, string)`, and `bumpSessionVersion(id): int`; every mutator bumps `version`)
- Test: `tests/Unit/Api/Session/SessionUpdateRequestResolverTest.php` (extend), `tests/conformance/CoreChecklistTest.php` (flip CORE-54, CORE-10)

**Interfaces:**
- Consumes: `schema/session-patch.json` — props `title,pinned,status,model(nullable),workspace(nullable)`, `additionalProperties:false`, `minProperties:1`; `Precondition` (Task 2). Vectors: `valid/session-patch.clear-model.json` (`{"model":null}`), `valid/session-patch.workspace.json` (`{"workspace":"/work/x"}`), `invalid/session-patch.empty.json` (`{}`).
- Note: `session.model` nullable + `workspace` read-out + `SessionHandler::toWire` already exist (Phase 2/3). The whole gap is the **write path** + version bump + If-Match.

- [ ] **Step 1: Failing resolver unit tests.** `{}` ⇒ rejected (throws/returns error); `{"model":null}` ⇒ `updatesModel` true, `model===null` (clear); `{"workspace":"/work/x"}` ⇒ `updatesWorkspace` true; `{"model_role":"coder"}` (old field) ⇒ rejected as unknown. Run → FAIL.

- [ ] **Step 2: Reshape the resolver + DTO (no-legacy).** `SessionUpdateRequestResolver::resolve($body)` now uses `decodePatchBody($r, ['title','pinned','status','model','workspace'])` semantics (reject unknown + empty). Distinguish "absent" from "null" with `array_key_exists` so `model:null` clears vs omitted leaves untouched. Drop the old `model_role`/`group_*`/`members`/`confirm_*` reads from the CAP PATCH path (member/group lifecycle is a separate scoped op surface, unchanged). Extend the DTO with `updatesWorkspace/workspace`, `updatesModel/model`, `updatesPinned/pinned`, `updatesStatus/status`. Run Step-1 → PASS.

- [ ] **Step 3: Wire the writes + version + If-Match.** In `SessionHandler::update`: after resolving, read `Precondition::fromRequest`; if `expectedVersion !== null` and `!== getSession($id)['version']` ⇒ `errorResponse(VERSION_CONFLICT, 'stale session version', null, 409)`. In the type handlers' `update`, apply each set field via the new `SessionStorage` mutators; each successful mutation path ends by calling `bumpSessionVersion($id)`. Add the storage mutators (direct UPDATEs on `sessions`, each also `version = version + 1, updated_at = ...`). `status` accepts only the closed `session.json` status enum (reject others 422 — reuse the enum guard).

- [ ] **Step 4: Failing conformance rows.** Remove `'CORE-54: …'` + `'CORE-10: …'`; add:

```php
it('CORE-54: session PATCH clears model, sets workspace, and rejects an empty body', function () {
    [$handler, $storage, $dbPath, $ws] = makeSessionHandler();
    try {
        $id = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', null, '/work/old');
        $clear = $handler->update(sessionPatchRequest($id, ['model' => null]));       // clear→inherit
        expect($clear->getStatusCode())->toBe(200);
        expect($storage->getSession($id)['model'])->toBeNull();
        $work = $handler->update(sessionPatchRequest($id, ['workspace' => '/work/x']));
        expect($storage->getSession($id)['workspace'])->toBe('/work/x');
        $empty = $handler->update(sessionPatchRequest($id, []));
        expect($empty->getStatusCode())->toBe(422);
    } finally { cleanupSqliteTestDb($dbPath); cleanupTestTree($ws); }
})->group('conformance');

it('CORE-10: a stale If-Match session write is rejected 409 version_conflict', function () {
    [$handler, $storage, $dbPath, $ws] = makeSessionHandler();
    try {
        $id = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', null, null);
        expect($storage->getSession($id)['version'])->toBe(1);
        $ok = $handler->update(sessionPatchRequest($id, ['title' => 'a'], ifMatch: 1)); // fresh
        expect($ok->getStatusCode())->toBe(200);
        expect($storage->getSession($id)['version'])->toBe(2);
        $stale = $handler->update(sessionPatchRequest($id, ['title' => 'b'], ifMatch: 1)); // stale
        expect($stale->getStatusCode())->toBe(409);
        expect(json_decode((string) $stale->getBody(), true)['code'])->toBe('version_conflict');
        expect(ApiErrorCode::tryFrom('version_conflict'))->not->toBeNull();
    } finally { cleanupSqliteTestDb($dbPath); cleanupTestTree($ws); }
})->group('conformance');
```

  `sessionPatchRequest($id, $body, ?int $ifMatch = null)` builds a `PATCH /sessions/{id}` `ServerRequest` with the JSON body and an `If-Match` header when given.

- [ ] **Step 5: Run + commit.** Green. Commit `feat(api): CAP session PATCH + version/If-Match (CORE-54/10)`.

---

### Task 4: Loop-definition PUT create/update + version (CORE-38 mechanism)

Give loop-definition writes the If-None-Match:* (create) / If-Match:v (update) contract over `object_versions`, and serve `version` on the persisted definition. (Task 5 builds the role PUT on this same mechanism and flips CORE-38.)

**Files:**
- Modify: `src/Api/Handler/LoopHandler.php` (`createDefinition` ~L301, `updateDefinition` ~L328, `definitions`/`getDefinition` producers)
- Modify: `src/Config/LoopDiscovery.php` (`saveDefinition` ~L335 — strip server-owned keys; do not persist `version` into the file)
- Modify: `ApiCommand.php` (thread `ObjectVersionStore` into `LoopHandler`)
- Test: `tests/Unit/Api/Handler/LoopHandlerTest.php` (extend), `tests/conformance/CoreChecklistTest.php` (no scoreboard flip here — CORE-38 flips in Task 5)

**Interfaces:**
- Consumes: `schema/loop-definition.put.json` (authoring body: required `name,roles,termination_condition`; `additionalProperties:false`; NO `version`), `schema/loop-definition.json` (persisted; requires `version`), `Precondition`, `ObjectVersionStore('loop_definition', $name)`.
- Vectors: `valid/loop-definition.put.json`, `valid/role.no-version.json` context (a PUT body is valid; a persisted row without `version` is invalid — the authoring-vs-persisted split).

- [ ] **Step 1: Failing handler tests.** PUT `/loops/definitions/{name}` with `If-None-Match: *` when absent ⇒ 201 + version 1; a second create ⇒ 409 `conflict`; PUT with `If-Match: 1` ⇒ 200 + version 2; PUT with `If-Match: 1` again (stale) ⇒ 409 `version_conflict`; a PUT body carrying `version`/`id` ⇒ 422 (authoring strictness). Run → FAIL.

- [ ] **Step 2: Implement.** `updateDefinition`/`createDefinition` collapse behind one PUT path that branches on `Precondition`: `isCreate` → require `ObjectVersionStore::current` == 0 (else 409 `conflict`), `decodeAuthoringBody($r, ['name','roles','termination_condition'], ['description','parameters','artifact_required'])`, `LoopDiscovery::saveDefinition`, `ObjectVersionStore::create`; `expectedVersion` set → require == current (else 409 `version_conflict`), save, `bump`; unconditional PUT → reject 428-style as 409 `conflict` "precondition required" (keep it simple: require one of the two headers). Ensure `saveDefinition` strips any server-owned key before writing (no `version` in the on-disk file — the file is authoring source; version lives in `object_versions`). The `definitions`/`getDefinition` producers add `version` from `ObjectVersionStore::current`.

- [ ] **Step 3: Run + commit.** Green (`LoopHandlerTest` new cases pass; no scoreboard row flips yet). Commit `feat(api): loop-definition PUT create/update + version (CORE-38 mechanism)`.

---
### Task 5: Role PUT create/update + version (CORE-38 flip)

Roles have **no HTTP write path** today (`RoleHandler` is read-only; mutations are REPL-only). Build a role PUT create/update handler on the Task-4 mechanism and flip CORE-38 asserting both role and loop-definition PUTs.

**Files:**
- Modify: `src/Api/Handler/RoleHandler.php` (add `put(ServerRequest): Response`, keep read paths)
- Modify: `src/Config/RoleDiscovery.php` / `src/Config/RoleParser.php` (add a `saveRole(string $name, array $body): void` file writer that mirrors `LoopDiscovery::saveDefinition` — writes the role markdown/frontmatter to the workspace roles dir; strips server-owned keys)
- Modify: `src/Command/ApiCommand.php` (register `PUT /api/v1/roles/{name}` → `RoleHandler::put`; thread `ObjectVersionStore`)
- Modify: `src/Api/Handler/RoleHandler.php` list/get producers (add `version`)
- Test: `tests/Unit/Api/Handler/RoleHandlerTest.php` (extend), `tests/conformance/CoreChecklistTest.php` (flip CORE-38)

**Interfaces:**
- Consumes: `schema/role.put.json` (required `name,access_level`; `additionalProperties:false`; other authoring keys per the schema — read it for the full prop list; NO `version`), `schema/role.json` (persisted; requires `version`), `Precondition`, `ObjectVersionStore('role', $name)`. Vector: `invalid/role.no-version.json` (a role body WITHOUT `version` is invalid *as a persisted role.json* — the authoring-vs-persisted split; the SAME body IS a valid `role.put.json`).

- [ ] **Step 1: Failing handler tests** mirroring Task 4 (create/update/stale/authoring-strict) but for roles. Run → FAIL.

- [ ] **Step 2: Implement `RoleHandler::put`** using the identical `Precondition` branch as loop-def (`isCreate` vs `expectedVersion`), `decodeAuthoringBody` against `role.put.json`'s required/optional props, `RoleDiscovery::saveRole`, `ObjectVersionStore` create/bump. Register the route. Keep RoleHandler's read paths and the "system/builtin role is not writable" guard (a builtin role name ⇒ 409 `role_builtin`). Add `version` to the role list/get producers from `ObjectVersionStore::current`.

- [ ] **Step 3: Flip CORE-38.** Remove `'CORE-38: …'` from `$rows`; add a test that drives BOTH the real `RoleHandler::put` and `LoopHandler` PUT over temp fs/db:

```php
it('CORE-38: role/loop-definition PUT distinguishes create (If-None-Match:*) from update (If-Match:v); persisted rows require version', function () {
    // --- role ---
    [$roleHandler, $rWs, $rDb] = makeRoleHandler();
    try {
        $create = $roleHandler->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'standard'], ifNoneMatch: '*'));
        expect($create->getStatusCode())->toBe(201);
        $created = json_decode((string) $create->getBody(), true);
        expect($created['version'])->toBe(1);
        // The persisted role is a schema-valid role.json (carries version).
        $v = new ConformanceValidator();
        expect($v->isValid('role.json', $roleHandler->servedRoleWire('reviewer')))->toBeTrue();
        // Re-create without a precondition-for-update ⇒ conflict.
        expect($roleHandler->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'standard'], ifNoneMatch: '*'))->getStatusCode())->toBe(409);
        // Update with the wrong version ⇒ version_conflict.
        $stale = $roleHandler->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'full'], ifMatch: 99));
        expect($stale->getStatusCode())->toBe(409);
        expect(json_decode((string) $stale->getBody(), true)['code'])->toBe('version_conflict');
    } finally { cleanupTestTree($rWs); cleanupSqliteTestDb($rDb); }

    // --- loop-definition (same contract) ---
    [$loopHandler, $lWs, $lDb] = makeLoopDefHandler();
    try {
        expect($loopHandler->putDefinition(loopDefPutRequest('ci', validLoopDefBody(), ifNoneMatch: '*'))->getStatusCode())->toBe(201);
        $stale = $loopHandler->putDefinition(loopDefPutRequest('ci', validLoopDefBody(), ifMatch: 99));
        expect(json_decode((string) $stale->getBody(), true)['code'])->toBe('version_conflict');
    } finally { cleanupTestTree($lWs); cleanupSqliteTestDb($lDb); }
})->group('conformance');
```

- [ ] **Step 4: Run + commit.** Green. Commit `feat(api): role PUT create/update + version; flip CORE-38`.

---

### Task 6: Cursor pagination + declared sort (CORE-18)

Introduce a shared `{data, next_cursor}` envelope with an opaque cursor and a declared default sort, and convert the Core-object list endpoints.

**Files:**
- Create: `src/Api/CursorPage.php` (encode/decode opaque cursor; build the envelope)
- Modify list handlers: `RoleHandler::list`, `LoopHandler::definitions`, `ConfigHandler::personas`, `SessionHandler::list`, `ScheduleHandler::list`, `ArtifactHandler::list`
- Test: `tests/Unit/Api/CursorPageTest.php`, `tests/conformance/CoreChecklistTest.php` (flip CORE-18)

**Interfaces:**
- Produces: `CursorPage::build(array $items, int $limit, callable $keyOf): array` → `['data' => [...], 'next_cursor' => string|null]`; `CursorPage::decode(?string $cursor): ?string` (opaque, base64url of the sort key of the last item on the previous page). Default `limit` from a constant (e.g. 50), capped at `max_page_size` (100, matches `instance-info.full.json`).
- Declared default sort: **name-ascending** for personas/roles/loop-definitions; a stable key for sessions (`updated_at DESC, id` — declare it), scheduled-tasks (`next_run_at ASC NULLS LAST, id`), artifacts (`updated_at DESC, id`). The default sort is documented in the handler and returned deterministically.

- [ ] **Step 1: Failing `CursorPageTest`.** `build` of 5 items with limit 2 ⇒ `data` has 2 + a non-null `next_cursor`; decoding it and building again resumes after the 2nd item; the last page ⇒ `next_cursor === null`. Round-trip `encode(decode(x)) === x`. Run → FAIL.

- [ ] **Step 2: Implement `CursorPage`** (opaque cursor = base64url of the last emitted sort key; a list op reads `?cursor=` + `?limit=`, sorts by the declared key, slices `> cursor`, returns `limit` items + the next cursor). No offset math — key-based, stable under insert.

- [ ] **Step 3: Convert the six list handlers.** Each: impose the declared sort (roles must be name-sorted — `RoleResolver::toArray` is currently insertion-ordered; loop-defs must `ksort` rather than rely on `scandir`; personas already ksort), then wrap through `CursorPage::build`. **Replace** the `{<name>:[...], count}` envelope with `{data, next_cursor}` (no-legacy). Keep any genuinely-extra top-level metadata a handler needs (e.g. `default_persona`) alongside, but `data`+`next_cursor` are the paginated contract. Fix any unit tests that asserted the old `{roles:…}` / `{definitions:…}` shape.

- [ ] **Step 4: Flip CORE-18.** Remove `'CORE-18: …'`; add:

```php
it('CORE-18: list operations return a {data, next_cursor} page with a declared name sort', function () {
    [$roleHandler, $ws, $db] = makeRoleHandler();
    try {
        // Seed several roles out of alpha order, then list.
        $roleHandler->put(rolePutRequest('zeta',  ['name' => 'zeta',  'access_level' => 'standard'], ifNoneMatch: '*'));
        $roleHandler->put(rolePutRequest('alpha', ['name' => 'alpha', 'access_level' => 'standard'], ifNoneMatch: '*'));
        $page = json_decode((string) $roleHandler->list(listRequest(['limit' => 100]))->getBody(), true);
        expect($page)->toHaveKeys(['data', 'next_cursor']);
        $names = array_column($page['data'], 'name');
        // The seeded roles appear in ascending name order (declared default sort).
        $seeded = array_values(array_intersect($names, ['alpha', 'zeta']));
        expect($seeded)->toBe(['alpha', 'zeta']);
    } finally { cleanupTestTree($ws); cleanupSqliteTestDb($db); }
})->group('conformance');
```

  If the seeded env has builtin roles ahead of the seeded ones, assert only relative order of the two seeded names (as above). Also add a small `CursorPage` unit assertion that a truncated page yields a resumable non-null `next_cursor` (teeth on pagination itself, not just sort).

- [ ] **Step 5: Run + commit.** Green. Commit `feat(api): cursor pagination + declared sort (CORE-18)`.

---

### Task 7: Typed loop-live + verdict producers (CORE-6, CORE-7)

Emit a strictly-typed `loop-live.json` snapshot and a typed `verdict.json`, including the verdict approval rule.

**Files:**
- Create: `src/Api/Loop/LoopLiveProducer.php` (`toWire(...)` → `loop-live.json`)
- Create: `src/Contract/Verdict.php` (typed value object) + `src/Api/Loop/VerdictProducer.php` (or a `toWire` on `Verdict`)
- Modify: `src/Api/Handler/LoopHandler.php` (`live` endpoint returns the typed producer output)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-6, CORE-7)

**Interfaces:**
- Consumes: `schema/loop-live.json` — required `loop_id, status, current_iteration, current_stage, budget, stages`; `budget` object (`tokens_used, iterations_used, max_iterations|null, elapsed_ms`, optional `breakdown`); `stages[]` `{stage_index, role, status, result_summary?}`; optional `model`, `feed[]`. `status` ⟵ `loop.json#/properties/status`; stage `status` ⟵ `loop-stage.json#/properties/status`. Data sources: `LoopStore` (loop row + iterations/stages), `LoopStreamTracker`/`latestActivityId` for feed.
- Consumes: `schema/verdict.json` — required `requirements_met, quality_pass, findings[]`; each finding `{severity∈{Critical,Important,Minor}, summary, detail?}`. Approval rule (CORE-7): approved ⟺ `requirements_met && quality_pass && no finding of severity Critical or Important`. Reuse the Phase-3 `StageSeverity` enum for severities.

- [ ] **Step 1: Failing conformance rows.** Remove `'CORE-6: …'` + `'CORE-7: …'`; add:

```php
it('CORE-6: the loop live snapshot is fully typed', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core6-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $loopStore = new LoopStore($storage->getPdo());
        // seed a loop + one iteration + two stages via the store; then:
        $wire = (new LoopLiveProducer($loopStore))->toWire($loopId);
        $v = new ConformanceValidator();
        expect($v->isValid('loop-live.json', $wire))->toBeTrue($v->errorText('loop-live.json', $wire));
        expect($wire)->toHaveKeys(['loop_id', 'status', 'current_iteration', 'current_stage', 'budget', 'stages']);
    } finally { cleanupSqliteTestDb($dbPath); }
})->group('conformance');

it('CORE-7: verdict is typed and approval requires both flags with no Critical/Important', function () {
    $v = new ConformanceValidator();
    $approved = Verdict::fromFindings(requirementsMet: true, qualityPass: true, findings: [
        new StageFinding(StageSeverity::Minor, 'nit'),
    ]);
    expect($v->isValid('verdict.json', $approved->toWire()))->toBeTrue($v->errorText('verdict.json', $approved->toWire()));
    expect($approved->isApproved())->toBeTrue();
    // Both flags true but a Critical finding blocks approval.
    $blocked = Verdict::fromFindings(true, true, [new StageFinding(StageSeverity::Critical, 'boom')]);
    expect($blocked->isApproved())->toBeFalse();
    // A false flag blocks approval even with no findings.
    expect(Verdict::fromFindings(false, true, [])->isApproved())->toBeFalse();
    expect(Verdict::fromFindings(true, false, [])->isApproved())->toBeFalse();
})->group('conformance');
```

- [ ] **Step 2: Implement the producers.** `LoopLiveProducer::toWire(string $loopId): array` reads the loop + iterations/stages from `LoopStore`, maps to the schema shape (empty `budget` sub-object with the 0-defaults; `stages` from the current iteration's stage rows; `model` optional). `Verdict` is an immutable VO with `requirements_met`/`quality_pass`/`findings[]`, `isApproved()` implementing the rule, and `toWire()` (findings' severity strings `Critical|Important|Minor`; empty findings ⇒ `[]` array, which is schema-legal for `findings`). Wire `LoopHandler::live` (the existing `/loops/{id}/live` route) to return the producer output.

- [ ] **Step 3: Run + commit.** Green. Commit `feat(api): typed loop-live + verdict producers (CORE-6/7)`.

---

### Task 8: Typed model catalog (CORE-11)

Add a strict `model.json` producer and serve it from `listModels`.

**Files:**
- Create: `src/Api/Model/ModelProducer.php` (`toWire(ModelDefinition): array`)
- Modify: `src/Api/Handler/ConfigHandler.php:344` (`models` — emit strict producer output)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-11)

**Interfaces:**
- Consumes: `schema/model.json` — required `id, context_window, tokenizer_hint, capabilities`; optional `display_name, max_output_tokens`; `additionalProperties:false`. `tokenizer_hint` ⟵ `common.json#/$defs/TokenizerHint` enum `[o200k_base, cl100k_base, claude, heuristic-3.5, heuristic-4, unknown]`. `capabilities` ⊆ `[tools, vision, thinking]`, `uniqueItems`. Source: `ModelMetadataResolver::configuredModels()` → `ModelDefinition` (`contextWindow`, `maxTokens`, `name`, `toolCalls`, `vision`, `thinking`).
- Produces: `ModelProducer::toWire(ModelDefinition $m): array` — map `id`←`id`; `context_window`←`contextWindow`; `max_output_tokens`←`maxTokens` (null when 0/absent); `display_name`←`name`; `capabilities` built from the three booleans (`toolCalls`→`tools`, `vision`→`vision`, `thinking`→`thinking`); `tokenizer_hint` derived from provider/family with a `'unknown'` default (no source exists today — a small `deriveTokenizerHint(ModelDefinition): string` mapping Anthropic→`claude`, OpenAI o-series→`o200k_base`, else `unknown`). Drop all extra keys (`provider`, `reasoning`, `input`, `family`, `metadataSource`) — strict shape.

- [ ] **Step 1: Failing CORE-11 row.** Remove `'CORE-11: …'`; add a test that builds a `ModelDefinition` (or takes one from a seeded resolver) and asserts `ModelProducer::toWire(...)` validates against `model.json`, `context_window` is a positive int, `tokenizer_hint` ∈ the enum, and `capabilities` ⊆ the enum. Run → FAIL.

- [ ] **Step 2: Implement `ModelProducer` + `deriveTokenizerHint`; point `ConfigHandler::models` at it** (list of strict producer objects; keep the endpoint route unchanged). Fix any unit test asserting the old rich per-model keys.

- [ ] **Step 3: Run + commit.** Green. Commit `feat(api): typed model catalog (CORE-11)`.

---

### Task 9: Budget breakdown at /sessions/{id}/budget (CORE-55)

Add a session-scoped budget endpoint returning a typed `budget-breakdown.json`.

**Files:**
- Create: `src/Api/Budget/BudgetBreakdownProducer.php` (`toWire(PromptBudgetSnapshot): array`)
- Modify: `src/Command/ApiCommand.php` (register `GET /api/v1/sessions/{id}/budget` → a handler method)
- Modify: `src/Api/Handler/BudgetHandler.php` (add `session(ServerRequest): Response` taking the id from the path)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-55)

**Interfaces:**
- Consumes: `schema/budget-breakdown.json` — required `sections[], total_estimated_tokens, model_context_window`; each section `{name(minLen1), included, estimated_tokens(≥0), priority(int≥0), shed_reason?}`; `additionalProperties:false`. Source: `AgentRunner::buildBudgetPreview(role, persona, sessionId)` → `PromptBudgetSnapshot::toArray()` (`prompt_sections[]` = `{id,title,group,priority(STRING),pinned,deferrable,included,decision,rationale,source,tokens}`, plus `context_window`).
- Produces: `BudgetBreakdownProducer::toWire(PromptBudgetSnapshot $s): array` — `sections[].name`←`title` (fallback `id`); `included`←`included`; `estimated_tokens`←`tokens`; `priority`←**(int) cast** of the string priority; `shed_reason`←derive from `decision`/`rationale` (`null` when `included` is true, else a short string). `total_estimated_tokens`←sum of included `tokens`. `model_context_window`←`context_window`.

- [ ] **Step 1: Failing CORE-55 row.** Remove `'CORE-55: …'`; add a test that builds a `PromptBudgetSnapshot` (via `AgentRunner::buildBudgetPreview` over a seeded session, or a hand-built snapshot fixture) and asserts `BudgetBreakdownProducer::toWire(...)` validates against `budget-breakdown.json`, `total_estimated_tokens` == sum of included section tokens, and `model_context_window` ≥ 1. Prefer driving the real `BudgetHandler::session` over a temp session and validating the response body. Run → FAIL.

- [ ] **Step 2: Implement the producer + `BudgetHandler::session` + route.** Reuse `buildBudgetPreview` with the session's role/persona resolved from the row (session-model precedence already handled inside). Register the path-param route beside the existing `/server/budget` (keep `/server/budget` — additive).

- [ ] **Step 3: Run + commit.** Green. Commit `feat(api): typed budget breakdown at /sessions/{id}/budget (CORE-55)`.

---
### Task 10: Content bytes + read + Range download + binary upload + export collection (CORE-44, CORE-45)

Give `ContentStore` byte persistence and a read path, serve content objects with Range download and binary/multipart upload, wire `content_not_found`, and type a `content` export collection.

**Files:**
- Modify: `src/Content/ContentStore.php` (persist bytes; add `get(string $contentRef): ?array`, `findBySha256(string): ?array`, `readBytes(string $contentRef): ?string`)
- Modify: `src/Storage/SessionStorage.php` (the `content` table gains a `bytes BLOB` column in `createTables()`, or a sibling blob store keyed by `sha256` — recreate-from-empty, no ALTER)
- Modify: `src/Api/Handler/FileUploadHandler.php` (`upload` — accept raw-binary body branch + emit `content.json`; `get` — Range → 206)
- Create/Modify: `src/Export/ContentProducer.php` + `src/Export/ExportCollectionMap.php` (type a `content` collection)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-44, CORE-45)

**Interfaces:**
- Consumes: `schema/content.json` (required `content_ref, mime_type, size, sha256, created_at`; `additionalProperties:false`); `schema/export.json` `content` collection; `ApiErrorCode::CONTENT_NOT_FOUND` (Task 1). Vectors: `valid/content.json`, `invalid/content.no-sha.json`, `valid/export.roundtrip.json` (`content[]`).
- Produces: `ContentStore::get`/`readBytes`; `ContentProducer::toWire(array $row): array` (already essentially `ContentStore::toWire`); `ExportCollectionMap` entry `content` → `ContentProducer`.
- HTTP: put (multipart via `getUploadedFiles`, OR raw binary via `(string) $request->getBody()` when non-multipart) stores bytes + returns the `content.json` object; get parses a `Range: bytes=a-b` header → `206 Partial Content` with `Content-Range`/`Accept-Ranges: bytes`, else `200`; a missing ref ⇒ `errorResponse(CONTENT_NOT_FOUND, …)`.

- [ ] **Step 1: Failing CORE-44/45 rows.** Remove `'CORE-44: …'` + `'CORE-45: …'`; add:

```php
it('CORE-44: content upload returns a typed content object; download honors Range and 404s a missing ref', function () {
    [$handler, $storage, $dbPath, $ws] = makeFileUploadHandler();
    try {
        $sid = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', null, null);
        $up = $handler->upload(binaryUploadRequest($sid, "hello world", 'text/plain'));
        $obj = json_decode((string) $up->getBody(), true);
        $v = new ConformanceValidator();
        expect($v->isValid('content.json', $obj))->toBeTrue($v->errorText('content.json', $obj));
        // Range download → 206 with the requested slice.
        $part = $handler->get(rangeGetRequest($sid, $obj['content_ref'], 'bytes=0-4'));
        expect($part->getStatusCode())->toBe(206);
        expect((string) $part->getBody())->toBe('hello');
        expect($part->getHeaderLine('Content-Range'))->toStartWith('bytes 0-4/');
        // Missing ref → content_not_found.
        $missing = $handler->get(rangeGetRequest($sid, 'nope', null));
        expect($missing->getStatusCode())->toBe(404);
        expect(json_decode((string) $missing->getBody(), true)['code'])->toBe('content_not_found');
    } finally { cleanupSqliteTestDb($dbPath); cleanupTestTree($ws); }
})->group('conformance');

it('CORE-45: the export envelope types a content collection', function () {
    $map = new ExportCollectionMap();
    expect($map->has('content'))->toBeTrue();
    // A produced content row validates against content.json (element type of the export content[]).
    $dbPath = sys_get_temp_dir() . '/coqui-core45-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $store = new ContentStore($storage->getPdo());
        $wire = $store->store('bytes here', 'application/octet-stream');
        $v = new ConformanceValidator();
        expect($v->isValid('content.json', $wire))->toBeTrue($v->errorText('content.json', $wire));
    } finally { cleanupSqliteTestDb($dbPath); }
})->group('conformance');
```

- [ ] **Step 2: Implement byte persistence + read + Range + export typing.** Store bytes (blob column keyed by `sha256`, deduped) so `readBytes(content_ref)` works. Extend `FileUploadHandler::upload` with the raw-binary branch and make it return the `content.json` object (per-session file route stays; the returned object is content-addressed). Add Range parsing to `get`. Register `content` in `ExportCollectionMap` with `ContentProducer`. Keep `store()`'s existing signature/return.

- [ ] **Step 3: Run + commit.** Green. Commit `feat(api): content bytes/read/Range + export content collection (CORE-44/45)`.

---

### Task 11: Message attachments + strict message producer (CORE-43)

Carry typed `attachments[]` on messages and add a strict `message.json` producer.

**Files:**
- Modify: `src/Storage/SessionStorage.php` (a `message_attachments(message_id, content_ref, mime_type)` table + join in `getMessages` ~L1177; SELECT also `session_id`, `turn_id`)
- Create: `src/Api/Handler/MessageWire.php` (or a `MessageHandler::toWire(array $row): array` producer)
- Modify: `src/Api/Handler/MessageHandler.php` (attach on send when the body carries `attachments`; emit strict shape where a producer is needed)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-43)

**Interfaces:**
- Consumes: `schema/message.json` (required `id, session_id, role, content, created_at`; optional nullable `attachments[]`, `turn_id`), `schema/attachment.json` (`{content_ref, mime_type}` both required, `additionalProperties:false`). Vector: `valid/message.with-attachments.json`.
- Produces: `MessageHandler::toWire(array $row): array` — includes `session_id`, optional `turn_id`, and `attachments[]` of `{content_ref, mime_type}` from the join (nullable/omitted when none).

- [ ] **Step 1: Failing CORE-43 row.** Remove `'CORE-43: …'`; add:

```php
it('CORE-43: messages carry typed attachments of {content_ref, mime_type}', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core43-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $sid = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', null, null);
        $mid = $storage->appendMessage($sid, 'user', 'see attached', attachments: [
            ['content_ref' => '01J00000000000000000CONTENT1', 'mime_type' => 'text/plain'],
        ]);
        $wire = MessageHandler::toWire($storage->getMessageRow($mid));
        $v = new ConformanceValidator();
        expect($v->isValid('message.json', $wire))->toBeTrue($v->errorText('message.json', $wire));
        expect($wire['attachments'][0])->toBe(['content_ref' => '01J00000000000000000CONTENT1', 'mime_type' => 'text/plain']);
    } finally { cleanupSqliteTestDb($dbPath); }
})->group('conformance');
```

  (If `appendMessage`/`getMessageRow` don't have those exact signatures, adapt to the real message-insert API found in `SessionStorage`; the point is: insert a message with an attachment and produce a strict `message.json`.)

- [ ] **Step 2: Implement the table, join, and producer.** Add `message_attachments` to `createTables()`; extend the message insert to accept optional `attachments`; add `session_id`/`turn_id` to the `getMessages` SELECT; write `MessageHandler::toWire`. Keep the live message list additive.

- [ ] **Step 3: Run + commit.** Green. Commit `feat(api): message attachments + strict message producer (CORE-43)`.

---

### Task 12: SSE string cursor + typed per-channel frames (CORE-51, CORE-52)

Replace the raw int frame id with an opaque, lexicographically-orderable string cursor across all three streams, and type each channel's frames (turn + loop), renaming the turn terminal `complete`→`done`.

**Files:**
- Create: `src/Api/Sse/SseCursor.php` (`encode(int): string` zero-padded fixed width; `decode(string): int`)
- Modify: `src/Api/SseStream.php` (`?int $id` → `?string $id`; give `connected`/`done` an id)
- Modify: `src/Api/Handler/MessageHandler.php` (`writeSseEvent` ~L387 — encode id; rename terminal `complete`→`done` carrying a `turn.json` record; constrain to the closed turn event set)
- Modify: `src/Api/Handler/TaskHandler.php` (`writeEvent` ~L406 — encode id), `src/Api/Handler/LoopHandler.php` (`events` ~L510 — encode `latestActivityId`)
- Test: `tests/Unit/Api/Sse/SseCursorTest.php`, `tests/conformance/CoreChecklistTest.php` (flip CORE-51, CORE-52)

**Interfaces:**
- Consumes: `schema/sse-frame.json` (`id` is `type:string`, "opaque, lexicographically-orderable, never a number" — a bare decimal counter that doesn't sort as strings is non-conformant), `schema/sse-turn-event.json` (closed set `token|message|tool_call|tool_result|question|done|error`; `done.data` ⟵ `turn.json`), `schema/sse-loop-event.json` (closed set `connected|stage_changed|activity|done`). Vectors: `valid/sse-turn-frame.json` (`{"id":"42",…}`), `invalid/sse-frame.numeric-id.json` (`{"id":42,…}` — rejected), `valid/sse-turn-event.token.json`, `valid/sse-turn-event.done.json`, `invalid/sse-turn-event.unknown-shape.json`.
- Produces: `SseCursor::encode(int $rowid): string` — zero-padded fixed width (e.g. `sprintf('%020d', $rowid)`) so string ordering matches numeric; `decode` reverses it. Every stream's `id:` line uses the encoded string; the internal cursor stays numeric via `decode`.

- [ ] **Step 1: Failing `SseCursorTest`.** `encode(9) < encode(10)` as strings; `decode(encode(n)) === n`; `encode` output matches `^[0-9]+$` and is fixed width. Run → FAIL.

- [ ] **Step 2: Implement `SseCursor`; thread it** through `SseStream` (widen id to string), `MessageHandler::writeSseEvent`, `TaskHandler::writeEvent`, `LoopHandler::events`. Rename the turn stream's terminal `complete` event to `done` and make its `data` a full `turn.json` record (from the existing `extractCompleteResult` turn row); constrain the turn stream's emitted event names to the closed set (map any coqui-internal event that isn't in the set onto the nearest CAP event or drop it — document the mapping in a code comment). Keep the internal `$lastEventId` cursor numeric (decode on read).

- [ ] **Step 3: Flip CORE-51/52.** Remove both strings; add:

```php
it('CORE-52: an SSE frame id is an opaque string cursor that sorts lexicographically', function () {
    // coqui encodes rowids so string order == numeric order.
    expect(SseCursor::encode(9) < SseCursor::encode(10))->toBeTrue();
    expect(SseCursor::decode(SseCursor::encode(4242)))->toBe(4242);
    $v = new ConformanceValidator();
    // A frame coqui would emit validates; the numeric-id shape is rejected by sse-frame.json.
    expect($v->isValid('sse-frame.json', ['id' => SseCursor::encode(42), 'text' => 'Hello']))->toBeTrue();
    expect($v->isValid('sse-frame.json', ['id' => 42, 'text' => 'Hello']))->toBeFalse();
})->group('conformance');

it('CORE-51: turn SSE frames are emitted only in the closed per-channel event set', function () {
    // The turn stream's terminal frame is `done` (a full turn record), never the legacy `complete`.
    $frame = MessageHandler::buildTurnEventFrame('done', $turnRecord, SseCursor::encode(7)); // small pure builder extracted in Step 2
    $v = new ConformanceValidator();
    expect($v->isValid('sse-turn-event.json', $frame))->toBeTrue($v->errorText('sse-turn-event.json', $frame));
    expect(in_array($frame['event'], ['token','message','tool_call','tool_result','question','done','error'], true))->toBeTrue();
})->group('conformance');
```

  Extract a small **pure** frame-builder (`MessageHandler::buildTurnEventFrame(string $event, array $data, string $id): array`) in Step 2 so the typed-frame shape is unit-testable without a live stream.

- [ ] **Step 4: Run + commit.** Green. Commit `feat(sse): string cursor + typed per-channel frames (CORE-51/52)`.

---

### Task 13: SSE replay (Last-Event-ID / ?since) + catalog-coded error frame (CORE-5, CORE-41)

Honor resumable reconnect on the turn + task streams, and emit a catalog-coded `error` frame on streaming failure.

**Files:**
- Modify: `src/Api/Handler/MessageHandler.php` (seed the turn cursor from `Last-Event-ID`/`?since`; emit an `error` frame on failure)
- Modify: `src/Api/Handler/TaskHandler.php` (also read `Last-Event-ID` header; emit `error` frame)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-5, CORE-41)

**Interfaces:**
- Consumes: `schema/sse-error.json` (`event` const `error`; `data` ⟵ `error.json` closed code enum). Vectors: `valid/sse-error.json` (`{"id":"42","event":"error","data":{"error":"…","code":"internal_error"}}`), `invalid/sse-error.bad-code.json`. Replay stores: `turn_events` (supports `id > :since_id`), `task_events` (already `id > :since_id`).
- Cursor resolution at handler entry: `Last-Event-ID` header (a `SseCursor` string) → `?since` query → legacy `?since_id` → null; decode to the numeric rowid via `SseCursor::decode`.

- [ ] **Step 1: Failing CORE-5/41 rows.** Remove both strings; add:

```php
it('CORE-5: a turn stream reconnect replays only events after the Last-Event-ID cursor', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core5-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        // seed a turn process with 3 turn_events (ids 1,2,3) for a session/turn.
        // Resolve the replay cursor from a Last-Event-ID equal to encode(2):
        $after = MessageHandler::resolveReplayCursor(sseReconnectRequest(lastEventId: SseCursor::encode(2)));
        expect($after)->toBe(2);
        $replayed = $storage->getTurnEvents($turnProcessId, sinceId: $after); // only id 3
        expect(array_column($replayed, 'id'))->toBe([3]);
    } finally { cleanupSqliteTestDb($dbPath); }
})->group('conformance');

it('CORE-41: an SSE error frame carries a code from the closed catalog', function () {
    $frame = MessageHandler::buildErrorFrame(ApiErrorCode::INTERNAL_ERROR, 'the turn crashed', SseCursor::encode(42));
    $v = new ConformanceValidator();
    expect($v->isValid('sse-error.json', $frame))->toBeTrue($v->errorText('sse-error.json', $frame));
    expect($frame['data']['code'])->toBe('internal_error');
    expect(ApiErrorCode::tryFrom($frame['data']['code']))->not->toBeNull();
})->group('conformance');
```

  Extract pure helpers in Step 2: `MessageHandler::resolveReplayCursor(ServerRequest): ?int` and `MessageHandler::buildErrorFrame(ApiErrorCode, string, string): array` (data = `ApiErrorCode::toPayload`, i.e. `{error, code, details?}`).

- [ ] **Step 2: Implement.** Turn stream: seed `$lastEventId` (`:180`) from `resolveReplayCursor`; `getTurnEvents` already filters `id > :since_id`. Task stream: also read `Last-Event-ID` alongside `?since_id`. On any streaming failure (both handlers currently just `$stream->end()`), first write an `error` frame via `buildErrorFrame` (intersection of `ApiErrorCode` with the catalog — after Task 1 the enum IS the catalog). Keep the numeric internal cursor; encode ids on the wire (Task 12).

- [ ] **Step 3: Run + commit.** Green. Commit `feat(sse): Last-Event-ID replay + catalog error frame (CORE-5/41)`.

---

### Task 14: InstanceInfo discovery + forward tolerance (CORE-30, 31, 35, 36, 39, 46)

Assemble a typed, aggregated `InstanceInfo` document from existing data sources and serve it at a new discovery route; enforce the closed enums (bindings, mcp.transports, auth.scheme) while keeping `profiles` open.

**Files:**
- Create: `src/Support/Cap.php` (`const PROTOCOL_VERSION = '0.5.0';` — new; do NOT reuse `AppVersion` or the MCP wire version)
- Create: `src/Api/Discovery/InstanceInfoBuilder.php` (`build(): array`)
- Create/Modify: `src/Api/Handler/ServerHandler.php` (add `instance(ServerRequest): Response`) + `ApiCommand.php` route `GET /api/v1/server/instance` (keep `/server/info` health blob unchanged — additive)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-30, 31, 35, 36, 39, 46)

**Interfaces:**
- Consumes: `schema/instance-info.json` — required `protocol_version(semver), profiles(open string array, uniqueItems), bindings(closed enum [in-process,http-sse])`; optional `persona_count, default_model, models[], host_toolkits[]{namespace,description?,tools?}, mcp{transports:[stdio|http]}, profile_versions{semver values}, auth{required:bool, scheme:"bearer"}, limits{max_page_size,max_payload_bytes,max_content_bytes[,rate_limit]}, api{base_path,api_major}, builtin_toolkits[], schedules{dialect?}`. Data sources (recon): `PersonaDiscovery` (persona_count), `ModelMetadataResolver`+`ModelProducer` (models/default_model — reuse Task 8), `ToolkitDiscovery` (host_toolkits), the `src/Toolkit/*Toolkit` set (builtin_toolkits portable names), `ApiFeatureDiscovery`+enabled toolkits (profiles — free strings), `AuthMiddleware`/`resolveApiKey` (auth), middleware size config (limits), `/api/v1` prefix (api).
- Vectors (the exact conformant shapes to match): `valid/instance-info.json`, `.host-toolkits.json`, `.mcp.json`, `.full.json`, `.profile-versions.json`, `.future-profile.json`; rejected: `.mcp-bad-transport.json` (`grpc`), `.bad-auth-scheme.json` (`oauth`), `.bad-profile-version.json` (`v3`).

- [ ] **Step 1: Failing conformance rows.** Remove the CORE-30/31/35/36/39/46 strings; add:

```php
it('CORE-30/46: InstanceInfo is typed, declares host_toolkits, and types auth/limits/api/builtin_toolkits', function () {
    $info = makeInstanceInfoBuilder()->build(); // over a temp workspace with one host toolkit + bearer auth
    $v = new ConformanceValidator();
    expect($v->isValid('instance-info.json', $info))->toBeTrue($v->errorText('instance-info.json', $info));
    expect($info)->toHaveKeys(['protocol_version', 'profiles', 'bindings']);
    expect($info['bindings'])->each->toBeIn(['in-process', 'http-sse']);        // closed set
    if (isset($info['auth'])) { expect($info['auth']['scheme'])->toBe('bearer'); } // closed scheme
})->group('conformance');

it('CORE-31: InstanceInfo mcp.transports is a closed set', function () {
    $info = makeInstanceInfoBuilder(withMcp: true)->build();
    $v = new ConformanceValidator();
    expect($v->isValid('instance-info.json', $info))->toBeTrue();
    expect($info['mcp']['transports'])->each->toBeIn(['stdio', 'http']);
})->group('conformance');

it('CORE-35: InstanceInfo profile_versions use semver', function () {
    $info = makeInstanceInfoBuilder(profileVersions: ['mcp' => '0.3.0'])->build();
    expect((new ConformanceValidator())->isValid('instance-info.json', $info))->toBeTrue();
})->group('conformance');

it('CORE-36/39: profiles is an OPEN set — an unknown profile still validates and is not rejected', function () {
    // The vendored schema puts no enum on profiles.items; an unknown profile MUST validate (CORE-39),
    // and coqui's discovery must not reject it (CORE-36 forward tolerance).
    $info = makeInstanceInfoBuilder(extraProfile: 'telepathy')->build();
    $v = new ConformanceValidator();
    expect($v->isValid('instance-info.json', $info))->toBeTrue($v->errorText('instance-info.json', $info));
    expect($info['profiles'])->toContain('telepathy');
})->group('conformance');
```

  (One combined row per natural cluster is fine; the `$rows` strings for 30/31/35/36/39/46 are all removed and each has a real assertion above. See the **Adjudication** note: CORE-30 asserts host_toolkits-declared + open profiles, NOT a bad-profile rejection.)

- [ ] **Step 2: Implement `Cap::PROTOCOL_VERSION`, `InstanceInfoBuilder`, and the route.** The builder assembles the object from the sources above. Enforce closed enums as it builds (`bindings` constant `['in-process','http-sse']`; `mcp.transports` from actual transports — `['stdio']` today; `auth.scheme` fixed `'bearer'`, and **omit `auth` entirely** when embedded/no-key rather than emitting a scheme-less object). `profiles` is emitted as free strings (never allowlist-filtered — forward tolerance). Empty sub-objects (`mcp` when no servers, `rate_limit`) emit as `stdClass`. Serve at `GET /server/instance` (health `/server/info` unchanged). The `makeInstanceInfoBuilder(...)` test factory constructs the builder over a temp workspace with the knobs the tests need.

- [ ] **Step 3: Run + commit.** Green. Commit `feat(api): aggregated InstanceInfo discovery (CORE-30/31/35/36/39/46)`.

---

### Task 15: Idempotency-Key for creators (CORE-53)

Dedup POST creators on an `Idempotency-Key` header.

**Files:**
- Create: `src/Api/Middleware/IdempotencyMiddleware.php`
- Create: `src/Storage/IdempotencyStore.php` (+ `idempotency_keys` table in `SessionStorage::createTables()`)
- Modify: `src/Command/ApiCommand.php` (insert the middleware ahead of the creator routes)
- Test: `tests/Unit/Api/Middleware/IdempotencyMiddlewareTest.php`, `tests/conformance/CoreChecklistTest.php` (flip CORE-53)

**Interfaces:**
- Produces: `IdempotencyStore(PDO)`: `lookup(string $key, string $route, string $actor): ?array` (stored response), `record(string $key, string $route, string $actor, int $status, string $body): void`. `IdempotencyMiddleware`: on a creator request carrying `Idempotency-Key`, replay the stored response if the `(key, route, actor)` tuple was seen; else run the handler and record the response. Absent header ⇒ pass through unchanged.
- Creators (recon): `POST /sessions`, `/sessions/{id}/members`, `/sessions/{id}/messages`, `/sessions/{id}/files`, `/personas`, `/tasks`, `/schedules`, `/sessions/{id}/artifacts`, `/loops`, `/loops/definitions`. (Non-creators untouched.)
- `idempotency_keys(key TEXT, route TEXT, actor TEXT, status INTEGER, body TEXT, created_at TEXT, PRIMARY KEY(key, route, actor))`.

- [ ] **Step 1: Failing middleware test.** Two identical creator requests with the same `Idempotency-Key` ⇒ the handler runs once; the second returns the recorded response (same status+body) without re-invoking. A request without the header ⇒ handler runs normally. Run → FAIL.

- [ ] **Step 2: Implement the store, table, and middleware; wire it** ahead of the creator routes (follow the existing per-route middleware pattern; `Router::registerPublic` shows per-route discrimination). Note: the checklist points CORE-53 at an `openapi.yaml` parameter that does not exist in the vendored spec — the coqui gate is **behavioral** (the header is accepted and dedups), not an openapi lint.

- [ ] **Step 3: Flip CORE-53.** Remove `'CORE-53: …'`; add a behavioral test driving a real creator (e.g. `SessionHandler::create` behind the middleware, or the middleware directly with a stub handler) that asserts a repeated key replays the first response and the handler side-effect happened once.

- [ ] **Step 4: Run + commit.** Green. Commit `feat(api): Idempotency-Key dedup for creators (CORE-53)`.

---

## Exit Criteria

- 26 rows green (CORE-4, 5, 6, 7, 9, 10, 11, 18, 30, 31, 35, 36, 37, 38, 39, 40, 41, 43, 44, 45, 46, 51, 52, 53, 54, 55); todos **37 → 11** (remaining: CORE-2, 12, 29, 32, 47, 48, 49, 50, 56, 57, 58 — Phase 5/6).
- `composer test` + `composer analyse` green after every task; never red.
- `CatastrophicBlacklist.php` byte-unchanged across the whole phase; vendored `tests/conformance/spec/**` untouched.
- `GoldenVectorsTest` still green (every vendored vector validates/rejects against its schema).
- Whole-branch review "Ready to merge = Yes".

## Carry-forwards (Phase 5/6)

- **Memory HTTP CRUD** (CAP-shaped store/adapter; memory-patch handler) — deferred; CORE-9 met via persona.
- **Child-run SSE channel** (`sse-childrun-event.json`, CORE-51 childrun arm) arrives with the Phase-5 child-run ops (CORE-29).
- **Project wire-surface teardown** still owed by D3's end-state (behavioral-only decision).
- **Credentials surface** repointed to `not_found` this phase; whole-surface removal (not in `error-coverage.json`) is a later cleanup.
- **`persona_session_active` semantics** now surface as generic `conflict`; if a distinct catalog code is ever wanted, that's a spec change (out of scope).

## Self-Review (author checklist — completed)

- **Spec coverage:** every Phase-4 CORE row (per design §6) maps to a task; CORE-8/42 already green (strengthened, not flipped); CORE-2 left to Phase 5/6 per design.
- **Type consistency:** `Precondition`, `ObjectVersionStore`, `CursorPage`, `SseCursor`, `*Producer` signatures are used identically across the tasks that consume them; `ApiErrorCode::VERSION_CONFLICT`/`CONTENT_NOT_FOUND` land in Task 1 before any consumer.
- **No placeholders:** every task names exact files (with recon line refs), the exact schema required-field set, the exact vectors, and concrete test code for the scoreboard flip.

