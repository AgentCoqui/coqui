# Phase 2 — §A Storage Reshape to CAP 0.5.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task (fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer per task; whole-branch review at phase end). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reshape coqui's three SQLite stores to the CAP 0.5.0 data shape — recreate-from-empty (no migrations), add the `meta.schema_version` marker with fail-closed-open, and make every in-scope Core object *producible* as a schema-valid instance validated against the vendored `schema/*.json`.

**Architecture:** Rewrite each reshaped store's base `createTables()` DDL directly to the 0.5.0 shape and drop the `addColumnIfMissing`/`migrate()` accretion for the tables we reshape (no ALTER-chains — there is no installed base). coqui retains the operational columns CAP does not model (group sessions, visibility, is_closed/is_archived, ScheduleManager execution fields); the CAP **wire** fields are *derived* in per-object serializers. Each serializer is proven by a conformance producer test that emits a real instance and asserts `(new ConformanceValidator())->isValid('<object>.json', $produced)`, turning the matching CORE checklist row green. Empty JSON objects are emitted as `stdClass`/`(object)[]` (never `[]`) so they validate against `object` schemas.

**Tech Stack:** PHP 8.4, SQLite (PDO), Pest, PHPStan, `opis/json-schema` (draft 2020-12, already vendored in Phase 0). Conformance harness under `tests/conformance/` (`Support/ConformanceValidator.php`, `CoreChecklistTest.php`).

## Global Constraints

- **Contract:** CAP **0.5.0**, `coqui-agent-spec` @ `5dffc63`, vendored read-only at `tests/conformance/spec/`. On any coqui-vs-schema conflict, **`schema/*.json` wins**. The normative DDL is `tests/conformance/spec/schema/sql/coqui.reference.sql`.
- **No-legacy rule:** no migration shims, no ALTER-chains for reshaped tables, no back-compat aliases, no dual keys/routes/columns. Recreate the store from empty. There is no installed base.
- **Version marker:** a `meta(key TEXT PRIMARY KEY, value TEXT NOT NULL)` table with row `('schema_version','0.5.0')`. The field name stays `meta.schema_version` (value tracks protocol_version `0.5.0`). On open, if a stamp exists and ≠ `0.5.0` → **refuse to open for writing** (throw); no in-place migration.
- **Identity rename is done (Phase 1):** the identity concept is `persona`; the session-owner id is `persona_id`. NEVER touch the capability `profiles[]` sense, `ToolProfileResolver`/`toolProfile`/`TOOL_PROFILE_*`, perf `test:profile`/`COQUI_TEST_PROFILE_*`, or `LeanDefaultProfilePrecedenceTest`.
- **Safety (AGENTS.md):** `src/Config/CatastrophicBlacklist.php` and its test stay byte-unchanged. Audit logging intact. Destructive ops gated. Shell/fs sandboxing enforced.
- **Timestamps:** RFC-3339 UTC, Z-suffixed, via `src/Support/Clock.php` (`gmdate('Y-m-d\TH:i:s\Z')`). Nullable timestamps use the same format or `null` (CAP `NullableTimestamp`); never a non-Z offset.
- **Empty-object gotcha:** `json_encode([])` → `[]` (array). Any field that must be a JSON object when empty (`avatar`, `preferences`, `metadata`, `action`, …) MUST be emitted as `stdClass`/`(object)[]`, and stored JSON columns decoded with `json_decode($s, false)` (objects, not assoc) before validation.
- **Green per task:** `composer test` (Pest) + `composer analyse` (PHPStan) pass after every task. Never commit red. Commit per task.
- **Worktree:** `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration` (branch `feat/cap-0.5-conformance`, unpushed). NEVER mutate the primary checkout `/home/carmelo/Projects/CoquiBot/Core/coqui` or the read-only spec repo `/home/carmelo/Projects/CoquiBot/Core/coqui-agent-spec`.

## Recreate-from-empty policy (applies to every reshape task)

For each table this plan reshapes: **rewrite the base `CREATE TABLE IF NOT EXISTS` in that store's `createTables()`** to the intended 0.5.0 shape, and **remove the now-redundant `migrateAddColumn`/`migrate()` calls** that added columns already present in the rewritten base DDL. Because DBs are always created fresh by current code, folding accreted columns into the base DDL is safe (any surviving `addColumnIfMissing` would be an idempotent no-op — but drop them for cleanliness per the no-legacy rule). Keep every coqui operational column that current code reads/writes; only DELETE a column when a task explicitly says so (dead artifact columns, `loops.project_id`). Enable `PRAGMA foreign_keys = ON` where the reference SQL requires FK enforcement (Task 1).

## Producer test pattern (applies to every producer task)

Each producer task adds a Pest test under `tests/conformance/Producers/<Object>ProducerTest.php` and turns the matching row green in `CoreChecklistTest.php` (replace the `$rows` string — which becomes `test($row)->todo()` — with a real `it('CORE-N: ...', …)->group('conformance')`). The assertion primitive:

```php
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

$v = new ConformanceValidator();
expect($v->isValid('<object>.json', $produced))
    ->toBeTrue($v->errorText('<object>.json', $produced));
```

`$produced` is the array/object the coqui serializer emits (build a real row through the store, then serialize). Emit empty JSON objects as `stdClass`. Timestamps via `Clock`.

---

## File Structure

Stores reshaped (DDL owners):
- `src/Storage/SessionStorage.php` — owns `meta` (new), `personas` (new), `sessions`, `session_group_members`, `turns`, `child_runs`, `content` (new), `messages`, `audit_log`, `background_tasks`, `questions`. Reshapes: sessions, turns, child_runs; adds meta, personas, content.
- `src/Storage/LoopStore.php` — owns `loops`, `loop_iterations`, `loop_stages`. Reshape: loops (add diagnostics, drop project_id).
- `src/Storage/ScheduleStore.php` — owns `scheduled_tasks`. Reshape.
- `src/Storage/SkillLifecycleStore.php` — owns `skill_usage_events`; ADD new `skills` catalog table.
- `src/Storage/ArtifactStore.php` — owns `artifacts`. Reshape: drop dead columns.
- `src/Memory/MemoryStore.php` — owns `memories`. Add `version` column only.

Serializers (row→wire) touched: `SessionStorage::normalizeSessionRow`/`normalizeTurnRow`, `SessionHandler::normalizeSessionForResponse`/`childRuns`, `TurnHandler`, `LoopHandler::normalizeLoop`, plus new per-object serializer methods where none exist. Single wire encoder `src/Api/Router.php::jsonResponse` (no `JSON_FORCE_OBJECT`).

New producer code lives beside the store/serializer it exercises; new conformance tests under `tests/conformance/Producers/` and `tests/conformance/Storage/`.

---

### Task 1: `meta` table + fail-closed-open + `PRAGMA foreign_keys`

**Files:**
- Modify: `src/Storage/SessionStorage.php` (constructor/init + `createTables()` L61) — add `meta` DDL, seed `schema_version`, fail-closed-open check, and `PRAGMA foreign_keys = ON`.
- Create: `tests/conformance/Storage/MetaMarkerTest.php`

**Interfaces:**
- Consumes: nothing (foundation).
- Produces: `meta(key,value)` with `('schema_version','0.5.0')`; a private `assertSchemaVersion(PDO): void` that throws a `RuntimeException` when an existing `schema_version` stamp ≠ `'0.5.0'`. Public constant `SCHEMA_VERSION = '0.5.0'` on `SessionStorage`.

- [ ] **Step 1: Write the failing test**

```php
// tests/conformance/Storage/MetaMarkerTest.php
use CoquiBot\Coqui\Storage\SessionStorage;

it('stamps schema_version 0.5.0 on a fresh store', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'coqui_meta_') . '.db';
    new SessionStorage($tmp);
    $pdo = new PDO('sqlite:' . $tmp);
    $val = $pdo->query("SELECT value FROM meta WHERE key='schema_version'")->fetchColumn();
    expect($val)->toBe('0.5.0');
});

it('refuses to open a store stamped with a different schema_version', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'coqui_meta_') . '.db';
    new SessionStorage($tmp);                       // stamps 0.5.0
    $pdo = new PDO('sqlite:' . $tmp);
    $pdo->exec("UPDATE meta SET value='0.4.0' WHERE key='schema_version'");
    expect(fn () => new SessionStorage($tmp))
        ->toThrow(RuntimeException::class);
});

it('enables foreign_keys on the connection', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'coqui_meta_') . '.db';
    $store = new SessionStorage($tmp);
    $on = $store->pdo()->query('PRAGMA foreign_keys')->fetchColumn();  // if no pdo() accessor, assert via a FK violation instead
    expect((int) $on)->toBe(1);
})->skip(!method_exists(\CoquiBot\Coqui\Storage\SessionStorage::class, 'pdo'), 'no pdo accessor');
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/conformance/Storage/MetaMarkerTest.php`
Expected: FAIL (no `meta` table / no fail-closed check).

- [ ] **Step 3: Implement**

In `SessionStorage`: add `public const SCHEMA_VERSION = '0.5.0';`. In the init path (constructor, before `createTables()` runs its content) execute `PRAGMA foreign_keys = ON` on the PDO. In `createTables()`, add:

```sql
CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
```

Then `INSERT OR IGNORE INTO meta(key,value) VALUES ('schema_version','0.5.0')`. Add a private `assertSchemaVersion(PDO $db): void` called during init AFTER the meta table exists and the seed ran: read the stored `schema_version`; if a value is present and `!== self::SCHEMA_VERSION`, throw `new \RuntimeException("Unsupported schema_version '{$stored}'; this build supports " . self::SCHEMA_VERSION . '. No in-place migration (CAP 0.5.0 fail-closed).')`. (On a fresh DB the seed makes the stamp `0.5.0`, so the check passes. The check only fires when a pre-stamped DB carries a different version.)

Note the FK-enable ordering: `PRAGMA foreign_keys = ON` must run on the same connection before FK-bearing writes. If other stores share this PDO (Project/Artifact/Loop/etc. via `coqui.db`), confirm they receive a FK-enabled connection — the pragma is per-connection.

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/pest tests/conformance/Storage/MetaMarkerTest.php`
Expected: PASS.

- [ ] **Step 5: Full suite + analyse**

Run: `composer test && composer analyse`
Expected: PASS / clean. (Watch for tests that open a DB twice or share a fixture DB across versions — none should stamp a non-0.5.0 value; if any pre-existing fixture DB file is committed, it must be removed, not migrated.)

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(storage): add meta.schema_version marker + fail-closed-open + PRAGMA foreign_keys"
```

---

### Task 2: `personas` index table + Persona producer → CORE-1

**Files:**
- Modify: `src/Storage/SessionStorage.php` (`createTables()` — add `personas` DDL + indexes)
- Create: `src/Persona/PersonaSnapshotStore.php` (sync file-based personas → `personas` rows; serialize a row → CAP `persona.json` wire object)
- Create: `tests/conformance/Producers/PersonaProducerTest.php`
- Modify: `tests/conformance/CoreChecklistTest.php` (turn CORE-1 green)

**Interfaces:**
- Consumes: `src/Config/PersonaParser.php`, `src/Config/PersonaDiscovery.php` (file authoring source), `Clock`.
- Produces: `personas(id, name, avatar, model, allowed_roles, soul, backstory, context, preferences, version, created_at, updated_at)`; `PersonaSnapshotStore::toWire(array $row): array` emitting the CAP Persona object.

**Target DDL** (verbatim from reference SQL L14-28):

```sql
CREATE TABLE IF NOT EXISTS personas (
    id            TEXT PRIMARY KEY,
    name          TEXT NOT NULL UNIQUE,
    avatar        TEXT NOT NULL,
    model         TEXT NOT NULL,
    allowed_roles TEXT NOT NULL,
    soul          TEXT NOT NULL,
    backstory     TEXT,
    context       TEXT,
    preferences   TEXT,
    version       INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
```

Add `CREATE INDEX IF NOT EXISTS idx_sessions_persona ON sessions(persona_id);` alignment is handled in Task 3; here only the personas table + its own uniqueness.

**Wire shape** (`persona.json`, required `id,name,avatar,model,allowed_roles,soul,version,created_at,updated_at`): `avatar` is an OBJECT `{tint, image_ref?}` (emit `stdClass`/`(object)` — never `[]`); `allowed_roles` array MUST contain `"orchestrator"`; `backstory`/`context`/`preferences` nullable; `version` integer ≥1; timestamps Z. Decode stored JSON columns with `json_decode($s, false)`.

- [ ] **Step 1: Write the failing producer test**

```php
// tests/conformance/Producers/PersonaProducerTest.php
use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

it('CORE-1: produces a schema-valid Persona whose allowed_roles includes orchestrator', function () {
    $row = [
        'id' => '01J000000000000000000PERSONA',
        'name' => 'Caelum',
        'avatar' => json_encode(['tint' => '#2b3a52']),
        'model' => 'anthropic/claude-sonnet-4',
        'allowed_roles' => json_encode(['orchestrator']),
        'soul' => 'You are Caelum, a warm, precise research companion.',
        'backstory' => null,
        'context' => null,
        'preferences' => null,
        'version' => 1,
        'created_at' => '2026-07-28T00:00:00Z',
        'updated_at' => '2026-07-28T00:00:00Z',
    ];
    $wire = PersonaSnapshotStore::toWire($row);
    $v = new ConformanceValidator();
    expect($v->isValid('persona.json', $wire))->toBeTrue($v->errorText('persona.json', $wire));
    expect($wire['allowed_roles'])->toContain('orchestrator');
    expect($wire['avatar'])->toBeInstanceOf(stdClass::class);   // empty/object avatar, never []
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/conformance/Producers/PersonaProducerTest.php`
Expected: FAIL (`PersonaSnapshotStore` absent).

- [ ] **Step 3: Implement the DDL + `PersonaSnapshotStore::toWire`**

Add the `personas` DDL to `createTables()`. Implement `PersonaSnapshotStore::toWire(array $row): array` returning:
- `id`,`name`,`model`,`soul` verbatim; `version` cast `(int)`; `created_at`,`updated_at` verbatim (already Z).
- `avatar` = `json_decode($row['avatar'], false)` (a `stdClass`; if it decodes to an empty array, cast `(object)`).
- `allowed_roles` = `json_decode($row['allowed_roles'], true)` (array of strings).
- `backstory` = `$row['backstory']` (nullable string).
- `context` = `$row['context'] !== null ? json_decode($row['context'], true) : null`.
- `preferences` = `$row['preferences'] !== null ? json_decode($row['preferences'], false) : null` (object when present).

Also implement `syncFromFiles(PersonaDiscovery, PersonaParser): void` that upserts each authored persona into `personas` (id = stable id per persona — reuse the persona name-slug as id if no ULID source exists, or generate a ULID once and persist; **decision:** derive a stable id from the persona slug to stay deterministic and file-driven, e.g. `id = 'persona_' . slug`; document this as the opaque `Id`). Set `version = 1` on first insert, bump on content change. (Sync wiring beyond producibility is exercised lightly here; full API serving is Phase 4/5.)

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/pest tests/conformance/Producers/PersonaProducerTest.php`
Expected: PASS.

- [ ] **Step 5: Turn CORE-1 green + full suite**

In `CoreChecklistTest.php`, remove the `CORE-1: …` string from `$rows` and add above the todo loop a real `it('CORE-1: persona allowed_roles includes orchestrator', …)` that constructs the same wire object and asserts `isValid('persona.json', …)` + `toContain('orchestrator')`. Run `composer test && composer analyse`.
Expected: PASS / clean; CORE-1 no longer a todo.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(storage): add personas index table + Persona producer (CORE-1)"
```

---

### Task 3: `sessions` reshape + Session producer → CORE-15, CORE-19

**Files:**
- Modify: `src/Storage/SessionStorage.php` — rewrite `sessions` base DDL; add `pinned`,`workspace`,`version`,`kind`; make `model` nullable; drop the redundant `migrateAddColumn` calls for columns now in base DDL; keep operational columns (group_*, session_type, visibility, is_closed, is_archived, closed_at, archived_at, closure_reason, active_project_id — active_project_id is removed later in Phase 3, keep for now); update `normalizeSessionRow`/`hydrateSessionRow`.
- Modify: `src/Api/Handler/SessionHandler.php` — `normalizeSessionForResponse` emits the CAP wire shape.
- Create: `tests/conformance/Producers/SessionProducerTest.php`
- Modify: `tests/conformance/CoreChecklistTest.php` (CORE-15, CORE-19 green)

**Interfaces:**
- Consumes: Task 1 (`meta`/FK), Task 2 (`personas` FK target). `session_group_members` (existing) for `members[]`.
- Produces: reshaped `sessions`; `SessionHandler::normalizeSessionForResponse` → CAP `session.json`.

**Wire shape** (`session.json`, required `id,persona_id,members,kind,status,pinned,version,created_at,updated_at`):
- `members`: array of persona Id (unique) = `{persona_id}` ∪ session_group_members.persona_id, `uniqueItems`.
- `kind`: `'chat' | 'loop_workscope'` — from the new `kind` column (default `'chat'`; loop work-scope sessions set `'loop_workscope'`).
- `status`: DERIVE — `is_archived ? 'archived' : (is_closed ? 'closed' : 'active')`.
- `pinned`: boolean from `pinned` column.
- `model`: `oneOf[ModelId,null]` — null passes through (do NOT back-fill from the role resolver into the wire object; null ⇒ inherit is the CAP semantic, CORE-15). Keep any resolver back-fill for internal use out of the wire object.
- `workspace`: `oneOf[string,null]` from the new `workspace` column.
- `title`: nullable; `token_count`: integer; `version`: integer ≥1; timestamps Z.

**Reshaped base DDL** (fold current accreted columns + add CAP columns):

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id                    TEXT PRIMARY KEY,
    persona_id            TEXT,                              -- FK personas(id); nullable until a persona is bound
    model_role            TEXT NOT NULL,
    model                 TEXT,                              -- CAP: nullable ⇒ inherit (was NOT NULL) — CORE-15
    kind                  TEXT NOT NULL DEFAULT 'chat',      -- chat | loop_workscope
    pinned                INTEGER NOT NULL DEFAULT 0,
    workspace             TEXT,                              -- opaque locator; null ⇒ none — CORE-19
    version               INTEGER NOT NULL DEFAULT 1,        -- optimistic-concurrency token
    title                 TEXT DEFAULT NULL,
    active_project_id     TEXT DEFAULT NULL,
    group_enabled         INTEGER NOT NULL DEFAULT 0,
    group_composition_key TEXT DEFAULT NULL,
    group_max_rounds      INTEGER DEFAULT NULL,
    session_type          TEXT NOT NULL DEFAULT 'interactive',
    visibility            TEXT NOT NULL DEFAULT 'visible',
    is_closed             INTEGER NOT NULL DEFAULT 0,
    is_archived           INTEGER NOT NULL DEFAULT 0,
    closed_at             TEXT DEFAULT NULL,
    archived_at           TEXT DEFAULT NULL,
    closure_reason        TEXT DEFAULT NULL,
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL,
    token_count           INTEGER DEFAULT 0
)
```

Keep the existing session indexes (L250-254). Do NOT add a FK to personas yet if `persona_id` can be null/unbound in current flows — the reference SQL makes it `NOT NULL … RESTRICT`, but coqui creates sessions before binding a persona in some paths; **decision:** keep `persona_id` nullable in the column, enforce the persona relationship at the application layer, and note the FK-strictness gap as a Phase 4 carry-forward (wire `persona_id` is required by schema — the producer test uses a bound persona). Remove `migrateAddColumn` calls for `title`,`active_project_id`,`persona_id`,`group_*`,`session_type`,`visibility`,`is_closed`,`is_archived`,`closed_at`,`archived_at`,`closure_reason` (now in base DDL); keep the data-repair `UPDATE`s only if they still apply to a fresh DB (they should be no-ops — remove if dead).

- [ ] **Step 1: Write the failing producer test**

```php
// tests/conformance/Producers/SessionProducerTest.php
use CoquiBot\Coqui\Api\Handler\SessionHandler;   // or the serializer entrypoint
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

it('CORE-15/19: produces a schema-valid Session (nullable model, workspace, derived status/members)', function () {
    // Build a real session row through SessionStorage, bound to a persona, then serialize.
    // (Use the test bootstrap helpers already used by SessionHandler tests.)
    $wire = /* SessionHandler::normalizeSessionForResponse($row, ...) */ null;
    $v = new ConformanceValidator();
    expect($v->isValid('session.json', $wire))->toBeTrue($v->errorText('session.json', $wire));
    expect($wire['members'])->toBeArray()->toContain($wire['persona_id']);
    expect(array_key_exists('model', $wire))->toBeTrue();      // nullable, present
    expect(array_key_exists('workspace', $wire))->toBeTrue();
    expect($wire['status'])->toBeIn(['active', 'archived', 'closed']);
    expect($wire['kind'])->toBeIn(['chat', 'loop_workscope']);
});
```

(Use the existing SessionHandler test fixtures/bootstrap to construct a persisted session + bound persona; the implementer wires the concrete construction.)

- [ ] **Step 2: Run to verify it fails** — Expected: FAIL.

- [ ] **Step 3: Implement** the reshaped DDL, drop the folded `migrateAddColumn` calls, update `normalizeSessionRow`/`hydrateSessionRow` to surface `pinned`/`workspace`/`version`/`kind`, and rewrite `SessionHandler::normalizeSessionForResponse` to emit the CAP wire object (derive `status`, build `members[]`, pass `model` through nullable, cast booleans). Ensure loop work-scope session creation sets `kind='loop_workscope'`.

- [ ] **Step 4: Run to verify it passes** — Expected: PASS.

- [ ] **Step 5: Turn CORE-15 + CORE-19 green + full suite.** Replace both rows in `CoreChecklistTest.php` with real assertions (nullable model present; workspace present + inheritable). Run `composer test && composer analyse`. Watch the group-session tests and any assertion on the old session response shape (e.g. `model` non-null, absence of `pinned`) — update them to the CAP shape (this is a real wire change, not a regression).

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(storage): reshape sessions to CAP 0.5.0 + Session producer (CORE-15, CORE-19)"
```

---

### Task 4: `turns` reshape + Turn producer → CORE-34

**Files:**
- Modify: `src/Storage/SessionStorage.php` — add `turns.actor_persona_id`, add `turns.status` (derive-backed), fold token columns already present; update `normalizeTurnRow`.
- Modify: `src/Api/Handler/TurnHandler.php` — emit CAP `turn.json`.
- Create: `tests/conformance/Producers/TurnProducerTest.php`; modify `CoreChecklistTest.php` (CORE-34).

**Interfaces:**
- Consumes: Task 3 (sessions FK). Produces: reshaped `turns`; Turn serializer → `turn.json`.

**Wire shape** (`turn.json`, required `id,session_id,turn_number,user_prompt,status,created_at`): `actor_persona_id` nullable (REQUIRED to be present-and-non-null in a group session — 422 `missing_field` enforcement is Phase 4; here just carry the column + emit it); `status` enum `running|completed|failed|cancelled` — DERIVE from existing state (`completed_at` set → `completed`; error/result_payload error → `failed`; else `running`) OR from a new `status` column set at write time. **Decision:** add a `status TEXT NOT NULL DEFAULT 'running'` column (reference SQL L70) and set it at turn completion, since coqui has no single status source today; keep `completed_at` too. `model` non-null string here; token fields present; `tools_used` nullable JSON array; `completed_at` NullableTimestamp.

**Reshaped `turns` DDL** (add `actor_persona_id`, `status`; keep coqui's `child_agent_count`/`turn_process_id`/`result_payload`):

```sql
CREATE TABLE IF NOT EXISTS turns (
    id                TEXT PRIMARY KEY,
    session_id        TEXT NOT NULL,
    actor_persona_id  TEXT,
    turn_number       INTEGER NOT NULL,
    user_prompt       TEXT NOT NULL,
    response_text     TEXT,
    model             TEXT,
    prompt_tokens     INTEGER NOT NULL DEFAULT 0,
    completion_tokens INTEGER NOT NULL DEFAULT 0,
    total_tokens      INTEGER NOT NULL DEFAULT 0,
    iterations        INTEGER NOT NULL DEFAULT 0,
    duration_ms       INTEGER NOT NULL DEFAULT 0,
    tools_used        TEXT,
    status            TEXT NOT NULL DEFAULT 'running',
    child_agent_count INTEGER DEFAULT 0,
    turn_process_id   TEXT DEFAULT NULL,
    result_payload    TEXT DEFAULT NULL,
    created_at        TEXT NOT NULL,
    completed_at      TEXT,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
)
```

- [ ] **Step 1: Failing producer test** — `it('CORE-34: produces a schema-valid Turn carrying actor_persona_id', …)`: build a turn row, serialize, assert `isValid('turn.json', $wire)`, assert `array_key_exists('actor_persona_id', $wire)` and `$wire['status']` in the enum.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** DDL (add `actor_persona_id`,`status`), set `status` at turn create/complete, thread `actor_persona_id` at turn create (from the acting persona; null in solo), update `normalizeTurnRow` + `TurnHandler` to emit `turn.json` (decode `tools_used`, map status/tokens/timestamps).
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-34 green + `composer test && composer analyse`.** Update any turn-shape assertions.
- [ ] **Step 6: Commit** — `feat(storage): reshape turns + actor_persona_id + Turn producer (CORE-34)`

---

### Task 5: `child_runs` realignment + ChildRun producer → CORE-28

**Files:**
- Modify: `src/Storage/SessionStorage.php` — rewrite `child_runs` DDL to reference shape; update `logChildRun()` (L1699) + `getChildRuns()` (L1736).
- Modify: `src/Api/Handler/SessionHandler.php::childRuns()` — emit CAP `child-run.json`; and the caller `src/Tool/SpawnAgentTool.php` (and `ChildAgent`) that write child-run rows.
- Create: `tests/conformance/Producers/ChildRunProducerTest.php`; modify `CoreChecklistTest.php` (CORE-28).

**Interfaces:** Consumes Task 3/4 (sessions/turns FKs). Produces reshaped `child_runs` + serializer → `child-run.json`.

**Reshaped DDL** (reference SQL L191-207): rename `session_id`→`parent_session_id`, `agent_role`→`role`; add `parent_turn_id`, `status`, `completion_tokens`, `total_tokens`; make `model` nullable; split `token_count`→`prompt_tokens`/`completion_tokens`/`total_tokens`; `result` nullable; add `completed_at`; drop `parent_iteration`, `metadata` (or keep `metadata` internally — **decision:** drop `parent_iteration` and `metadata` from the wire; keep `metadata` as an internal column only if a caller reads it, else drop). FK `parent_session_id`→sessions CASCADE, `parent_turn_id`→turns SET NULL.

```sql
CREATE TABLE IF NOT EXISTS child_runs (
    id                 TEXT PRIMARY KEY,
    parent_session_id  TEXT NOT NULL,
    parent_turn_id     TEXT,
    role               TEXT NOT NULL,
    model              TEXT,
    prompt             TEXT NOT NULL,
    result             TEXT,
    status             TEXT NOT NULL,
    prompt_tokens      INTEGER NOT NULL DEFAULT 0,
    completion_tokens  INTEGER NOT NULL DEFAULT 0,
    total_tokens       INTEGER NOT NULL DEFAULT 0,
    created_at         TEXT NOT NULL,
    completed_at       TEXT,
    FOREIGN KEY (parent_session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_turn_id)    REFERENCES turns(id)    ON DELETE SET NULL
)
```

**Wire shape** (`child-run.json`, required `id,parent_session_id,role,prompt,status,created_at`): `status` enum `pending|running|completed|failed|cancelled` (coqui runs children synchronously → emit `completed`/`failed` after the in-process run); `model` nullable; `parent_turn_id` nullable; token fields; `completed_at` nullable.

- [ ] **Step 1: Failing producer test** — build a child_run via `logChildRun`, serialize via `childRuns()` shape, assert `isValid('child-run.json', $wire)`; assert `status` in enum and `parent_session_id` present.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** DDL rename/realign; update `logChildRun()` signature to accept `parent_session_id`,`parent_turn_id`,`role`,`model`(nullable),`prompt`,`status`,token split,`completed_at`, and update `SpawnAgentTool`/`ChildAgent` callers to pass the new fields (map current synchronous run: role from agent role, status `completed` on success / `failed` on error, tokens from the child result). Update `getChildRuns()` + `SessionHandler::childRuns()` serializer.
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-28 green + `composer test && composer analyse`.** Update `SpawnAgentTool`/child-run tests to the new column names.
- [ ] **Step 6: Commit** — `feat(storage): realign child_runs to CAP 0.5.0 + ChildRun producer (CORE-28)`

---

### Task 6: `content` table + Content producer → CORE-42

**Files:**
- Modify: `src/Storage/SessionStorage.php` — add `content` DDL.
- Create: `src/Content/ContentStore.php` (insert content-addressed blobs; serialize → `content.json`).
- Create: `tests/conformance/Producers/ContentProducerTest.php`; modify `CoreChecklistTest.php` (CORE-42).

**Interfaces:** Produces `content(content_ref PK, mime_type, size, sha256, created_at)` + serializer.

**DDL** (reference SQL notes content id = content_ref):

```sql
CREATE TABLE IF NOT EXISTS content (
    content_ref TEXT PRIMARY KEY,
    mime_type   TEXT NOT NULL,
    size        INTEGER NOT NULL,
    sha256      TEXT NOT NULL,
    created_at  TEXT NOT NULL
)
```

**Wire shape** (`content.json`, ALL required, no nullables): `content_ref` Id; `mime_type` non-empty; `size` int ≥0; `sha256` lowercase hex `^[0-9a-f]{64}$`; `created_at` Z. The producer computes `sha256 = hash('sha256', $bytes)` and `size = strlen($bytes)`.

- [ ] **Step 1: Failing producer test** — `it('CORE-42: produces a schema-valid Content addressed by sha256', …)`: hash some bytes, build a row, serialize, assert `isValid('content.json', $wire)` and that `sha256` matches `/^[0-9a-f]{64}$/`.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** the DDL + `ContentStore::store(string $bytes, string $mimeType): array` (returns the wire object; content_ref = a generated Id or the sha256-derived ref) + `toWire`.
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-42 green + `composer test && composer analyse`.**
- [ ] **Step 6: Commit** — `feat(storage): add content table + Content producer (CORE-42)`

---

### Task 7: `skills` catalog table + Skill producer → CORE-26, CORE-27

**Files:**
- Modify: `src/Storage/SkillLifecycleStore.php` (or a new `SkillCatalogStore`) — add `skills` DDL alongside `skill_usage_events`.
- Create: serializer producing `skill.json`.
- Create: `tests/conformance/Producers/SkillProducerTest.php`; modify `CoreChecklistTest.php` (CORE-26, CORE-27).

**DDL** (reference SQL L273-275):

```sql
CREATE TABLE IF NOT EXISTS skills (
    name        TEXT PRIMARY KEY,
    description TEXT,
    metadata    TEXT,
    source      TEXT,
    status      TEXT NOT NULL,
    origin      TEXT,
    execution   TEXT,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
)
```

**Wire shape** (`skill.json`, required `name,description,status,origin,execution`): `name` Slug; `status` `available|disabled`; `origin` OBJECT required `kind` ∈ `builtin|local|imported` (+ nullable publisher/signature) — emit as `stdClass`/object; `execution` OBJECT required `kind` ∈ `instruction|script` (+ `requires` array default `[]`); `metadata`/`source` nullable; timestamps nullable.

- [ ] **Step 1: Failing producer test** — build a skill row (origin `{kind:'builtin'}`, execution `{kind:'instruction','requires':[]}`), serialize, assert `isValid('skill.json', $wire)`; assert `origin` and `execution` are objects with valid `kind`.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** DDL + serializer (decode `origin`/`execution` JSON columns with `json_decode($s, false)`; ensure `execution.requires` defaults to `[]`). Populate from skill discovery where available; a producer-only path is acceptable for this task (full catalog sync is Phase 5).
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-26 + CORE-27 green + `composer test && composer analyse`.**
- [ ] **Step 6: Commit** — `feat(storage): add skills catalog table + Skill producer (CORE-26, CORE-27)`

---

### Task 8: `scheduled_tasks` reshape + ScheduledTask producer → CORE-33

**Files:**
- Modify: `src/Storage/ScheduleStore.php` — reshape DDL (`schedule_expression`→`cron`; add `persona_id`, `action`); drop the bespoke `migrate()` accretion for folded columns; keep operational columns ScheduleManager needs (role/prompt/max_iterations/timezone/run_count/failure_count/next_run_at/last_run_at/etc.).
- Modify: `src/Schedule/ScheduleManager.php` (+ any code reading `schedule_expression`) — use `cron`; carry `persona_id`.
- Create: serializer → `scheduled-task.json`; `tests/conformance/Producers/ScheduledTaskProducerTest.php`; modify `CoreChecklistTest.php` (CORE-33).

**Reshape approach:** rename `schedule_expression`→`cron`; add `persona_id TEXT` and an `action TEXT` JSON column (or derive `action` in the serializer from existing `role`+`prompt`). **Decision:** add `persona_id` column and DERIVE `action` in the serializer as `{kind:'turn', prompt:<prompt>}` (coqui schedules are turn-kind today); reserve the `loop` action kind for Phase 5. Keep `prompt`/`role` columns for execution.

**Wire shape** (`scheduled-task.json`, required `id,name,cron,persona_id,action,status,created_at`): `name` plain string; `cron` string; `persona_id` Id; `action` OBJECT oneOf (`{kind:'turn',prompt}` or `{kind:'loop',definition_name}`) — emit `stdClass`/object; `status` `enabled|disabled` (derive from `enabled` int); `last_run_at`/`next_run_at`/`updated_at` nullable; `created_at` Z.

- [ ] **Step 1: Failing producer test** — build a scheduled task (persona bound, action turn), serialize, assert `isValid('scheduled-task.json', $wire)`; assert `action->kind === 'turn'` and `status` in `{enabled,disabled}`.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** DDL rename/add, update `ScheduleManager` + all `schedule_expression` references to `cron`, add `persona_id` write path, serializer emitting `action` object + derived `status`.
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-33 green + `composer test && composer analyse`.** Update ScheduleStore/Manager tests for `cron`.
- [ ] **Step 6: Commit** — `feat(storage): reshape scheduled_tasks to CAP 0.5.0 + ScheduledTask producer (CORE-33)`

---

### Task 9: `loops` diagnostics + project-column removal + Loop producer → CORE-16

**Files:**
- Modify: `src/Storage/LoopStore.php` — add `rework_attempts`,`dispatch_state`,`last_dispatch_error`,`origin`; DROP `project_id`; keep `configuration`/`metadata`.
- Modify: `src/Api/Handler/LoopHandler.php::normalizeLoop` — emit CAP `loop.json`; migrate escalation/dispatch state out of the `metadata` blob into the new columns.
- Create: `tests/conformance/Producers/LoopProducerTest.php`; modify `CoreChecklistTest.php` (CORE-16).

**Reshaped `loops` DDL** (reference SQL L118-146; add `persona_id`, `origin`, diagnostics; drop `project_id`; note reference has `session_id` nullable + FK SET NULL):

```sql
CREATE TABLE IF NOT EXISTS loops (
    id                   TEXT PRIMARY KEY,
    definition_name      TEXT NOT NULL,
    persona_id           TEXT NOT NULL,
    session_id           TEXT,
    goal                 TEXT NOT NULL,
    status               TEXT NOT NULL DEFAULT 'running',
    current_iteration    INTEGER NOT NULL DEFAULT 0,
    current_stage        INTEGER NOT NULL DEFAULT 0,
    max_iterations       INTEGER,
    deadline             TEXT,
    termination_criteria TEXT,
    configuration        TEXT,
    origin               TEXT NOT NULL DEFAULT 'conversation',
    started_at           TEXT NOT NULL,
    completed_at         TEXT,
    last_activity_at     TEXT,
    rework_attempts      INTEGER NOT NULL DEFAULT 0,
    dispatch_state       TEXT NOT NULL DEFAULT 'pending',
    last_dispatch_error  TEXT,
    metadata             TEXT,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
)
```

(Reference SQL adds `FOREIGN KEY(persona_id)…RESTRICT`; add `persona_id` and thread it from loop creation. If loop creation currently lacks a persona, source it from the owning session's `persona_id`.)

**Wire shape** (`loop.json`, required `id,definition_name,persona_id,goal,status,origin,created_at`): note wire uses `created_at` — map coqui's `started_at`→`created_at` in the serializer (or rename the column; **decision:** keep coqui column `started_at` internally and map to wire `created_at`). `status` enum incl. `blocked`; `origin` `conversation|headless`; `rework_attempts` int; `dispatch_state` `pending|dispatched`; `last_dispatch_error` nullable; `metadata` nullable object.

- [ ] **Step 1: Failing producer test** — build a loop row, serialize, assert `isValid('loop.json', $wire)`; assert `rework_attempts`/`dispatch_state`/`origin` present and valid.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** DDL (add diagnostics + `persona_id` + `origin`, drop `project_id`), thread `persona_id`/`origin` at loop creation, move dispatch/escalation reads from `metadata` to the new columns in `LoopHandler`, emit `loop.json` (`started_at`→`created_at`).
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-16 green + `composer test && composer analyse`.** Update loop tests referencing `project_id`/metadata escalation.
- [ ] **Step 6: Commit** — `feat(storage): loop diagnostics + drop project_id + Loop producer (CORE-16)`

---

### Task 10: `artifacts` dead-column removal + Artifact producer → CORE-25

**Files:**
- Modify: `src/Storage/ArtifactStore.php` — DROP dead columns `stage`,`persistent`,`storage_mode`,`canonical_path` from base DDL + INSERT; keep `version`,`path`,`content_hash`.
- Modify: the artifact serializer/handler to emit CAP `artifact.json` (session_id required).
- Create: `tests/conformance/Producers/ArtifactProducerTest.php`; modify `CoreChecklistTest.php` (CORE-25).

**Notes:** reference SQL artifacts (profile-gated) = `id, session_id, name, type, content_ref, metadata, created_at`. coqui's artifacts are richer (files-only index). Read `schema/artifact.json` for the exact wire shape before serializing; `session_id` is required. Recreate-from-empty: rewrite the base DDL without the four dead columns, and remove them from the `create()` INSERT column list (currently writes `persistent`/`storage_mode('filesystem')`/`canonical_path`).

- [ ] **Step 1: Failing producer test** — build an artifact row, serialize, assert `isValid('artifact.json', $wire)`; assert `session_id` present.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** — read `schema/artifact.json`; rewrite `artifacts` base DDL without dead columns; update `create()`/`update()` INSERT/UPDATE column lists; add/adjust serializer to the CAP shape (map `content` addressing to `content_ref` where the schema requires it; if the schema expects `content_ref`, emit it from the files-only path — coordinate with Task 6 Content).
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-25 green + `composer test && composer analyse`.** Update artifact tests that referenced dropped columns.
- [ ] **Step 6: Commit** — `feat(storage): drop dead artifact columns + Artifact producer (CORE-25)`

---

### Task 11: Question producer + `memories.version` → CORE-24

**Files:**
- Modify: `src/Storage/SessionStorage.php` — confirm `questions` DDL (exists L312-326); add any missing CAP columns per `schema/question.json`.
- Modify: `src/Memory/MemoryStore.php` — add `memories.version INTEGER NOT NULL DEFAULT 1` to the base DDL (five-mutable-objects; wire producibility of memory.json is NOT a Phase 2 exit row — column only).
- Create: Question serializer → `question.json`; `tests/conformance/Producers/QuestionProducerTest.php`; modify `CoreChecklistTest.php` (CORE-24).

**Notes:** read `schema/question.json` for the exact shape (required fields, `status` closed set). coqui already has a `questions` table (from `src/Question/`); serialize it to the CAP wire shape. The `memories.version` add is column-only — do NOT reshape coqui's `memories` (area/tags/importance/memory_type) in this phase; memory.json producibility is out of Phase 2 scope (flagged as a carry-forward).

- [ ] **Step 1: Failing producer test** — build a question row, serialize, assert `isValid('question.json', $wire)`; assert `status` in the closed set.
- [ ] **Step 2: Verify it fails.**
- [ ] **Step 3: Implement** — read `schema/question.json`; add missing question columns if any; add `memories.version` column to `MemoryStore::createTables`; add the Question serializer.
- [ ] **Step 4: Verify it passes.**
- [ ] **Step 5: CORE-24 green + `composer test && composer analyse`.**
- [ ] **Step 6: Commit** — `feat(storage): Question producer + memories.version (CORE-24)`

---

### Task 12: LoopDefinition producer + internal collections + export envelope → CORE-8, CORE-13, CORE-14

**Files:**
- Create: LoopDefinition producer (from the file-based definitions, `src/Contract/LoopDefinition.php`) → `loop-definition.json`; assert `termination_condition.value` matches its `type` (CORE-8).
- Create: producers/typing for internal collections `jobs`(background_tasks)/`job_events`(task_events)/`audit_records`(audit_log) for export validation (CORE-13).
- Modify/Create: the export path so the envelope types **every** Core + internal collection incl. child_runs/skills/content/scheduled_tasks (CORE-14); import fail-closed + FK-consistent (preserve+remap) — if a full export path does not exist yet, scope this task to the *typed collection map* + a producer test that each collection serializes schema-valid, and carry the roundtrip import to Phase 6 (design §4.6). **Decision:** Phase 2 asserts every collection is *producible/typed*; the preserve+remap roundtrip import is a Phase 6 gate — note this split explicitly.
- Modify: `CoreChecklistTest.php` (CORE-8, CORE-13, CORE-14); a timestamp audit test (CORE-3, CORE-59) asserting every producer's timestamps match the Z pattern and nullable timestamps are `null` or Z.

- [ ] **Step 1: Failing tests** — LoopDefinition producer validates against `loop-definition.json` with a discriminated `termination_condition` (CORE-8); each internal collection serializes schema-valid (CORE-13); the export collection map includes all Core+internal names (CORE-14); a timestamp-format test across producers (CORE-3/59).
- [ ] **Step 2: Verify they fail.**
- [ ] **Step 3: Implement** the LoopDefinition serializer (from file definitions), internal-collection serializers, the typed export collection map, and the timestamp audit assertions.
- [ ] **Step 4: Verify they pass.**
- [ ] **Step 5: CORE-8, CORE-13, CORE-14, CORE-3, CORE-59 green + `composer test && composer analyse`.**
- [ ] **Step 6: Commit** — `feat(storage): LoopDefinition + internal-collection + export typing + timestamp audit (CORE-8/13/14/3/59)`

---

## Phase exit criteria

- Producible (schema-valid) + green CORE rows: **1, 3, 8, 13, 14, 15, 16, 19, 24, 25, 26, 27, 28, 33, 34, 42, 59** (design §6 exit set; CORE-1 folded in from the Phase-1 deferral).
- `meta.schema_version = 0.5.0` stamped; fail-closed-open verified; `PRAGMA foreign_keys = ON`.
- `composer test` + `composer analyse` green; `CatastrophicBlacklist` untouched.
- Whole-branch review (base = Phase-2 start commit) clean.

## Carry-forwards flagged during planning (surface in the phase status report)

- **Memory Core reshape** (coqui `memories`: area/tags/importance/memory_type vs CAP `name/description/type`) is NOT scheduled by the design's Phase-2 exit rows; Phase 2 adds only `memories.version`. memory.json producibility needs a later phase — flag as an open gap.
- **`sessions.persona_id` FK strictness** (reference SQL: NOT NULL … RESTRICT) is relaxed to a nullable column + app-layer enforcement to avoid breaking pre-bind session creation — Phase 4 carry-forward.
- **`action.kind = 'loop'`** scheduled-task branch + schedules-profile endpoints + dialect advertisement → Phase 5 (Phase 2 produces only the `turn` branch).
- **Export preserve+remap roundtrip import** → Phase 6 gate (Phase 2 proves per-collection typing/producibility only).
- **`on_question`** is a `LoopDefinition` JSON field, not a DB column (no column to drop) — its deletion is behavioral, Phase 3 (D4).
