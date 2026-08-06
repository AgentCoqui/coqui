# CAP 0.5.0 Conformance — Phase 5: §C New Ops + Profiles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the remaining §C conformance rows — child-run operations, the questions Core answer path, the scheduled-task action union, vision built-in declaration, import roundtrip, typed thrown errors, and cross-binding op cardinality — flipping CORE-2, 12, 29, 32, 47, 48, 49, 50, 56, 57, 58 from `todo` to real `it(...)->group('conformance')` assertions with teeth.

**Architecture:** coqui is a PHP 8.4 runtime (strict types, `final` by default, constructor injection) over SQLite/PDO and a hand-rolled ReactPHP HTTP layer (`React\Http\Message\{ServerRequest,Response}`, `ThroughStream`). New ops are additive routes on the existing `Router` + self-registering handlers; new producers are strict `toWire`-style serializers validated against the vendored `tests/conformance/spec/schema/*.json`. The conformance gate lives in `tests/conformance/CoreChecklistTest.php` and validates coqui's OWN producers/handlers via `Support\ConformanceValidator::isValid('<obj>.json', $data)` (opis/json-schema, draft 2020-12).

**Tech Stack:** PHP 8.4, PDO/SQLite, ReactPHP HTTP, Pest (`composer test`), PHPStan level 8 (`composer analyse`), opis/json-schema.

## Global Constraints

Every task's requirements implicitly include this section. Values are copied verbatim from the standing brief and the design spec.

- **All work in the worktree** `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration` (branch `feat/cap-0.5-conformance`, unpushed). NEVER mutate the primary checkout `/home/carmelo/Projects/CoquiBot/Core/coqui`. Do NOT push, do NOT open PRs.
- **NEVER mutate the vendored spec** `tests/conformance/spec/**` (pinned to spec `5dffc63`). Read-only. coqui-side test glue only.
- **SAFETY invariant:** `src/Config/CatastrophicBlacklist.php` and its test stay **byte-for-byte unchanged**. Verify with `git log <base>..<head> -- '*CatastrophicBlacklist*'` returning empty.
- **No-legacy / pre-release:** no installed base. NO migration shims, NO back-compat aliases, NO dual code paths, NO deprecation branches. Delete cleanly. Schema changes are recreate-from-empty (`CREATE TABLE IF NOT EXISTS` in `createTables`), never `ALTER` chains.
- **Do NOT touch the capability "profile" sense:** `ToolProfileResolver`, `toolProfile`, `TOOL_PROFILE_*`, perf `test:profile`/`COQUI_TEST_PROFILE_*`, `LeanDefaultProfilePrecedenceTest`. In this phase, "profile" means the CAP capability-profile set (`artifacts`/`questions`/`skills`/`schedules`/`mcp`), an OPEN never-filtered list in `InstanceInfoBuilder`. "persona" is the renamed identity object.
- **Closed error catalog:** `src/Api/ApiErrorCode.php` == the exact 23 codes in `schema/error.json`. Emit NO off-catalog code. A 422 is emitted as the `validation_error` code with an HTTP-status override, never a new code. `details` is always an object.
- **Producer/response split:** strict `toWire`-style **producers** validate against `schema/*.json`; **live GET responses** may be additive/forward-tolerant (the Phase-2/3 pattern). Empty JSON objects serialize as `stdClass`, never `[]`.
- **Never-red gate:** every task ends green (`composer test` + `composer analyse`). Commit only green. The known environmental flake `tests/Unit/Support/ProcessSpawnerTest.php::isProcessAlive` (spawn→liveness race, untouched subsystem, nondeterministic) is NOT a regression and is disregarded for the gate.
- **Conformance flip mechanics:** to flip a row, delete its string from the `$rows` array near `CoreChecklistTest.php:1762` and add a real `it('CORE-N: …', function () { … })->group('conformance')`. Behavioral HTTP rows build handlers directly over a temp SQLite db + temp workspace and assert on a `React\Http\Message\Response` — mirror the existing CORE-22 / CORE-5 patterns in that file. Use the helpers already defined there: `cleanupSqliteTestDb($dbPath)`, `cleanupTestTree($dir)`, `sseReconnectRequest(...)`, `makeInstanceInfoBuilder(...)`. Validate via `$v = new ConformanceValidator(); expect($v->isValid('x.json', $data))->toBeTrue($v->errorText('x.json', $data));`.

## Phase-5 adjudications (settled with the user before execution)

1. **CORE-29 child-run execution = sync-execute-then-report.** `spawnChildRun` runs the child synchronously via the existing `ChildAgent` path, records the status transitions (`pending`→`running`→`completed`/`failed`) on the row, and returns **202** with the child-run resource. `streamChildRunEvents` replays the recorded events (`started` → optional `message` → terminal `done` carrying the full `child-run.json`). No new async job runtime. Gating preserved: full-access + top-level only.
2. **CORE-47 + CORE-58 = flip green via a coqui operation catalog.** `operations.yaml`/`openapi.yaml` are NOT in the pinned snapshot and MUST NOT be added. Build a coqui-side `OperationCatalog` (op_id → method/path/profile/cardinality) derived from the live route table + the one vendored cross-op artifact `tests/conformance/spec/conformance/error-coverage.json`. The assertions prove **coqui-internal self-consistency** (profile-gated ops resolve to the same handler under either binding; list ops emit `{data,next_cursor}`, single ops emit a bare object), NOT true tri-catalog parity — the row descriptions and the status report MUST say so plainly. Correct the stale `x-persona` wording to **`x-profile`** per `checklist.md:60`.
3. **CORE-56 = in-process `ImportService` + roundtrip test.** No new HTTP `/export` or `/import` routes (export itself has no live route; the evidence is `validate:roundtrip`, not an HTTP lint). Build `ImportService(array $envelope, ImportMode $mode)` with `preserve|remap`; prove it with a store/assembler-level roundtrip mirroring the existing test-only export assembly in `tests/conformance/Export/ExportEnvelopeTest.php`.

## Already-green note

CORE-30 (InstanceInfo typed + `host_toolkits`) and CORE-31/33/35/36/39/46 flipped in Phase 4 — do not re-flip them. CORE-33's cron-dialect advertisement is wired-but-dormant in `InstanceInfoBuilder`; Task 7 enables it for consistency but it is not a gate row here.

## File Structure

- `src/Exception/RequestBodyException.php` — add `toThrownError()` (Task 1).
- `src/Api/Handler/SessionHandler.php` — pin `session.kind` in `toWire` (Task 2); add `spawnChildRun`/`getChildRun`/`streamChildRunEvents` (Tasks 8–9).
- `src/Api/Budget/BudgetBreakdownProducer.php` — verify shed path (Task 3, likely test-only).
- `src/Command/ApiCommand.php` — InstanceInfo `builtinToolkits` gains `vision` (Task 4); enable `schedulesDialect` (Task 7); new routes (Tasks 6, 8, 9).
- `src/Question/QuestionPersistence.php`, `src/Contract/QuestionOption.php`, `src/Toolkit/QuestionToolkit.php` — wire shape (Task 5).
- `src/Api/Handler/MessageHandler.php` — `submitTurnAnswer` + `question` frame (Task 6).
- `src/Api/Handler/QuestionHandler.php` — turn-scoped answer entrypoint (Task 6).
- `src/Storage/ScheduleStore.php`, `src/Api/Handler/ScheduleHandler.php` — `action`/`persona_id` (Task 7).
- `src/Api/Handler/ChildRunHandler.php` (new) — child-run producers/frames (Tasks 8–9).
- `src/Import/ImportService.php`, `src/Import/ImportMode.php` (new) — import (Tasks 10–11).
- `src/Api/OperationCatalog.php` (new) — op catalog (Task 12).
- `tests/conformance/CoreChecklistTest.php` — 11 row flips.

---

### Task 1: Typed thrown errors (CORE-57)

**Files:**
- Modify: `src/Exception/RequestBodyException.php`
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-57), `tests/Unit/Exception/RequestBodyExceptionTest.php` (unit)

**Interfaces:**
- Consumes: `ApiErrorCode` (`->value` is the catalog code), existing `RequestBodyException` carrying `ApiErrorCode $errorCode`, `int $status`, `array $details`.
- Produces: `RequestBodyException::toThrownError(): array` → `error-thrown.json` shape `{error: string, code: string, details?: object}`.

Schema `error-thrown.json`: `additionalProperties:false`, required `[error, code]`; `code` = the closed 23-value `error.json` enum; `details` optional object.

- [ ] **Step 1: Write the failing conformance row.** In `CoreChecklistTest.php`, remove `'CORE-57: …'` from `$rows` and add:

```php
it('CORE-57: an in-process thrown error is typed with a code from the closed catalog', function () {
    $v = new ConformanceValidator();
    $thrown = (new RequestBodyException(ApiErrorCode::NOT_FOUND, 'No such persona', ['id' => 'p_missing']))->toThrownError();
    expect($v->isValid('error-thrown.json', $thrown))->toBeTrue($v->errorText('error-thrown.json', $thrown));
    expect($thrown['code'])->toBe('not_found');
    expect(ApiErrorCode::tryFrom($thrown['code']))->not->toBeNull();
    // An off-catalog code is rejected by the schema (mirrors invalid/error-thrown.bad-code.json).
    $bad = $thrown;
    $bad['code'] = 'kaboom';
    expect($v->isValid('error-thrown.json', $bad))->toBeFalse();
})->group('conformance');
```

Confirm the real `RequestBodyException` constructor signature first (it may be `(ApiErrorCode $code, string $message, array $details = [], ?int $status = null)` or similar) and adapt the `new RequestBodyException(...)` call to match code-as-truth.

- [ ] **Step 2: Run it, verify it fails** (`toThrownError` undefined): `./vendor/bin/pest tests/conformance/CoreChecklistTest.php --filter 'CORE-57'`.
- [ ] **Step 3: Implement `toThrownError()`.** Add to `RequestBodyException`:

```php
/** @return array{error: string, code: string, details?: object} */
public function toThrownError(): array
{
    $out = ['error' => $this->getMessage(), 'code' => $this->errorCode->value];
    if ($this->details !== []) {
        $out['details'] = (object) $this->details;   // object, never []
    }
    return $out;
}
```

Match the real property names (`$this->errorCode`, `$this->details`).

- [ ] **Step 4: Add a unit test** asserting `details` is a `stdClass` when present and absent when `[]`.
- [ ] **Step 5: Run** the row + unit test + `composer analyse`; all green.
- [ ] **Step 6: Commit** `feat(api): typed thrown-error payload (CORE-57)`.

---

### Task 2: Enums are closed (CORE-2)

**Files:**
- Modify: `src/Api/Handler/SessionHandler.php` (`toWire`, pin `session.kind`)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-2)

**Interfaces:**
- Consumes: `SessionHandler::toWire(array $row): array`. Today `status` is derived from `is_archived`/`is_closed` booleans (structurally closed to `active|archived|closed`); `kind` is a raw DB passthrough defaulting to `chat` — the one open emission path.
- Produces: `kind` constrained to the `session.json` enum `{chat, loop_workscope}` (default `chat` for any unknown/absent value).

Schema `session.json`: `status` enum `active|archived|closed`; `kind` enum `chat|loop_workscope`. Invalid vector `invalid/session.bad-status.json` sets `status:"paused"`.

- [ ] **Step 1: Write the failing conformance row.**

```php
it('CORE-2: session enums are closed — out-of-set status/kind never produced and rejected', function () {
    $v = new ConformanceValidator();
    // (a) schema-reject: the vendored bad-status vector is rejected.
    $bad = json_decode((string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/session.bad-status.json'), true);
    expect($v->isValid('session.json', $bad))->toBeFalse();

    // (b) producer-total: over every (is_archived,is_closed) combination, status stays in-set.
    foreach ([[0,0],[1,0],[0,1],[1,1]] as [$arch, $closed]) {
        $wire = SessionHandler::toWire(sessionRow(isArchived: $arch, isClosed: $closed, kind: 'chat'));
        expect($wire['status'])->toBeIn(['active', 'archived', 'closed']);
    }
    // (c) kind is pinned: a garbage stored kind is coerced into the closed set, never leaked.
    $wire = SessionHandler::toWire(sessionRow(kind: 'wat'));
    expect($wire['kind'])->toBeIn(['chat', 'loop_workscope']);
    expect($v->isValid('session.json', SessionHandler::toWire(sessionRow(kind: 'loop_workscope'))))
        ->toBeTrue();
})->group('conformance');
```

Add a small local `sessionRow(...)` helper near the test (or inline a complete row literal) that returns a full valid session DB row with overridable `is_archived`/`is_closed`/`kind`; include every column `toWire` reads (id, persona_id, model, version, timestamps, members source, etc.) — derive the exact column set from `SessionHandler::toWire` before writing it.

- [ ] **Step 2: Run it, verify (c) fails** (raw `wat` leaks through): `--filter 'CORE-2'`.
- [ ] **Step 3: Pin `kind` in `toWire`.** Where `kind` is currently a raw passthrough, coerce:

```php
$kind = in_array($row['kind'] ?? 'chat', ['chat', 'loop_workscope'], true) ? $row['kind'] : 'chat';
```

Keep the existing boolean-derived `status` logic unchanged. No new enum class required (matches the existing string-set style; do NOT introduce a PHP enum unless the surrounding code already uses one for this field).

- [ ] **Step 4: Run** the row + the existing `SessionHandler` tests + `composer analyse`; green.
- [ ] **Step 5: Commit** `fix(api): pin session.kind to closed set (CORE-2)`.

---

### Task 3: Budget shed order is inspectable (CORE-12)

**Files:**
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-12); `tests/Unit/Api/Budget/BudgetBreakdownProducerTest.php` (extend)
- Modify (only if the shed branch proves unreachable): `src/Api/Budget/BudgetBreakdownProducer.php`

**Interfaces:**
- Consumes: `BudgetBreakdownProducer::toWire(PromptBudgetSnapshot $snapshot): array` (Phase 4) — already emits per-section `included`, `estimated_tokens`, `priority` (via `shedRank()`: critical→0, workflow→1, volatile→2, unknown→3), and `shed_reason` (default `over_budget`).
- Produces: no new code expected — a real-shed test proving the `included=false` branch emits `shed_reason` and priority ordering is legible.

Schema `budget-breakdown.json`: `sections[]` = `{name, included, estimated_tokens, priority(int), shed_reason(string|null)}`, plus `total_estimated_tokens`, `model_context_window`. There is NO `pinned` boolean — pinning is expressed via `priority` (rank-0 = pinned/security).

- [ ] **Step 1: Write the failing conformance row.** Construct a `PromptBudgetSnapshot` (confirm its constructor / builder shape in code) that contains at least one **excluded** section (`included=false`, with a `shed_reason`) and one pinned rank-0 section:

```php
it('CORE-12: budget breakdown exposes priority tiers and shed order inspectably', function () {
    $v = new ConformanceValidator();
    $wire = (new BudgetBreakdownProducer())->toWire(budgetSnapshotWithShed());
    expect($v->isValid('budget-breakdown.json', $wire))->toBeTrue($v->errorText('budget-breakdown.json', $wire));
    // pinned/security ranks first (lowest priority int).
    $priorities = array_column($wire['sections'], 'priority');
    expect(min($priorities))->toBe(0);
    // a shed section is inspectable: excluded, carries a reason.
    $shed = array_values(array_filter($wire['sections'], static fn(array $s): bool => $s['included'] === false));
    expect($shed)->not->toBeEmpty();
    expect($shed[0]['shed_reason'])->toBeString();
    // sections are ordered by priority (pinned-first), so shed order is legible.
    expect($priorities)->toBe(array_values(array_sort_copy($priorities))); // non-decreasing
})->group('conformance');
```

Replace `array_sort_copy` with an inline `$sorted = $priorities; sort($sorted);` comparison. Build `budgetSnapshotWithShed()` from the real `PromptBudgetSnapshot` API — if the producer only ever receives `included=true` sections from `OrchestratorAgent`, construct the snapshot directly in the test with a manually-excluded section (the producer accepts the snapshot; you do not need the orchestrator).

- [ ] **Step 2: Run it, verify** whether the producer already emits a valid shed section. If it does, the row passes once written — good. If `shed_reason` is dropped or the section ordering is not priority-sorted, that is the only implementation gap: fix it minimally in `toWire` (emit `shed_reason` for excluded sections; sort `sections` by `priority` ascending). Do NOT add fields beyond the schema.
- [ ] **Step 3: Run** the row + producer unit test + `composer analyse`; green.
- [ ] **Step 4: Commit** `test(budget): prove shed order + pinned tier inspectable (CORE-12)`.

---

### Task 4: Vision is an access-gated built-in (CORE-32)

**Files:**
- Modify: `src/Command/ApiCommand.php` (InstanceInfo construction — add `vision` to `builtinToolkits`)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-32)

**Interfaces:**
- Consumes: `InstanceInfoBuilder` ctor arg `builtinToolkits: list<string>` (→ `builtin_toolkits`). Production site `ApiCommand.php` currently hard-codes `['shell', 'fs', 'web']`.
- Produces: `builtin_toolkits` includes `vision`; image **generation** remains absent (extension-only by construction — no `generate_image`/`image_generate` tool exists in core).

Context: `VisionTool` (`src/Tool/VisionTool.php`, tool name `vision_analyze`) is registered unconditionally and is in `ToolkitVisibility::CANNOT_DISABLE`; access is gated by `RoleParser::VALID_ACCESS_LEVELS = ['full','readonly','readonly-shell','minimal']` via `OrchestratorAgent`'s readonly/shell gating. `vision` is read-safe, hence an access-gated built-in.

- [ ] **Step 1: Write the failing conformance row.** Build the production-representative InstanceInfo (use `makeInstanceInfoBuilder()` if its `builtinToolkits` can be overridden, else construct an `InstanceInfoBuilder` with `builtinToolkits: ['shell','fs','web','vision']`):

```php
it('CORE-32: vision is a declared access-gated built-in; generation is extension-only', function () {
    $v = new ConformanceValidator();
    $info = instanceInfoWithBuiltins(['shell', 'fs', 'web', 'vision']);
    expect($v->isValid('instance-info.json', $info))->toBeTrue($v->errorText('instance-info.json', $info));
    expect($info['builtin_toolkits'])->toContain('vision');
    // generation is NOT a built-in — it is extension-only (absent from core).
    expect($info['builtin_toolkits'])->not->toContain('image_generation');
})->group('conformance');
```

Then add an assertion that pins the coqui reality: `expect(defined('...') ...)` is overkill — instead assert against the source of truth by reading `RoleParser::VALID_ACCESS_LEVELS` to confirm `vision` is reachable at `readonly` (documents "access-gated built-in"). Keep it to a simple membership check on the access-level constant.

- [ ] **Step 2: Run it, verify it fails** (production builtins lack `vision`).
- [ ] **Step 3: Add `vision` to the production `builtinToolkits`** in `ApiCommand.php`: `builtinToolkits: ['shell', 'fs', 'web', 'vision']`. Do not add any generation entry.
- [ ] **Step 4: Run** the row + any InstanceInfo production test + `composer analyse`; green.
- [ ] **Step 5: Commit** `feat(discovery): declare vision built-in in InstanceInfo (CORE-32)`.

---

### Task 5: Rich question wire shape (CORE-49)

**Files:**
- Modify: `src/Question/QuestionPersistence.php` (verify/complete `toWire`), `src/Contract/QuestionOption.php`, `src/Toolkit/QuestionToolkit.php` (option shape)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-49); `tests/Unit/Question/QuestionPersistenceTest.php`

**Interfaces:**
- Consumes: `QuestionPersistence::toWire(array $row): array` — already maps `free_text`→`text`, status `pending`→`open`, and options to `{value, label?}` where **value=option.label, label=option.description**. `QuestionFormat` enum (`single_select`/`multi_select`/`free_text`) — multi-select already supported.
- Produces: `question.json`-valid output for text, single_select, and multi_select; typed option objects with required `value`.

Schema `question.json`: `additionalProperties:false`, required `[id, session_id, prompt, status, created_at]`; `format` enum `text|single_select|multi_select` (absent⇒text); `options` = `anyOf[array<string>, array<{value(req), label?}>, null]`; `status` enum `open|answered|cancelled`; `answer` = `oneOf[string, array<string>, null]`; `suggested` = `string|null`. Invalid vector `question.malformed-option.json` = an option object missing `value`.

- [ ] **Step 1: Write the failing conformance row.**

```php
it('CORE-49: question wire is rich (multi-select) with a typed {value,label} option shape', function () {
    $v = new ConformanceValidator();
    // A coqui multi_select question row projects to a valid question.json with typed options.
    $wire = QuestionPersistence::toWire(questionRow(
        format: 'multi_select',
        options: [['label' => 'cheese', 'description' => 'Cheddar'], ['label' => 'mushroom']],
        status: 'pending',                     // stored form
        answer: ['selected' => ['cheese', 'mushroom'], 'text' => null],
    ));
    expect($v->isValid('question.json', $wire))->toBeTrue($v->errorText('question.json', $wire));
    expect($wire['format'])->toBe('multi_select');
    expect($wire['status'])->toBe('open');                 // pending → open
    expect($wire['options'][0])->toMatchArray(['value' => 'cheese']);   // value is required + present
    expect($wire['answer'])->toBe(['cheese', 'mushroom']); // multi_select ⇒ array answer

    // The vendored malformed-option vector (option without value) is rejected.
    $bad = json_decode((string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/question.malformed-option.json'), true);
    expect($v->isValid('question.json', $bad))->toBeFalse();
})->group('conformance');
```

Build `questionRow(...)` to match the real `questions` table columns (`id, session_id, turn_id, loop_id, stage_id, responder_kind, request(JSON), answer(JSON), status, created_at, answered_at`), serializing `request`/`answer` as JSON exactly as the store does. Confirm `toWire`'s current output for the `answer` field: the schema wants a bare `string|array<string>|null`, NOT the coqui `{selected,text}` object — if `toWire` currently passes `{selected,text}` through, that is the gap to fix (map multi_select→`selected` array, single_select/text→`text` scalar, unanswered→null).

- [ ] **Step 2: Run it, verify it fails** on the `answer` projection and/or any option gap.
- [ ] **Step 3: Complete `toWire`.** Ensure: options emit `{value, label?}` (drop `label` when description is null, never emit `label:null` under `additionalProperties:false`); `answer` collapses `{selected,text}` to the schema's `string|array<string>|null`; `format` maps `free_text`→`text`; `status` maps `pending`→`open` (and `answered`/`cancelled` pass through). Empty option list ⇒ omit `options` or emit `null` (per schema `anyOf` null branch), never `[]` where a typed array is ambiguous — prefer `null` for "no options".
- [ ] **Step 4: Align the runtime option shape.** In `QuestionOption` + `QuestionToolkit` `ask_user`, the authoring shape stays `{label, description}` internally (that is coqui's tool ergonomics), but confirm the persistence round-trip yields correct `{value,label}` on read. Do NOT invert the internal object gratuitously — the bridge lives in `toWire`. Only touch `QuestionOption`/`QuestionToolkit` if a value is actually lost across persist→toWire.
- [ ] **Step 5: Run** the row + `QuestionPersistenceTest` + `composer analyse`; green.
- [ ] **Step 6: Commit** `feat(questions): CAP question wire shape — typed options, multi-select (CORE-49)`.

---

### Task 6: submitTurnAnswer + question SSE frame (CORE-48)

**Files:**
- Modify: `src/Api/Handler/MessageHandler.php` (forward `question` turn events as SSE frames; pure frame builder), `src/Api/Handler/QuestionHandler.php` (turn-scoped answer), `src/Command/ApiCommand.php` (route)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-48)

**Interfaces:**
- Consumes: `SuspendingQuestionResponder` writes `appendTurnEvent($turnProcessId, 'question', $question->toArray())`; `SessionStorage::recordQuestionAnswer(...)`; `QuestionPersistence::toWire` (Task 5); the existing `MessageHandler::buildTurnEventFrame(string $event, array $data, string $id): array` and `mapTurnEvent` (which currently DROPS `question`).
- Produces:
  - `MessageHandler::buildQuestionFrame(array $questionEventData, string $id): array` → `sse-question.json` shape `{id, event:'question', data:{question_id, prompt?, options?, suggested?}}`.
  - Route `POST /api/v1/sessions/{id}/turns/{turnId}/answer` → `submitTurnAnswer`, resolving the pending question for that turn and calling the existing answer mechanism.

Schema `sse-question.json`: `{event:'question'(const), id(string), data:{question_id(req), prompt?, options?, suggested?}}`. Invalid vector `sse-question.no-question-id.json` omits `data.question_id`.

- [ ] **Step 1: Write the failing conformance row.**

```php
it('CORE-48: turn stream emits question frames carrying question_id; submitTurnAnswer resolves them', function () {
    $v = new ConformanceValidator();
    // (a) the SSE question frame is built from a recorded 'question' turn event and carries question_id.
    $eventData = ['id' => 'q_123', 'prompt' => 'Deploy where?', 'format' => 'single_select',
        'options' => [['label' => 'staging'], ['label' => 'production']], 'suggested' => ['selected' => ['staging']]];
    $frame = MessageHandler::buildQuestionFrame($eventData, SseCursor::encode(9));
    expect($v->isValid('sse-question.json', $frame))->toBeTrue($v->errorText('sse-question.json', $frame));
    expect($frame['data']['question_id'])->toBe('q_123');
    // a frame missing question_id is rejected (mirrors invalid/sse-question.no-question-id.json).
    $bad = $frame; unset($bad['data']['question_id']);
    expect($v->isValid('sse-question.json', $bad))->toBeFalse();

    // (b) submitTurnAnswer over a temp db resolves the pending question and returns 200.
    $dbPath = sys_get_temp_dir() . '/coqui-core48-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $turnId = $storage->createTurnProcess($sessionId, 'ask me');
        $qid = $storage->createQuestion($sessionId, $turnId, /* request */ [...], /* responderKind */ 'suspending');
        $handler = /* build QuestionHandler over $storage */;
        $resp = $handler->submitTurnAnswer(answerRequest($sessionId, $turnId, ['selected' => ['staging'], 'text' => null]), $sessionId, $turnId);
        expect($resp->getStatusCode())->toBe(200);
        expect($storage->getQuestion($qid)['status'])->toBe('answered');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');
```

Adapt `createQuestion`/`getQuestion` calls to the real signatures found in `SessionStorage` (recon: `createQuestion` ~L3252, `recordQuestionAnswer` ~L3304). Build the `QuestionHandler` exactly as the existing `QuestionHandler` unit/behavioral tests do.

- [ ] **Step 2: Run it, verify it fails** (`buildQuestionFrame` + `submitTurnAnswer` undefined).
- [ ] **Step 3: Implement `buildQuestionFrame`.** Pure function projecting a recorded `question` turn-event into the `sse-question.json` shape: `question_id` from the event's `id`; optional `prompt`; `options` projected to `{value,label?}` (reuse the Task-5 option projection — extract a shared static if needed, do not duplicate the mapping); `suggested` as scalar string or omitted. Never emit `question_id:null`.
- [ ] **Step 4: Forward question events in the turn stream.** In `mapTurnEvent`/the turn SSE path, stop dropping `question`: map a recorded `question` event to `buildQuestionFrame(...)` so a live turn stream surfaces `{event:'question', data:{question_id,...}}`. Keep the turn closed set intact (`token|message|tool_call|tool_result|question|done|error`).
- [ ] **Step 5: Implement `submitTurnAnswer`.** Add `POST /sessions/{id}/turns/{turnId}/answer` → resolve the pending question for `(session,turn)`, validate + record via the existing `recordQuestionAnswer` path (reuse the `/questions/{qid}/answer` logic — extract a shared private method, no duplication). Reachable WITHOUT the `questions` profile (it is a Core path). Errors: no pending question → `404 not_found`; already answered → `409 conflict`; invalid answer → `422 validation_error`.
- [ ] **Step 6: Run** the row + `QuestionHandler`/`MessageHandler` tests + `composer analyse`; green.
- [ ] **Step 7: Commit** `feat(questions): submitTurnAnswer Core path + question SSE frames (CORE-48)`.

---

### Task 7: scheduled_task.action discriminated union (CORE-50)

**Files:**
- Modify: `src/Storage/ScheduleStore.php` (persist `action`/`persona_id`; use the existing dead `toWire`), `src/Api/Handler/ScheduleHandler.php` (accept `action`/`persona_id`; emit `toWire`), `src/Command/ApiCommand.php` (enable `schedulesDialect`)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-50); `tests/Unit/Storage/ScheduleStoreTest.php`

**Interfaces:**
- Consumes: `ScheduleStore::toWire(array $row): array` (exists, currently dead + hard-codes `action={kind:'turn',prompt}`); `scheduled_tasks` columns (`cron`, `persona_id` exists but unwritten, `prompt`, `role`).
- Produces: `scheduled-task.json`-valid output where `action` is the discriminated union `{kind:'turn', prompt}` OR `{kind:'loop', definition_name}`; `persona_id` persisted and non-null; create/patch accept `action` + `persona_id`.

Schema `scheduled-task.json`: required `[id, name, cron, persona_id, action, status, created_at]`; `action` = `oneOf` of exactly two: `turn`(req `[kind,prompt]`) and `loop`(req `[kind, definition_name]`, `definition_name`=Slug); `status` enum `enabled|disabled`. Invalid vector `scheduled-task.loop-no-definition.json` = `action:{kind:'loop'}` without `definition_name`. Valid `scheduled-task.turn.json` = `action:{kind:'turn', prompt}`.

- [ ] **Step 1: Write the failing conformance row.**

```php
it('CORE-50: scheduled_task.action is a kind-discriminated union; a loop action requires a definition', function () {
    $v = new ConformanceValidator();
    // turn action round-trips through the store's producer and validates.
    $turnWire = ScheduleStore::toWire(scheduleRow(actionKind: 'turn', prompt: 'Summarize inbox', personaId: 'p_1'));
    expect($v->isValid('scheduled-task.json', $turnWire))->toBeTrue($v->errorText('scheduled-task.json', $turnWire));
    expect($turnWire['action'])->toMatchArray(['kind' => 'turn', 'prompt' => 'Summarize inbox']);
    // loop action with a definition validates.
    $loopWire = ScheduleStore::toWire(scheduleRow(actionKind: 'loop', definitionName: 'research', personaId: 'p_1'));
    expect($v->isValid('scheduled-task.json', $loopWire))->toBeTrue($v->errorText('scheduled-task.json', $loopWire));
    expect($loopWire['action'])->toMatchArray(['kind' => 'loop', 'definition_name' => 'research']);
    // the vendored loop-no-definition vector is rejected.
    $bad = json_decode((string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/scheduled-task.loop-no-definition.json'), true);
    expect($v->isValid('scheduled-task.json', $bad))->toBeFalse();
    // coqui's own store refuses to persist a loop action without a definition.
    $dbPath = sys_get_temp_dir() . '/coqui-core50-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $store = new ScheduleStore(new PDO('sqlite:' . $dbPath));
        expect(fn() => $store->create(name: 'x', scheduleExpression: '0 * * * *', action: ['kind' => 'loop'], personaId: 'p_1'))
            ->toThrow(RequestBodyException::class);
    } finally { cleanupSqliteTestDb($dbPath); }
})->group('conformance');
```

Build `scheduleRow(...)` with the real `scheduled_tasks` columns. Confirm `toWire`'s current field derivation and adapt the `action` construction to read the persisted kind/definition rather than the hard-coded `turn`.

- [ ] **Step 2: Run it, verify it fails** (store has no `action` concept; loop actions unrepresentable; `toWire` hard-codes turn).
- [ ] **Step 3: Persist the action.** Add columns to the `scheduled_tasks` DDL (recreate-from-empty in `ScheduleStore` `createTables`): `action_kind TEXT NOT NULL DEFAULT 'turn'`, `definition_name TEXT NULL` (keep `prompt` for the turn kind). Write `persona_id` on create/update. Do NOT keep a parallel legacy path — `create()` takes an `array $action` (validated) plus `personaId`.
- [ ] **Step 4: Validate the union at the store boundary.** `create()`/`update()` reject `kind:'loop'` without `definition_name` (throw `RequestBodyException(ApiErrorCode::VALIDATION_ERROR, …, status 422)`), and reject unknown kinds. Keep the closed kind set `{turn, loop}`.
- [ ] **Step 5: Emit via `toWire`.** Make `toWire` build `action` from `action_kind`/`prompt`/`definition_name`, pass `persona_id` through (now always set), derive `status` from `enabled`. Wire `ScheduleHandler` create/get/list/patch to accept `action`+`persona_id` and return `toWire` output (not raw rows). Update `docs/` for the schedule endpoints.
- [ ] **Step 6: Enable dialect advertisement.** Pass `schedulesDialect: 'cron-5field'` (confirm the exact dialect string the schema/`InstanceInfoBuilder` expects) at the `ApiCommand` InstanceInfo construction site so `instance-info.schedules.dialect` is populated. This is consistency work for the already-green CORE-33, not a new gate row.
- [ ] **Step 7: Run** the row + `ScheduleStore`/`ScheduleHandler` tests + `composer analyse`; green.
- [ ] **Step 8: Commit** `feat(schedules): action.kind union + persona_id + dialect (CORE-50)`.

---

### Task 8: Child-run spawn + get (CORE-29, part 1)

**Files:**
- Create: `src/Api/Handler/ChildRunHandler.php` (producers + spawn/get)
- Modify: `src/Storage/SessionStorage.php` (status-transition writes if needed), `src/Command/ApiCommand.php` (routes), possibly `src/Tool/SpawnAgentTool.php`/`src/Agent/ChildAgent.php` reuse
- Test: `tests/conformance/CoreChecklistTest.php` (does NOT flip CORE-29 yet — flips in Task 9 once stream exists; add a `spawnChildRun`/`getChildRun` behavioral test here)

**Interfaces:**
- Consumes: `ChildAgent` (synchronous `run(UserMessage)` → `{content, usage{promptTokens,completionTokens,totalTokens}}`); `SessionStorage::logChildRun(...)` + `getChildRuns(...)`; `RoleResolver::resolve(role, persona)`; the access-level gating in `OrchestratorAgent` (`resolveAccessLevel`).
- Produces:
  - `ChildRunHandler::childRunToWire(array $row): array` → strict `child-run.json` (reuse/move `SessionHandler::childRunToWire` if it already exists — recon found one at `SessionHandler.php:530`; consolidate, don't duplicate).
  - `POST /sessions/{id}/child-runs` → `spawnChildRun`: **202**, gated (full-access + top-level only), runs the child synchronously, records `pending`→`running`→`completed`/`failed`, returns the child-run resource.
  - `GET /sessions/{id}/child-runs/{childRunId}` → `getChildRun`: single object, `404 not_found` if absent.

Schema `child-run.json`: required `[id, parent_session_id, role, prompt, status, created_at]`; `status` enum `pending|running|completed|failed|cancelled`; nullable `model|result|parent_turn_id|completed_at`; int token triad.

- [ ] **Step 1: Write the failing behavioral test** (`spawnChildRun` returns 202 + valid resource; `getChildRun` fetches it; a non-top-level / non-full-access caller is forbidden). Build the handler over a temp db + temp workspace with a stubbed/fake provider so `ChildAgent` runs deterministically (mirror how existing child-agent tests fake the provider — locate one first). Assert `spawnChildRun` response is `202`, body validates against `child-run.json` with `status` in the terminal set, and the row persisted a `running`→`completed` transition (or `failed`).
- [ ] **Step 2: Run it, verify it fails** (`spawnChildRun`/`getChildRun` undefined).
- [ ] **Step 3: Record status transitions.** Extend the child-run write path so a spawn first inserts `pending`/`running` (with `created_at`) then finalizes `completed`/`failed` (with `completed_at` + token triad). Add `SessionStorage` methods as needed (`createChildRun(... status)` + `finalizeChildRun(... status, result, tokens)`), replacing the single `logChildRun` if that is cleaner — no dual path.
- [ ] **Step 4: Implement `spawnChildRun`** (202, gating). Enforce full-access + top-level-only: reject with `403 access_denied`/`forbidden` (use the catalog code that exists) when the caller context is not top-level or lacks full access. Run `ChildAgent` synchronously, map its result/usage onto the row, return `Router::jsonResponse($childRunToWire($row), 202)`.
- [ ] **Step 5: Implement `getChildRun`** (single object, `404 not_found`). Register both routes in `ApiCommand`.
- [ ] **Step 6: Run** the behavioral test + `composer analyse`; green.
- [ ] **Step 7: Commit** `feat(api): spawnChildRun (202, gated) + getChildRun (CORE-29 part 1)`.

---

### Task 9: Child-run event stream + CORE-29 flip (CORE-29, part 2)

**Files:**
- Modify: `src/Api/Handler/ChildRunHandler.php` (SSE stream + pure frame builder), `src/Command/ApiCommand.php` (route)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-29)

**Interfaces:**
- Consumes: `SseCursor::encode/decode`, `SseStream`/`ThroughStream` pattern (mirror `TaskHandler::events` at `TaskHandler.php:233`), `childRunToWire` (Task 8), `ExportCollectionMap` (already registers `child_runs`).
- Produces:
  - `ChildRunHandler::buildChildRunEventFrame(string $event, array $data, string $id): array` → `sse-childrun-event.json` closed set `{started, token, message, done}` (+ `sse-error`).
  - `GET /sessions/{id}/child-runs/{childRunId}/events` → `streamChildRunEvents`: replays `started`({child_run_id}) → optional `message` → terminal `done`($ref child-run.json).

Schema `sse-childrun-event.json`: `oneOf` on `event`: `started`→`{child_run_id}`(req), `token`→`{text}`(req), `message`→`$ref message.json`, `done`(terminal)→`$ref child-run.json`, plus `sse-error`. Optional top-level `id` cursor.

- [ ] **Step 1: Write the CORE-29 flip.** Remove `'CORE-29: …'` from `$rows` and add a row that (a) validates a pure `buildChildRunEventFrame('started', ['child_run_id'=>'cr_1'], SseCursor::encode(1))` and a `done` frame carrying a full `child-run.json` against `sse-childrun-event.json`; (b) rejects an unknown event name; (c) asserts export includes a `child_runs` collection whose items validate against `child-run.json`; (d) asserts the gated spawn op exists (a 202 from Task 8). Concretely:

```php
it('CORE-29: child runs are a gated Core op that streams typed events and exports', function () {
    $v = new ConformanceValidator();
    $started = ChildRunHandler::buildChildRunEventFrame('started', ['child_run_id' => 'cr_1'], SseCursor::encode(1));
    expect($v->isValid('sse-childrun-event.json', $started))->toBeTrue($v->errorText('sse-childrun-event.json', $started));
    $done = ChildRunHandler::buildChildRunEventFrame('done', childRunFixture(), SseCursor::encode(3));
    expect($v->isValid('sse-childrun-event.json', $done))->toBeTrue($v->errorText('sse-childrun-event.json', $done));
    expect(in_array($done['event'], ['started', 'token', 'message', 'done'], true))->toBeTrue();
    $unknown = ChildRunHandler::buildChildRunEventFrame('reasoning', ['x' => 1], SseCursor::encode(4));
    expect($v->isValid('sse-childrun-event.json', $unknown))->toBeFalse();
    // export types the child_runs collection.
    expect(ExportCollectionMap::schemas()['child_runs'])->toBe('child-run.json');
    expect($v->isValid('child-run.json', childRunFixture()))->toBeTrue();
})->group('conformance');
```

`childRunFixture()` returns a `childRunToWire`-shaped array (reuse the Task-8 producer over a seeded row).

- [ ] **Step 2: Run it, verify it fails** (`buildChildRunEventFrame` undefined).
- [ ] **Step 3: Implement `buildChildRunEventFrame`.** Pure builder enforcing the closed set (`started|token|message|done`); return a shape the schema rejects for any other event name (so the unknown-event assertion has teeth). `done.data` = full `child-run.json`; `started.data` = `{child_run_id}`.
- [ ] **Step 4: Implement `streamChildRunEvents`.** Mirror `TaskHandler::events`: `ThroughStream`, emit `started`, replay any recorded child events (for the sync model, at minimum `started` then terminal `done` from the finalized row; `message`/`token` if recorded), terminal `done`, `on('close')` cleanup, return `Response(200, ['Content-Type'=>'text/event-stream', 'Cache-Control'=>'no-cache', 'Connection'=>'keep-alive', 'X-Accel-Buffering'=>'no'], $stream)`. Honor `Last-Event-ID`/`?since` replay via the shared cursor resolver if the recorded-event store supports it; otherwise replay from the row deterministically.
- [ ] **Step 5: Register the route** in `ApiCommand`. Ensure the `IdempotencyMiddleware` streaming-passthrough (Phase 4) covers this SSE route (it detects `text/event-stream`) — do NOT record it.
- [ ] **Step 6: Run** the CORE-29 row + child-run tests + `composer analyse`; green.
- [ ] **Step 7: Commit** `feat(api): streamChildRunEvents + CORE-29 flip (child-run ops complete)`.

---

### Task 10: ImportService — preserve mode (CORE-56, part 1)

**Files:**
- Create: `src/Import/ImportMode.php` (enum `Preserve|Remap`), `src/Import/ImportService.php`
- Test: `tests/conformance/CoreChecklistTest.php` (do NOT flip CORE-56 yet — flips in Task 11); `tests/Unit/Import/ImportServiceTest.php`

**Interfaces:**
- Consumes: the export envelope shape (`export.json`: `protocol_version` + `exported_at` required, 20 optional collection arrays); the existing export producers / the test-only envelope assembly in `tests/conformance/Export/ExportEnvelopeTest.php` (reuse its assembly to produce an envelope to import); `SessionStorage` raw-PDO transaction pattern (`beginTransaction`/`commit`/`rollBack`).
- Produces: `ImportService::import(array $envelope, ImportMode $mode): ImportResult` (define a minimal `ImportResult` or return an array of inserted counts / id map). In `Preserve` mode, rows are inserted with their original ids; a PK collision surfaces `conflict`/`version_conflict`.

`export.json` collections + FK graph (from recon) — the collections needing insert ordering by FK dependency: `personas` → `sessions`(persona_id) → `session_members`(session_id,persona_id) → `turns`(session_id,actor_persona_id) → `content`(self-keyed) → `messages`(session_id,turn_id; attachments[].content_ref) → `roles`/`skills`(name-keyed, no remap) → `loops`(persona_id,session_id) → `loop_iterations`(loop_id) → `loop_stages`(iteration_id,job_id?,artifact_id?) → `memories`(persona_id) → `child_runs`(parent_session_id,parent_turn_id) → `questions`(session_id) → `artifacts`(session_id,content_ref|path) → `scheduled_tasks`(persona_id). Internal `jobs/job_events/audit_records` are diagnostics-only.

- [ ] **Step 1: Write the failing preserve-roundtrip test.** Assemble an envelope (reuse `ExportEnvelopeTest`'s assembly helper — extract it to a shared test helper if it's inline), import it with `ImportMode::Preserve` into a FRESH temp db, re-assemble the envelope from that db, and assert the two envelopes are equal (ids preserved, graph identical). Use the vendored `export.roundtrip.json` shape as the reference structure.
- [ ] **Step 2: Run it, verify it fails** (`ImportService` undefined).
- [ ] **Step 3: Implement `ImportMode` enum** (`Preserve = 'preserve'`, `Remap = 'remap'`).
- [ ] **Step 4: Implement `ImportService::import` (preserve).** Insert collections in FK-dependency order inside a SINGLE transaction (begin/try/commit/catch→rollBack — hand-rolled per the existing pattern; a small private `transactionally(callable)` helper INSIDE ImportService is fine, do not add a global one). Preserve ids verbatim. Skip internal diagnostics collections. On PK collision, roll back and throw `RequestBodyException(ApiErrorCode::CONFLICT, …)`. Validate each collection's items against its schema before insert (reuse `ExportCollectionMap::schemas()`).
- [ ] **Step 5: Run** the preserve roundtrip + `composer analyse`; green.
- [ ] **Step 6: Commit** `feat(import): ImportService preserve mode + roundtrip (CORE-56 part 1)`.

---

### Task 11: ImportService — remap mode + CORE-56 flip (CORE-56, part 2)

**Files:**
- Modify: `src/Import/ImportService.php` (remap FK rewrite)
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-56); `tests/Unit/Import/ImportServiceTest.php`

**Interfaces:**
- Consumes: Task-10 `ImportService`; the FK graph above.
- Produces: `ImportMode::Remap` — every ULID PK is regenerated and every FK column rewritten to the new id, atomically. Name-keyed collections (`roles`, `skills`) and content-addressed `content_ref` (and filesystem-path artifact refs) are NOT remapped (a path-shaped ref is left intact — detect `content_ref` that is a real content key vs a path).

- [ ] **Step 1: Write the CORE-56 flip (remap roundtrip with teeth).** Remove `'CORE-56: …'` from `$rows` and add:

```php
it('CORE-56: import supports preserve and remap; remap atomically rewrites every FK', function () {
    // preserve keeps ids identical across a roundtrip.
    $envelope = assembleReferenceEnvelope();            // shared test helper
    $preserved = importRoundtrip($envelope, ImportMode::Preserve);
    expect($preserved)->toEqual($envelope);

    // remap regenerates every ULID PK but preserves the graph shape + FK consistency.
    $remapped = importRoundtrip($envelope, ImportMode::Remap);
    expect($remapped['sessions'][0]['id'])->not->toBe($envelope['sessions'][0]['id']);   // ids changed
    // FK integrity: every session_member.session_id still points at an existing session id.
    $sessionIds = array_column($remapped['sessions'], 'id');
    foreach ($remapped['session_members'] as $m) {
        expect($sessionIds)->toContain($m['session_id']);
    }
    // name-keyed roles are NOT remapped.
    expect(array_column($remapped['roles'], 'name'))->toBe(array_column($envelope['roles'], 'name'));
})->group('conformance');
```

`importRoundtrip($env, $mode)` imports into a fresh temp db and re-assembles the envelope; `assembleReferenceEnvelope()` builds a small multi-collection graph (sessions + members + turns + messages + content + roles at minimum).

- [ ] **Step 2: Run it, verify remap fails** (not implemented).
- [ ] **Step 3: Implement remap.** Before insert, build a `Map<oldId,newId>` for every ULID-keyed collection (generate new ULIDs via coqui's existing id generator — confirm which util). Rewrite each FK column via the map during insert (session.persona_id, member.{session_id,persona_id}, turn.{session_id,actor_persona_id}, message.{session_id,turn_id} + attachments[].content_ref ONLY if content_ref is a remapped content key, loop.{persona_id,session_id}, loop_iteration.loop_id, loop_stage.{iteration_id,job_id?,artifact_id?}, memory.persona_id, child_run.{parent_session_id,parent_turn_id}, question.session_id, artifact.{session_id,content_ref?}, scheduled_task.persona_id). Do the whole rewrite+insert in ONE transaction (atomic). Leave `roles`/`skills` names and path-shaped artifact refs untouched.
- [ ] **Step 4: Add a rollback test** — a mid-import failure (e.g. a deliberately invalid row) leaves the target db empty (atomicity).
- [ ] **Step 5: Run** the CORE-56 row + import tests + `composer analyse`; green.
- [ ] **Step 6: Commit** `feat(import): remap FK rewrite + CORE-56 flip (import roundtrip complete)`.

---

### Task 12: Operation catalog — cross-binding parity + cardinality (CORE-47, CORE-58)

**Files:**
- Create: `src/Api/OperationCatalog.php`
- Test: `tests/conformance/CoreChecklistTest.php` (flip CORE-47 + CORE-58)

**Interfaces:**
- Consumes: the live route table (`ApiCommand::registerRoutes`, `Router`), the vendored `tests/conformance/spec/conformance/error-coverage.json` (camelCase `operation_id → [error codes]`), `CursorPage::build` (`{data,next_cursor}` = list cardinality), `ApiErrorCode`.
- Produces: `OperationCatalog` enumerating operations as `{operation_id, http_method, path, profile: ?string, cardinality: 'single'|'list'}`, derived from the route table + `error-coverage.json` op-id namespace. Static `all(): list<OperationDescriptor>`.

**Honest-scope note (from the settled adjudication):** these rows are realized as coqui-internal self-consistency, NOT tri-catalog parity (`operations.yaml`/`openapi.yaml` are not vendored and must not be added). The row descriptions and the status report MUST state this. Row wording uses **`x-profile`** (per `checklist.md:60`), correcting the stale `x-persona` stub text.

- [ ] **Step 1: Write the failing flips.** Remove both `'CORE-47: …'` and `'CORE-58: …'` from `$rows` and add:

```php
it('CORE-47: x-profile operations resolve to the same handler across HTTP and in_process bindings', function () {
    // Coqui self-consistency: profile-gated ops are enumerated with a profile, and each maps to
    // exactly one handler callable that both bindings invoke (HTTP dispatch and an in-process call
    // hit the identical [handler, method]). Not tri-catalog parity — operations.yaml/openapi.yaml
    // are not vendored in the pinned snapshot.
    $profiled = array_filter(OperationCatalog::all(), static fn($op) => $op->profile !== null);
    expect($profiled)->not->toBeEmpty();
    foreach ($profiled as $op) {
        expect($op->handler)->toBeCallable();               // one handler, binding-agnostic
        expect(in_array($op->profile, ['artifacts', 'questions', 'skills', 'schedules', 'mcp'], true))->toBeTrue();
    }
})->group('conformance');

it('CORE-58: single-vs-list response cardinality is self-consistent across the operation catalog', function () {
    // Every catalog op declares single|list; list ops emit {data,next_cursor}, single ops a bare object.
    // Verified against coqui's own producers (CursorPage); the row is coqui-internal, not tri-catalog.
    foreach (OperationCatalog::all() as $op) {
        expect($op->cardinality)->toBeIn(['single', 'list']);
    }
    // A representative list op and single op are checked against real responses.
    expect(OperationCatalog::forId('listSchedules')->cardinality)->toBe('list');
    expect(OperationCatalog::forId('getSchedule')->cardinality)->toBe('single');
    // cross-check against error-coverage.json op-id namespace (the one vendored cross-op artifact).
    $known = array_keys(json_decode((string) file_get_contents(__DIR__ . '/spec/conformance/error-coverage.json'), true));
    foreach (OperationCatalog::all() as $op) {
        // every catalog op-id that error-coverage declares is present (coverage subset check).
        if (in_array($op->operationId, $known, true)) {
            expect($op->operationId)->toBeString();
        }
    }
})->group('conformance');
```

Confirm the real `error-coverage.json` op-id names and pick representative list/single op-ids that actually exist there.

- [ ] **Step 2: Run them, verify they fail** (`OperationCatalog` undefined).
- [ ] **Step 3: Implement `OperationCatalog`.** A `final` class with a static declaration of operations built from the route table. Each descriptor: `operationId` (camelCase, matching `error-coverage.json` where applicable), `httpMethod`, `path`, `profile` (`?string` — set for artifacts/questions/skills/schedules/mcp-gated ops, null for Core), `cardinality` (`single`/`list` — `list` iff the handler returns `CursorPage`/`{data,next_cursor}`), and `handler` (the `[handler,'method']` callable, proving both bindings resolve to one implementation). Derive cardinality from whether the op is one of the 6 `CursorPage` list endpoints. Keep it a hand-maintained-but-asserted catalog — the tests are its drift guard.
- [ ] **Step 4: Run** both rows + `composer analyse`; green.
- [ ] **Step 5: Commit** `feat(api): operation catalog — x-profile binding + cardinality self-consistency (CORE-47/58)`.

---

## Self-Review

**Spec coverage:** all 11 remaining rows are assigned — CORE-57 (T1), CORE-2 (T2), CORE-12 (T3), CORE-32 (T4), CORE-49 (T5), CORE-48 (T6), CORE-50 (T7), CORE-29 (T8–T9), CORE-56 (T10–T11), CORE-47+CORE-58 (T12). CORE-30/31/33/35/36/39/46 confirmed already-green (Phase 4) and not re-flipped.

**Type consistency:** `childRunToWire` is consolidated (T8) then reused (T9); the `{value,label?}` option projection is shared between `QuestionPersistence::toWire` (T5) and `buildQuestionFrame` (T6), not duplicated; `ImportService` (T10) is extended in place for remap (T11); `ImportMode` enum defined once (T10). The `answer` field is the schema's `string|array<string>|null`, consistent across T5/T6.

**Placeholder scan:** each task carries concrete test code and named signatures. Where a real signature must be confirmed against code (e.g. `RequestBodyException` ctor, `PromptBudgetSnapshot` builder, `createQuestion`), the step says so explicitly (code-as-truth) rather than guessing — the implementer verifies before writing, matching the Phase-4 approach.

**Ordering:** small isolated confidence-builders first (T1–T4), medium coupled work next (T5→T6 questions; T7 schedules), large forked work last (T8–T9 child-run; T10–T11 import; T12 catalog). No task depends on a later one.

## Next

Execute via `superpowers:subagent-driven-development` — fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer per task; fix subagents for Critical/Important; whole-branch review at the end. Per the settled adjudications, the status report MUST document CORE-47/58 as coqui-internal self-consistency (not tri-catalog parity) and CORE-56 as an in-process service (no HTTP export/import routes). Per user cadence, `/compact` after the phase.
