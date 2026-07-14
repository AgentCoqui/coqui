# Structured Questions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give an agent a first-class way to ask the user a *structured* question mid-turn (single-select / multi-select / free-text), rendered by surface-specific responders (REPL prompt, API suspend-and-ask, loop/background policy), with one validated `QuestionRequest`/`QuestionResponse` representation and full audit logging.

**Architecture:** One shared representation in `src/Contract/` (`QuestionFormat`, `QuestionOption`, `QuestionRequest`, `QuestionResponse` with `isValidFor()` as the single validation authority, `QuestionResponderInterface`). A context-agnostic `ask_user` tool in a new `QuestionToolkit` delegates to a wired `QuestionResponderInterface`, exactly as the agent already threads `ToolExecutionPolicyInterface`. Three responders mirror the execution-policy split: `InteractiveQuestionResponder` (REPL, `SymfonyStyle`, synchronous), `SuspendingQuestionResponder` (API child-turn process — persist the question, emit a `question` turn-event over SSE, then block-poll the DB for the answer and return it inline), and `PolicyQuestionResponder` (loops/background — `default` returns the suggestion inline; `block` reuses feature-#3's `blocked`/escalation/retry machinery: flip the loop to `blocked`, and on the operator's REST answer, reopen the iteration with the answer injected into the reopened stage prompt).

**Tech Stack:** PHP 8.4 (strict types, `final`, readonly value objects, constructor injection), Pest tests, PHPStan (level per repo config), SQLite via `SessionStorage`, ReactPHP HTTP for the API, Symfony Console for the REPL.

## Global Constraints

- **PHP 8.4**, `declare(strict_types=1);` in every file, `final` by default, one class per file, 4-space indentation, constructor injection, early returns, explicit exceptions over `null` error signals. (`AGENTS.md` → Coding Standards)
- **Composer is the only package manager. Add no new dependencies** — use PHP built-ins, SPL, and existing project utilities only. (`AGENTS.md` → Dependency Management)
- **Validation has a single authority:** `QuestionResponse::isValidFor(QuestionRequest): bool`. No other code re-implements answer validation. (spec → Validation)
- **One question per `ask_user` call** in v1. No batching, no role-level `on_question` override, no answer formats beyond single-select / multi-select / free-text (+`allowOther` on selects). No dedicated confirm type (yes/no is a two-option single-select). (spec → Non-goals; brief §6)
- **Persist Q&A to `audit_log` and the new questions tables only** — never as memory or artifacts. (spec → Non-goals)
- **Do not change the tool-approval mechanism.** Questions are a sibling of approvals on the shared substrate. (spec → Non-goals)
- **The `QuestionHandler` REST routes are CORE authenticated routes.** Register them with `$router->get/post` — NEVER `addPublicRoute`. (brief §6)
- **Never commit `config/documentation.json`** (generated + git-ignored). (brief §6)
- **Validation commands (must both be green at the end):** `composer test` (Pest) and `composer analyse` (PHPStan, `--memory-limit=512M`).

---

## Resolved Design Decision (the brief's §4 open question)

**Finding — loop stages do NOT run on a suspendable fiber; they run as discrete background-task *processes*.** `LoopManager::advanceLoop()` (`src/Api/LoopManager.php:190-204`) creates a task via `SessionStorage::createTask()`; that task is executed in a **separate OS process** by `bin/coqui task:run` (`src/Command/TaskRunCommand.php`) with `AutoApprovalPolicy` + a `DatabasePendingInputProvider`; `LoopManager::reconcile()` (`src/Api/LoopManager.php:300-379`) polls the task's status and calls `LoopExecutor::completeStage()`/`failStage()`. There is no `Fiber::suspend()` in the loop path — nor, in practice, in the live API-turn path (interactive API turns also run out-of-process via `bin/coqui turn:run`, `src/Command/TurnRunCommand.php`; `AgentFiberExecutor`'s fiber runs synchronously and its `scheduleFiberResumption` is an explicit v2 placeholder). All three surfaces are **blocking synchronous agent loops in dedicated processes**; mid-run input today is DB-polled (`DatabasePendingInputProvider` over `task_inputs`), not fiber-suspended.

**Decision — block-mode loop questions use the discrete-task reopen mechanism (the spec's second §4 branch), reusing feature #3.** When a loop-stage agent calls `ask_user` under `on_question: block`, `PolicyQuestionResponder`:
1. persists the `QuestionRequest` (pending),
2. writes it into the loop's `metadata.escalation` payload and flips the loop to `blocked` + the iteration to `needs_rework` (mirroring `LoopExecutor::escalateBlocked()`),
3. returns a terminal **sentinel `ToolResult`** to the agent telling it the question was escalated to the operator and the stage is now blocked (no fabricated answer). The stage's remaining output is discarded when the iteration reopens, so a control exception is unnecessary and we never fight `AgentRunner`'s catch-all.

The operator answers via `POST /api/v1/sessions/{id}/questions/{questionId}/answer`; the answer is validated by `QuestionResponse::isValidFor` (`422` on mismatch), persisted, and the reopen is triggered exactly like a #3 retry (`resetStagesForIteration` + `resetIterationForRetry` + loop→`running`), with the answer written into loop metadata and injected into the reopened stage prompt as an `## Answer to Your Earlier Question` section — the analogue of the existing `pending_guidance` injection in `LoopExecutor::prepareNextStage()` (`src/Agent/LoopExecutor.php:242-269, 932-934`).

**Rejected alternative (stated for the reviewer):** an inline block-poll inside the stage task process (like the API `SuspendingQuestionResponder`) would return the answer to `ask_user` inline, but it holds a task process open indefinitely, loses its in-memory wait on any API-server restart, and contradicts the spec's loop-integration section ("answered via the REST answer endpoint, which resumes the stage exactly like a retry"). The reopen path is DB-durable, restart-safe, and reuses #3 end-to-end. Both yield identical user-visible behavior: loop `blocked` → operator answers → stage proceeds with the answer.

**API turns (`SuspendingQuestionResponder`) DO block-poll inline** — a live human is present (the REST client), the turn is its own `turn:run` process where blocking is free, and the SSE stream (polled from `turn_events` by `MessageHandler`) keeps the client connected while the child waits.

---

## File Structure

**New files (`src/Contract/`):**
- `QuestionFormat.php` — enum `SingleSelect | MultiSelect | FreeText`.
- `QuestionOption.php` — readonly `{ label, description? }`.
- `QuestionResponse.php` — readonly `{ selected: list<string>, text: ?string }` + `isValidFor()` + `toArray/fromArray`.
- `QuestionRequest.php` — readonly request DTO + `toArray/fromArray`.
- `QuestionResponderInterface.php` — one method `ask(QuestionRequest): QuestionResponse`.
- `QuestionRecord.php` — readonly row DTO returned by storage reads (persisted question + its answer + status).

**New files (`src/Question/`):**
- `InteractiveQuestionResponder.php` — REPL (`SymfonyStyle`).
- `SuspendingQuestionResponder.php` — API child-turn (persist + turn-event + block-poll).
- `PolicyQuestionResponder.php` — loop/background (`block` | `default`).
- `QuestionPersistence.php` — thin collaborator wrapping the storage calls + audit logging shared by all responders and the REST handler (keeps `SessionStorage` lean and gives one place for audit parity).

**New files (`src/Toolkit/`):**
- `QuestionToolkit.php` — the `ask_user` tool.

**New files (`src/Api/Handler/`):**
- `QuestionHandler.php` — `GET .../questions`, `POST .../questions/{questionId}/answer`.

**Modified files:**
- `src/Storage/SessionStorage.php` — new `questions` table (schema block + `$expected` list at `:1433`) and CRUD methods.
- `src/Agent/OrchestratorDependencies.php` — add `?QuestionResponderInterface $questionResponder`.
- `src/Agent/OrchestratorAgent.php` — store the responder; construct `QuestionToolkit` (after the LoopToolkit block ~`:526`).
- `src/Agent/AgentRunner.php` — thread `?QuestionResponderInterface` through `run/runForTask/runWithObserver/runSegment/doRun/executeSegment/createAgent`.
- `src/Command/RunCommand.php` — build `InteractiveQuestionResponder` and pass it in (REPL).
- `src/Repl/ExecutionPolicyFactory.php` — (optional) a `buildQuestionResponder()` helper, or build inline in `RunCommand`.
- `src/Command/TurnRunCommand.php` — build `SuspendingQuestionResponder`.
- `src/Command/TaskRunCommand.php` — build `PolicyQuestionResponder` (resolving `on_question` + loop/stage context from the task).
- `src/Contract/LoopDefinition.php` — add `on_question` (`block` | `default`, default `block`) to the enum-backed field, `fromArray`/`toArray`.
- `src/Agent/LoopExecutor.php` — inject the answer section in `prepareNextStage()`; expose a `reopenForAnswer()`/reuse retry path helper.
- `src/Api/Handler/LoopHandler.php` — reuse `retryIteration()` machinery from the answer endpoint (or expose a shared reopen method).
- `src/Command/ApiCommand.php` — construct `QuestionHandler`, add to `registerRoutes` signature + call site, register the two routes; add a `question` SSE event type where turn events are streamed.
- `src/Api/Handler/MessageHandler.php` — pass through the new `question` turn-event type in the SSE poller (it already forwards all `turn_events`; verify no allowlist filtering drops it).
- `docs/QUESTIONS.md` (new), `docs/API.md`, `docs/LOOPS.md`, `config/source.json`.

---

## Task 1: Shared representation — value objects, validation, responder interface

**Files:**
- Create: `src/Contract/QuestionFormat.php`, `src/Contract/QuestionOption.php`, `src/Contract/QuestionResponse.php`, `src/Contract/QuestionRequest.php`, `src/Contract/QuestionResponderInterface.php`
- Test: `tests/Unit/Contract/QuestionRequestTest.php`, `tests/Unit/Contract/QuestionResponseTest.php`

**Interfaces:**
- Produces:
  - `enum QuestionFormat: string { case SingleSelect = 'single_select'; case MultiSelect = 'multi_select'; case FreeText = 'free_text'; }`
  - `final readonly class QuestionOption { public function __construct(public string $label, public ?string $description = null); public static function fromArray(array): self; public function toArray(): array; }`
  - `final readonly class QuestionResponse { public function __construct(public array $selected = [], public ?string $text = null); public function isValidFor(QuestionRequest $q): bool; public static function fromArray(array): self; public function toArray(): array; }`
  - `final readonly class QuestionRequest { public function __construct(public string $id, public string $prompt, public QuestionFormat $format, public array $options, public bool $allowOther, public QuestionResponse $suggested, public ?string $header = null); public static function fromArray(array): self; public function toArray(): array; public function optionLabels(): array; }`
  - `interface QuestionResponderInterface { public function ask(QuestionRequest $question): ?QuestionResponse; }` — returns a validated answer, or `null` when the question was escalated to an operator with no synchronous answer (loop `block` mode). Implementations throw only on a genuine error (e.g. a cancelled/timed-out API wait).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Contract/QuestionResponseTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;

function makeRequest(
    QuestionFormat $format,
    array $optionLabels = [],
    bool $allowOther = false,
    ?QuestionResponse $suggested = null,
): QuestionRequest {
    $options = array_map(fn(string $l) => new QuestionOption($l), $optionLabels);
    $suggested ??= match ($format) {
        QuestionFormat::SingleSelect => new QuestionResponse([$optionLabels[0]]),
        QuestionFormat::MultiSelect => new QuestionResponse([]),
        QuestionFormat::FreeText => new QuestionResponse([], 'default'),
    };

    return new QuestionRequest(
        id: 'q1',
        prompt: 'Pick',
        format: $format,
        options: $options,
        allowOther: $allowOther,
        suggested: $suggested,
    );
}

test('single-select accepts exactly one known label', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b', 'c']);
    expect((new QuestionResponse(['b']))->isValidFor($q))->toBeTrue();
});

test('single-select rejects unknown label', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b']);
    expect((new QuestionResponse(['z']))->isValidFor($q))->toBeFalse();
});

test('single-select rejects more than one label', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b']);
    expect((new QuestionResponse(['a', 'b']))->isValidFor($q))->toBeFalse();
});

test('single-select rejects zero labels without other', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b']);
    expect((new QuestionResponse([]))->isValidFor($q))->toBeFalse();
});

test('single-select accepts Other text only when allowOther', function () {
    $with = makeRequest(QuestionFormat::SingleSelect, ['a', 'b'], allowOther: true);
    $without = makeRequest(QuestionFormat::SingleSelect, ['a', 'b'], allowOther: false);
    expect((new QuestionResponse([], 'custom'))->isValidFor($with))->toBeTrue();
    expect((new QuestionResponse([], 'custom'))->isValidFor($without))->toBeFalse();
});

test('multi-select accepts zero-or-more known labels', function () {
    $q = makeRequest(QuestionFormat::MultiSelect, ['a', 'b', 'c']);
    expect((new QuestionResponse([]))->isValidFor($q))->toBeTrue();
    expect((new QuestionResponse(['a', 'c']))->isValidFor($q))->toBeTrue();
});

test('multi-select rejects any unknown label', function () {
    $q = makeRequest(QuestionFormat::MultiSelect, ['a', 'b']);
    expect((new QuestionResponse(['a', 'z']))->isValidFor($q))->toBeFalse();
});

test('multi-select allows text only with allowOther', function () {
    $with = makeRequest(QuestionFormat::MultiSelect, ['a'], allowOther: true);
    $without = makeRequest(QuestionFormat::MultiSelect, ['a'], allowOther: false);
    expect((new QuestionResponse(['a'], 'extra'))->isValidFor($with))->toBeTrue();
    expect((new QuestionResponse(['a'], 'extra'))->isValidFor($without))->toBeFalse();
});

test('free-text requires non-empty text and empty selected', function () {
    $q = makeRequest(QuestionFormat::FreeText);
    expect((new QuestionResponse([], 'hello'))->isValidFor($q))->toBeTrue();
    expect((new QuestionResponse([], null))->isValidFor($q))->toBeFalse();
    expect((new QuestionResponse([], ''))->isValidFor($q))->toBeFalse();
    expect((new QuestionResponse(['a'], 'hello'))->isValidFor($q))->toBeFalse();
});

test('response round-trips through array', function () {
    $r = new QuestionResponse(['a', 'b'], 'note');
    expect(QuestionResponse::fromArray($r->toArray()))->toEqual($r);
});
```

`tests/Unit/Contract/QuestionRequestTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;

test('request round-trips through array', function () {
    $q = new QuestionRequest(
        id: 'q1',
        prompt: 'Which fruit?',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('apple', 'a fruit'), new QuestionOption('pear')],
        allowOther: true,
        suggested: new QuestionResponse(['apple']),
        header: 'Fruit',
    );

    $restored = QuestionRequest::fromArray($q->toArray());

    expect($restored->id)->toBe('q1');
    expect($restored->format)->toBe(QuestionFormat::SingleSelect);
    expect($restored->optionLabels())->toBe(['apple', 'pear']);
    expect($restored->allowOther)->toBeTrue();
    expect($restored->suggested->selected)->toBe(['apple']);
    expect($restored->header)->toBe('Fruit');
});

test('request rejects a suggested answer that is invalid for it', function () {
    expect(fn() => new QuestionRequest(
        id: 'q1',
        prompt: 'Pick',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('a')],
        allowOther: false,
        suggested: new QuestionResponse(['nonexistent']),
    ))->toThrow(InvalidArgumentException::class);
});

test('select request rejects empty options', function () {
    expect(fn() => new QuestionRequest(
        id: 'q1',
        prompt: 'Pick',
        format: QuestionFormat::SingleSelect,
        options: [],
        allowOther: false,
        suggested: new QuestionResponse([], 'x'),
    ))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Contract/QuestionResponseTest.php tests/Unit/Contract/QuestionRequestTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the value objects**

`src/Contract/QuestionFormat.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Answer shape an agent requests from the user via `ask_user`.
 */
enum QuestionFormat: string
{
    case SingleSelect = 'single_select';
    case MultiSelect = 'multi_select';
    case FreeText = 'free_text';

    public function isSelect(): bool
    {
        return $this === self::SingleSelect || $this === self::MultiSelect;
    }
}
```

`src/Contract/QuestionOption.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A selectable choice. `label` is the answer identifier.
 */
final readonly class QuestionOption
{
    public function __construct(
        public string $label,
        public ?string $description = null,
    ) {
        if ($label === '') {
            throw new \InvalidArgumentException('QuestionOption label must not be empty');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $description = $data['description'] ?? null;

        return new self(
            label: (string) ($data['label'] ?? ''),
            description: is_string($description) && $description !== '' ? $description : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['label' => $this->label];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        return $out;
    }
}
```

`src/Contract/QuestionResponse.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A user's answer to a QuestionRequest.
 *
 * `isValidFor()` is the single validation authority for the whole feature —
 * no other code re-implements answer validation.
 */
final readonly class QuestionResponse
{
    /**
     * @param list<string> $selected Chosen option labels (empty for free-text / Other).
     * @param ?string       $text    Free-text answer, or the "Other" value on a select.
     */
    public function __construct(
        public array $selected = [],
        public ?string $text = null,
    ) {}

    public function isValidFor(QuestionRequest $question): bool
    {
        $labels = $question->optionLabels();
        $hasText = is_string($this->text) && $this->text !== '';

        return match ($question->format) {
            QuestionFormat::FreeText => $hasText && $this->selected === [],
            QuestionFormat::SingleSelect => $this->isValidSingleSelect($labels, $hasText, $question->allowOther),
            QuestionFormat::MultiSelect => $this->isValidMultiSelect($labels, $hasText, $question->allowOther),
        };
    }

    /**
     * @param list<string> $labels
     */
    private function isValidSingleSelect(array $labels, bool $hasText, bool $allowOther): bool
    {
        $otherPath = $allowOther && $hasText && $this->selected === [];
        $optionPath = count($this->selected) === 1
            && in_array($this->selected[0], $labels, true)
            && $this->text === null;

        return $otherPath || $optionPath;
    }

    /**
     * @param list<string> $labels
     */
    private function isValidMultiSelect(array $labels, bool $hasText, bool $allowOther): bool
    {
        foreach ($this->selected as $label) {
            if (!in_array($label, $labels, true)) {
                return false;
            }
        }

        // Text is permitted only as an Other entry, and only when allowOther.
        if ($this->text !== null) {
            return $allowOther && $hasText;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $selected = [];
        foreach ($data['selected'] ?? [] as $label) {
            if (is_string($label)) {
                $selected[] = $label;
            }
        }
        $text = $data['text'] ?? null;

        return new self(
            selected: $selected,
            text: is_string($text) ? $text : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['selected' => array_values($this->selected), 'text' => $this->text];
    }
}
```

`src/Contract/QuestionRequest.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * An agent's structured question to the user. One question per ask_user call (v1).
 */
final readonly class QuestionRequest
{
    /**
     * @param list<QuestionOption> $options  Required for selects, empty for free-text.
     * @param QuestionResponse      $suggested The agent's best-guess default (must be valid for this request).
     */
    public function __construct(
        public string $id,
        public string $prompt,
        public QuestionFormat $format,
        public array $options,
        public bool $allowOther,
        public QuestionResponse $suggested,
        public ?string $header = null,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('QuestionRequest id must not be empty');
        }
        if ($prompt === '') {
            throw new \InvalidArgumentException('QuestionRequest prompt must not be empty');
        }
        if ($format->isSelect() && $options === []) {
            throw new \InvalidArgumentException('Select questions require at least one option');
        }
        if (!$format->isSelect() && $options !== []) {
            throw new \InvalidArgumentException('Free-text questions must not carry options');
        }
        if (!$suggested->isValidFor($this)) {
            throw new \InvalidArgumentException('QuestionRequest suggested answer is not valid for the question');
        }
    }

    /**
     * @return list<string>
     */
    public function optionLabels(): array
    {
        return array_map(static fn(QuestionOption $o): string => $o->label, $this->options);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $options = [];
        foreach ($data['options'] ?? [] as $opt) {
            if (is_array($opt)) {
                $options[] = QuestionOption::fromArray($opt);
            }
        }
        $header = $data['header'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            prompt: (string) ($data['prompt'] ?? ''),
            format: QuestionFormat::from((string) ($data['format'] ?? '')),
            options: $options,
            allowOther: (bool) ($data['allow_other'] ?? false),
            suggested: QuestionResponse::fromArray(is_array($data['suggested'] ?? null) ? $data['suggested'] : []),
            header: is_string($header) && $header !== '' ? $header : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'format' => $this->format->value,
            'options' => array_map(static fn(QuestionOption $o): array => $o->toArray(), $this->options),
            'allow_other' => $this->allowOther,
            'suggested' => $this->suggested->toArray(),
            'header' => $this->header,
        ];
    }
}
```

`src/Contract/QuestionResponderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Renders a QuestionRequest on a surface (REPL / API / loop) and returns a
 * validated answer.
 *
 * Return contract:
 *  - a QuestionResponse that satisfies QuestionResponse::isValidFor($question), OR
 *  - null when the question was escalated to an operator and no synchronous
 *    answer is available (loop `block` mode — the caller emits a hard-STOP
 *    sentinel to the agent and halts the stage).
 * Implementations throw only on a genuine error (e.g. a cancelled or
 * timed-out API wait), never as ordinary control flow.
 */
interface QuestionResponderInterface
{
    public function ask(QuestionRequest $question): ?QuestionResponse;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Contract/QuestionResponseTest.php tests/Unit/Contract/QuestionRequestTest.php`
Expected: PASS (all green).

- [ ] **Step 5: Commit**

```bash
git add src/Contract/QuestionFormat.php src/Contract/QuestionOption.php src/Contract/QuestionResponse.php src/Contract/QuestionRequest.php src/Contract/QuestionResponderInterface.php tests/Unit/Contract/QuestionResponseTest.php tests/Unit/Contract/QuestionRequestTest.php
git commit -m "feat(questions): shared QuestionRequest/QuestionResponse representation + isValidFor"
```

---

## Task 2: Persistence — `questions` table, storage CRUD, and audit-logging collaborator

**Files:**
- Modify: `src/Storage/SessionStorage.php` (add schema block near the `turn_events` table ~`:293`; add `'questions'` to the `$expected` list at `:1433`; add CRUD methods near the other task/turn methods ~`:2660-2760`)
- Create: `src/Question/QuestionPersistence.php`
- Test: `tests/Unit/Storage/QuestionStorageTest.php`, `tests/Unit/Question/QuestionPersistenceTest.php`

**Interfaces:**
- Consumes: `QuestionRequest`, `QuestionResponse`, `QuestionRecord` (Task 1 + this task).
- Produces (on `SessionStorage`):
  - `createQuestion(string $sessionId, QuestionRequest $q, string $responderKind, ?string $turnId = null, ?string $loopId = null, ?string $stageId = null): void`
  - `getQuestion(string $questionId): ?array` — raw row or `null`.
  - `getPendingQuestions(string $sessionId): array` — list of raw rows with `status = 'pending'`.
  - `recordQuestionAnswer(string $questionId, QuestionResponse $answer): bool` — sets `answer` + `status='answered'`; returns `false` if not pending.
- Produces `final readonly class QuestionRecord` in `src/Contract/QuestionRecord.php` with `id, sessionId, request: QuestionRequest, responderKind, status, answer: ?QuestionResponse, loopId: ?string, stageId: ?string` + `fromRow(array): self` + `toArray(): array`.
- Produces `final class QuestionPersistence` wrapping `SessionStorage` + audit:
  - `__construct(SessionStorage $storage)`
  - `persistAsked(string $sessionId, QuestionRequest $q, string $responderKind, ?string $turnId, ?string $loopId = null, ?string $stageId = null): void` — calls `createQuestion` + `logAudit(action: 'question_asked')`.
  - `persistAnswered(string $questionId, string $sessionId, QuestionRequest $q, QuestionResponse $answer, ?string $turnId = null): bool` — validates via `isValidFor`, `recordQuestionAnswer`, `logAudit(action: 'question_answered')`; returns `false` on invalid or not-pending.
  - `pending(string $sessionId): list<QuestionRecord>`
  - `find(string $questionId): ?QuestionRecord`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Storage/QuestionStorageTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\SessionStorage;

function questionStorage(): SessionStorage
{
    return new SessionStorage(':memory:');
}

function sampleRequest(string $id = 'q1'): QuestionRequest
{
    return new QuestionRequest(
        id: $id,
        prompt: 'Which fruit?',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('apple'), new QuestionOption('pear')],
        allowOther: false,
        suggested: new QuestionResponse(['apple']),
    );
}

test('createQuestion then getQuestion returns a pending row', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');

    $storage->createQuestion($sessionId, sampleRequest(), 'interactive');

    $row = $storage->getQuestion('q1');
    expect($row)->not->toBeNull();
    expect($row['status'])->toBe('pending');
    expect($row['session_id'])->toBe($sessionId);
    expect($row['responder_kind'])->toBe('interactive');
});

test('getPendingQuestions lists only pending questions for the session', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $storage->createQuestion($sessionId, sampleRequest('q1'), 'suspending');
    $storage->createQuestion($sessionId, sampleRequest('q2'), 'suspending');

    $storage->recordQuestionAnswer('q1', new QuestionResponse(['apple']));

    $pending = $storage->getPendingQuestions($sessionId);
    expect(count($pending))->toBe(1);
    expect($pending[0]['id'])->toBe('q2');
});

test('recordQuestionAnswer marks answered and stores the answer', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $storage->createQuestion($sessionId, sampleRequest(), 'policy');

    expect($storage->recordQuestionAnswer('q1', new QuestionResponse(['pear'])))->toBeTrue();

    $row = $storage->getQuestion('q1');
    expect($row['status'])->toBe('answered');
    expect(json_decode($row['answer'], true)['selected'])->toBe(['pear']);
});

test('recordQuestionAnswer returns false when not pending', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $storage->createQuestion($sessionId, sampleRequest(), 'policy');
    $storage->recordQuestionAnswer('q1', new QuestionResponse(['pear']));

    expect($storage->recordQuestionAnswer('q1', new QuestionResponse(['apple'])))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/QuestionStorageTest.php`
Expected: FAIL — `createQuestion` not defined.

- [ ] **Step 3: Add the schema + methods to `SessionStorage`**

Add a schema block alongside the other `CREATE TABLE IF NOT EXISTS` statements (right after the `turn_events` block around `src/Storage/SessionStorage.php:293`):

```php
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS questions (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
                turn_id TEXT,
                loop_id TEXT,
                stage_id TEXT,
                responder_kind TEXT NOT NULL,
                request TEXT NOT NULL,
                answer TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                created_at TEXT NOT NULL,
                answered_at TEXT
            )
        SQL);
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_questions_session ON questions(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_questions_status ON questions(status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_questions_loop ON questions(loop_id)');
```

Add `'questions'` to the `$expected` array at `src/Storage/SessionStorage.php:1433`:

```php
        $expected = ['sessions', 'messages', 'turns', 'audit_log', 'child_runs', 'background_tasks', 'task_events', 'task_inputs', 'turn_processes', 'turn_events', 'questions'];
```

Add the CRUD methods (place near the other turn/task methods, ~`:2760`). Use `IdGenerator` and `date('c')` exactly as `logAudit` does (`:1745-1746`):

```php
    /**
     * Persist a pending structured question.
     */
    public function createQuestion(
        string $sessionId,
        \CoquiBot\Coqui\Contract\QuestionRequest $question,
        string $responderKind,
        ?string $turnId = null,
        ?string $loopId = null,
        ?string $stageId = null,
    ): void {
        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO questions (id, session_id, turn_id, loop_id, stage_id, responder_kind, request, status, created_at)
            VALUES (:id, :session_id, :turn_id, :loop_id, :stage_id, :responder_kind, :request, 'pending', :created_at)
        SQL);
        $stmt->execute([
            ':id' => $question->id,
            ':session_id' => $sessionId,
            ':turn_id' => $turnId,
            ':loop_id' => $loopId,
            ':stage_id' => $stageId,
            ':responder_kind' => $responderKind,
            ':request' => json_encode($question->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':created_at' => date('c'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getQuestion(string $questionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM questions WHERE id = :id');
        $stmt->execute([':id' => $questionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPendingQuestions(string $sessionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM questions WHERE session_id = :session_id AND status = 'pending' ORDER BY created_at ASC",
        );
        $stmt->execute([':session_id' => $sessionId]);

        return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Record an answer for a pending question. Returns false if it is not pending.
     */
    public function recordQuestionAnswer(string $questionId, \CoquiBot\Coqui\Contract\QuestionResponse $answer): bool
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE questions
            SET answer = :answer, status = 'answered', answered_at = :answered_at
            WHERE id = :id AND status = 'pending'
        SQL);
        $stmt->execute([
            ':answer' => json_encode($answer->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':answered_at' => date('c'),
            ':id' => $questionId,
        ]);

        return $stmt->rowCount() > 0;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Storage/QuestionStorageTest.php`
Expected: PASS.

- [ ] **Step 5: Write the `QuestionRecord` DTO + `QuestionPersistence` collaborator with its test**

`src/Contract/QuestionRecord.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A persisted question row rehydrated into typed form.
 */
final readonly class QuestionRecord
{
    public function __construct(
        public string $id,
        public string $sessionId,
        public QuestionRequest $request,
        public string $responderKind,
        public string $status,
        public ?QuestionResponse $answer = null,
        public ?string $loopId = null,
        public ?string $stageId = null,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $request = QuestionRequest::fromArray(
            json_decode((string) $row['request'], true, 512, JSON_THROW_ON_ERROR),
        );
        $answer = null;
        if (is_string($row['answer'] ?? null) && $row['answer'] !== '') {
            $answer = QuestionResponse::fromArray(json_decode($row['answer'], true, 512, JSON_THROW_ON_ERROR));
        }

        return new self(
            id: (string) $row['id'],
            sessionId: (string) $row['session_id'],
            request: $request,
            responderKind: (string) $row['responder_kind'],
            status: (string) $row['status'],
            answer: $answer,
            loopId: isset($row['loop_id']) && $row['loop_id'] !== '' ? (string) $row['loop_id'] : null,
            stageId: isset($row['stage_id']) && $row['stage_id'] !== '' ? (string) $row['stage_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'request' => $this->request->toArray(),
            'responder_kind' => $this->responderKind,
            'status' => $this->status,
            'answer' => $this->answer?->toArray(),
            'loop_id' => $this->loopId,
            'stage_id' => $this->stageId,
        ];
    }
}
```

`src/Question/QuestionPersistence.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\QuestionRecord;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Single place that persists asked/answered questions and mirrors the
 * approval audit trail (audit_log actions: question_asked / question_answered).
 */
final class QuestionPersistence
{
    public function __construct(private readonly SessionStorage $storage) {}

    public function persistAsked(
        string $sessionId,
        QuestionRequest $question,
        string $responderKind,
        ?string $turnId = null,
        ?string $loopId = null,
        ?string $stageId = null,
    ): void {
        $this->storage->createQuestion($sessionId, $question, $responderKind, $turnId, $loopId, $stageId);
        $this->storage->logAudit(
            sessionId: $sessionId,
            toolName: 'ask_user',
            arguments: $question->toArray(),
            action: 'question_asked',
            reason: $question->prompt,
            turnId: $turnId,
        );
    }

    /**
     * Validate + persist an answer. Returns false when the answer is invalid
     * for the question or the question is no longer pending.
     */
    public function persistAnswered(
        string $questionId,
        string $sessionId,
        QuestionRequest $question,
        QuestionResponse $answer,
        ?string $turnId = null,
    ): bool {
        if (!$answer->isValidFor($question)) {
            return false;
        }
        if (!$this->storage->recordQuestionAnswer($questionId, $answer)) {
            return false;
        }
        $this->storage->logAudit(
            sessionId: $sessionId,
            toolName: 'ask_user',
            arguments: $answer->toArray(),
            action: 'question_answered',
            reason: $question->prompt,
            turnId: $turnId,
        );

        return true;
    }

    /**
     * @return list<QuestionRecord>
     */
    public function pending(string $sessionId): array
    {
        return array_map(
            static fn(array $row): QuestionRecord => QuestionRecord::fromRow($row),
            $this->storage->getPendingQuestions($sessionId),
        );
    }

    public function find(string $questionId): ?QuestionRecord
    {
        $row = $this->storage->getQuestion($questionId);

        return $row === null ? null : QuestionRecord::fromRow($row);
    }
}
```

`tests/Unit/Question/QuestionPersistenceTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;

test('persistAsked writes an audit row and a pending question', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $p = new QuestionPersistence($storage);

    $p->persistAsked($sessionId, sampleRequest(), 'interactive', turnId: null);

    expect(count($p->pending($sessionId)))->toBe(1);
    $audit = $storage->getPdo()->query("SELECT action FROM audit_log WHERE action = 'question_asked'")->fetchAll();
    expect(count($audit))->toBe(1);
});

test('persistAnswered rejects an invalid answer', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $p = new QuestionPersistence($storage);
    $request = sampleRequest();
    $p->persistAsked($sessionId, $request, 'suspending', turnId: null);

    expect($p->persistAnswered('q1', $sessionId, $request, new QuestionResponse(['nope'])))->toBeFalse();
    expect($p->find('q1')->status)->toBe('pending');
});

test('persistAnswered stores a valid answer and audits it', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $p = new QuestionPersistence($storage);
    $request = sampleRequest();
    $p->persistAsked($sessionId, $request, 'suspending', turnId: null);

    expect($p->persistAnswered('q1', $sessionId, $request, new QuestionResponse(['pear'])))->toBeTrue();
    expect($p->find('q1')->answer->selected)->toBe(['pear']);
    $audit = $storage->getPdo()->query("SELECT action FROM audit_log WHERE action = 'question_answered'")->fetchAll();
    expect(count($audit))->toBe(1);
});
```

> Note: `sampleRequest()` is defined in `tests/Unit/Storage/QuestionStorageTest.php`. Pest shares top-level test helper functions across the suite via autoloading of test files; if the runner reports a redeclaration, move `sampleRequest()` into `tests/Support/` (a file already autoloaded by `tests/Pest.php`) and delete the inline copy. Verify by running the whole `tests/Unit` tree in Step 6.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/QuestionStorageTest.php tests/Unit/Question/QuestionPersistenceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Storage/SessionStorage.php src/Contract/QuestionRecord.php src/Question/QuestionPersistence.php tests/Unit/Storage/QuestionStorageTest.php tests/Unit/Question/QuestionPersistenceTest.php
git commit -m "feat(questions): questions table + storage CRUD + audit-logging persistence collaborator"
```

---

## Task 3: `InteractiveQuestionResponder` (REPL)

**Files:**
- Create: `src/Question/InteractiveQuestionResponder.php`
- Test: `tests/Unit/Question/InteractiveQuestionResponderTest.php`

**Interfaces:**
- Consumes: `QuestionResponderInterface`, `QuestionRequest`, `QuestionResponse`, `QuestionFormat`, `QuestionPersistence`, `Symfony\Component\Console\Style\SymfonyStyle`.
- Produces: `final class InteractiveQuestionResponder implements QuestionResponderInterface` with `__construct(SymfonyStyle $io, QuestionPersistence $persistence, string $sessionId, ?string $turnId = null)`.

Behavior: persist the question as `interactive`, prompt synchronously via `SymfonyStyle` (`choice()` for single-select with the suggested label as default; a repeated `choice()`/multi loop for multi-select; `ask()` for free-text; an appended "Other…" choice on `allowOther` selects that triggers `ask()`), build the `QuestionResponse`, and — because the answer is synchronous here — call `persistAnswered()` before returning. Re-prompt on an answer that fails `isValidFor` (defensive; the UI already constrains choices).

- [ ] **Step 1: Write the failing test** (Symfony's `SymfonyStyle` reads scripted input via an `ArrayInput`/`StreamableInputInterface`; use `Symfony\Component\Console\Tester\CommandTester`-style input stream)

`tests/Unit/Question/InteractiveQuestionResponderTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\InteractiveQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function scriptedIo(string $keystrokes): array
{
    $input = new ArrayInput([]);
    $input->setStream(fopenString($keystrokes));
    $input->setInteractive(true);
    $output = new BufferedOutput();

    return [new SymfonyStyle($input, $output), $output];
}

function fopenString(string $content)
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

test('single-select returns the chosen option and persists the answer', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    [$io] = scriptedIo("pear\n");

    $responder = new InteractiveQuestionResponder($io, new QuestionPersistence($storage), $sessionId);
    $request = new QuestionRequest(
        id: 'q1', prompt: 'Which fruit?', format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('apple'), new QuestionOption('pear')],
        allowOther: false, suggested: new QuestionResponse(['apple']),
    );

    $answer = $responder->ask($request);

    expect($answer->selected)->toBe(['pear']);
    expect($answer->isValidFor($request))->toBeTrue();
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});

test('free-text returns typed text', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    [$io] = scriptedIo("blue and green\n");

    $responder = new InteractiveQuestionResponder($io, new QuestionPersistence($storage), $sessionId);
    $request = new QuestionRequest(
        id: 'q2', prompt: 'Colours?', format: QuestionFormat::FreeText,
        options: [], allowOther: false, suggested: new QuestionResponse([], 'none'),
    );

    $answer = $responder->ask($request);

    expect($answer->text)->toBe('blue and green');
    expect($answer->selected)->toBe([]);
});
```

> If `ArrayInput::setStream` is unavailable in the installed Symfony Console version, substitute `Symfony\Component\Console\Input\StringInput` seeded via the `QuestionHelper` stream API used elsewhere in the repo — grep `setStream(` under `src/` and `tests/` to match the existing scripted-prompt idiom before writing this test.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Question/InteractiveQuestionResponderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the responder**

`src/Question/InteractiveQuestionResponder.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders a question synchronously in the REPL via SymfonyStyle.
 * Mirrors InteractiveApprovalPolicy's prompt-and-wait model.
 */
final class InteractiveQuestionResponder implements QuestionResponderInterface
{
    private const OTHER_LABEL = 'Other…';

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly QuestionPersistence $persistence,
        private readonly string $sessionId,
        private readonly ?string $turnId = null,
    ) {}

    public function ask(QuestionRequest $question): ?QuestionResponse
    {
        $this->persistence->persistAsked($this->sessionId, $question, 'interactive', $this->turnId);

        $this->io->newLine();
        if ($question->header !== null) {
            $this->io->writeln("<fg=cyan>{$question->header}</>");
        }
        $this->io->writeln("<fg=yellow>{$question->prompt}</>");

        do {
            $answer = $this->collect($question);
        } while (!$answer->isValidFor($question));

        $this->persistence->persistAnswered($question->id, $this->sessionId, $question, $answer, $this->turnId);

        return $answer;
    }

    private function collect(QuestionRequest $question): QuestionResponse
    {
        return match ($question->format) {
            QuestionFormat::FreeText => new QuestionResponse([], (string) $this->io->ask($question->prompt, $question->suggested->text)),
            QuestionFormat::SingleSelect => $this->collectSingle($question),
            QuestionFormat::MultiSelect => $this->collectMulti($question),
        };
    }

    private function collectSingle(QuestionRequest $question): QuestionResponse
    {
        $labels = $question->optionLabels();
        $choices = $labels;
        if ($question->allowOther) {
            $choices[] = self::OTHER_LABEL;
        }
        $default = $question->suggested->selected[0] ?? ($question->allowOther ? self::OTHER_LABEL : $labels[0]);

        $chosen = (string) $this->io->choice($question->prompt, $choices, $default);
        if ($chosen === self::OTHER_LABEL) {
            return new QuestionResponse([], (string) $this->io->ask('Your answer', $question->suggested->text));
        }

        return new QuestionResponse([$chosen]);
    }

    private function collectMulti(QuestionRequest $question): QuestionResponse
    {
        $labels = $question->optionLabels();
        $default = implode(',', $question->suggested->selected);
        // SymfonyStyle::choice with multiSelect: true returns an array of labels.
        /** @var list<string> $picked */
        $picked = (array) $this->io->choice($question->prompt, $labels, $default === '' ? null : $default, true);

        $text = null;
        if ($question->allowOther && $this->io->confirm('Add an "Other" free-text entry?', false)) {
            $text = (string) $this->io->ask('Other');
        }

        return new QuestionResponse(array_values(array_map('strval', $picked)), $text);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Question/InteractiveQuestionResponderTest.php`
Expected: PASS. If the multi-select `choice(..., true)` default handling differs in the installed Symfony version, adjust the default argument per that version — keep the returned `QuestionResponse` shape identical.

- [ ] **Step 5: Commit**

```bash
git add src/Question/InteractiveQuestionResponder.php tests/Unit/Question/InteractiveQuestionResponderTest.php
git commit -m "feat(questions): InteractiveQuestionResponder for the REPL"
```

---

## Task 4: `PolicyQuestionResponder` (loops / background)

**Files:**
- Create: `src/Question/PolicyQuestionResponder.php`
- Create: `src/Contract/OnQuestionPolicy.php` (enum `Block | DefaultAnswer`)
- Create: `src/Contract/LoopBlockNotifier.php` (interface)
- Test: `tests/Unit/Question/PolicyQuestionResponderTest.php`

**Interfaces:**
- Produces `enum OnQuestionPolicy: string { case Block = 'block'; case DefaultAnswer = 'default'; public static function fromString(?string): self; }` (default → `Block`).
- Produces `final class PolicyQuestionResponder implements QuestionResponderInterface` with `__construct(OnQuestionPolicy $policy, QuestionPersistence $persistence, string $sessionId, ?LoopBlockNotifier $loopBlock = null, ?string $turnId = null, ?string $loopId = null, ?string $stageId = null)`.
  - `default` → persist asked + persist answered (`suggested`) + return `suggested`.
  - `block` → persist asked, escalate the loop to `blocked` (via `LoopBlockNotifier`, Task 9), and **return `null`** (no synchronous answer). The tool (Task 6) sees `null` and returns the hard-STOP sentinel `ToolResult`.
- Consumes `interface LoopBlockNotifier { public function block(string $loopId, ?string $stageId, QuestionRequest $q): void; }` (implemented in Task 9 against `LoopStore`; `null` for non-loop background tasks).

> **Design (sentinel, not exception — per review):** block mode never fabricates an answer and never throws for control flow — it escalates the loop and returns `null` through the nullable `ask()` contract. The `ask_user` tool (Task 6) maps `null` → a hard-STOP sentinel `ToolResult` that tells the agent to stop immediately. This keeps `ask()` honest, avoids fighting `AgentRunner`'s catch-all, and needs no exception type. If `$loopId`/`$loopBlock` is `null` (a plain background task, not a loop), block mode still persists the question and returns `null`; the agent is told to stop and the task ends cleanly (no operator channel exists to answer, which is the safe non-interactive default).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Question/PolicyQuestionResponderTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopBlockNotifier;
use CoquiBot\Coqui\Contract\OnQuestionPolicy;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Question\PolicyQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;

test('OnQuestionPolicy defaults to block', function () {
    expect(OnQuestionPolicy::fromString(null))->toBe(OnQuestionPolicy::Block);
    expect(OnQuestionPolicy::fromString('nonsense'))->toBe(OnQuestionPolicy::Block);
    expect(OnQuestionPolicy::fromString('default'))->toBe(OnQuestionPolicy::DefaultAnswer);
});

test('default policy returns the suggestion and marks it answered', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $responder = new PolicyQuestionResponder(
        OnQuestionPolicy::DefaultAnswer, new QuestionPersistence($storage), $sessionId,
    );
    $request = sampleRequest();

    $answer = $responder->ask($request);

    expect($answer->selected)->toBe(['apple']);
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});

test('block policy persists the question, escalates the loop, and returns null', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');

    $blocked = [];
    $notifier = new class($blocked) implements LoopBlockNotifier {
        public function __construct(public array &$blocked) {}
        public function block(string $loopId, ?string $stageId, QuestionRequest $q): void
        {
            $this->blocked[] = [$loopId, $stageId, $q->id];
        }
    };

    $responder = new PolicyQuestionResponder(
        OnQuestionPolicy::Block, new QuestionPersistence($storage), $sessionId,
        loopBlock: $notifier, loopId: 'loop1', stageId: 'stage1',
    );

    $result = $responder->ask(sampleRequest());

    expect($result)->toBeNull();
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
    expect($blocked)->toBe([['loop1', 'stage1', 'q1']]);
});

test('block policy without a loop notifier still persists and returns null', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $responder = new PolicyQuestionResponder(
        OnQuestionPolicy::Block, new QuestionPersistence($storage), $sessionId,
    );

    expect($responder->ask(sampleRequest()))->toBeNull();
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Question/PolicyQuestionResponderTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the enum, notifier interface, and responder**

`src/Contract/OnQuestionPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Non-interactive question policy for loops / background tasks.
 */
enum OnQuestionPolicy: string
{
    case Block = 'block';
    case DefaultAnswer = 'default';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Block;
    }
}
```

`src/Contract/LoopBlockNotifier.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Flips a loop to `blocked` carrying a QuestionRequest as the escalation
 * payload. Implemented in Task 9 over LoopStore; null for non-loop tasks.
 */
interface LoopBlockNotifier
{
    public function block(string $loopId, ?string $stageId, QuestionRequest $question): void;
}
```

`src/Question/PolicyQuestionResponder.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\LoopBlockNotifier;
use CoquiBot\Coqui\Contract\OnQuestionPolicy;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;

/**
 * Non-interactive responder for loop stages / background tasks.
 *
 * default → returns the agent's suggested answer inline and logs it.
 * block   → persists the question, escalates the loop to `blocked`, and returns
 *           null (no synchronous answer). The ask_user tool maps null → a
 *           hard-STOP sentinel ToolResult. No exception, no fabricated answer.
 */
final class PolicyQuestionResponder implements QuestionResponderInterface
{
    public function __construct(
        private readonly OnQuestionPolicy $policy,
        private readonly QuestionPersistence $persistence,
        private readonly string $sessionId,
        private readonly ?LoopBlockNotifier $loopBlock = null,
        private readonly ?string $turnId = null,
        private readonly ?string $loopId = null,
        private readonly ?string $stageId = null,
    ) {}

    public function ask(QuestionRequest $question): ?QuestionResponse
    {
        $this->persistence->persistAsked(
            $this->sessionId, $question, 'policy', $this->turnId, $this->loopId, $this->stageId,
        );

        if ($this->policy === OnQuestionPolicy::DefaultAnswer) {
            $this->persistence->persistAnswered(
                $question->id, $this->sessionId, $question, $question->suggested, $this->turnId,
            );

            return $question->suggested;
        }

        // block: escalate the loop (if any) and halt the stage — the tool
        // turns the null return into a hard-STOP sentinel for the agent.
        if ($this->loopBlock !== null && $this->loopId !== null) {
            $this->loopBlock->block($this->loopId, $this->stageId, $question);
        }

        return null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Question/PolicyQuestionResponderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Contract/OnQuestionPolicy.php src/Contract/LoopBlockNotifier.php src/Question/PolicyQuestionResponder.php tests/Unit/Question/PolicyQuestionResponderTest.php
git commit -m "feat(questions): PolicyQuestionResponder (default/block) for loops and background tasks"
```

---

## Task 5: `SuspendingQuestionResponder` (API child-turn)

**Files:**
- Create: `src/Question/SuspendingQuestionResponder.php`
- Test: `tests/Unit/Question/SuspendingQuestionResponderTest.php`

**Interfaces:**
- Produces `final class SuspendingQuestionResponder implements QuestionResponderInterface` with `__construct(QuestionPersistence $persistence, SessionStorage $storage, string $sessionId, string $turnProcessId, ?CancellationTokenInterface $cancellationToken = null, int $pollIntervalMicros = 200000, int $timeoutSeconds = 1800, ?callable $sleeper = null)`.
- Behavior: persist asked (`suspending`), emit a `question` turn-event via `SessionStorage::appendTurnEvent($turnProcessId, 'question', $question->toArray())` so `MessageHandler`'s SSE poller streams it, then block-poll `SessionStorage::getQuestion($id)` until `status === 'answered'` (returning the stored `QuestionResponse`), the cancellation token cancels, or the timeout elapses. On cancel/timeout, throw `QuestionUnansweredException` (defined in this file) so the turn ends cleanly. The injected `$sleeper` (defaults to `usleep`) and a monotonic clock closure keep the test fast and deterministic.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Question/SuspendingQuestionResponderTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Question\SuspendingQuestionResponder;
use CoquiBot\Coqui\Question\QuestionUnansweredException;
use CoquiBot\Coqui\Storage\SessionStorage;

test('ask emits a question turn-event and returns the answer once recorded', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $turnProcessId = $storage->createTurnProcess($sessionId, 'go');
    $persistence = new QuestionPersistence($storage);

    // Sleeper records the answer on the 2nd poll, simulating the REST endpoint.
    $calls = 0;
    $sleeper = function () use (&$calls, $storage): void {
        if (++$calls === 2) {
            $storage->recordQuestionAnswer('q1', new QuestionResponse(['pear']));
        }
    };

    $responder = new SuspendingQuestionResponder(
        $persistence, $storage, $sessionId, $turnProcessId,
        pollIntervalMicros: 1, timeoutSeconds: 5, sleeper: $sleeper,
    );

    $answer = $responder->ask(sampleRequest());

    expect($answer->selected)->toBe(['pear']);
    $events = $storage->getTurnEvents($turnProcessId);
    $types = array_map(fn($e) => $e['event_type'], $events);
    expect($types)->toContain('question');
});

test('ask throws when it times out with no answer', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $turnProcessId = $storage->createTurnProcess($sessionId, 'go');

    $responder = new SuspendingQuestionResponder(
        new QuestionPersistence($storage), $storage, $sessionId, $turnProcessId,
        pollIntervalMicros: 1, timeoutSeconds: 0, sleeper: fn() => null,
    );

    expect(fn() => $responder->ask(sampleRequest()))->toThrow(QuestionUnansweredException::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Question/SuspendingQuestionResponderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the responder**

`src/Question/SuspendingQuestionResponder.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * API responder for interactive turns running in a `turn:run` child process.
 *
 * Persists the question, emits a `question` turn-event (streamed to the client
 * by MessageHandler's SSE poller), then block-polls the DB for the answer that
 * the REST answer endpoint records. Blocking is safe: this is a dedicated
 * child process, not the API event loop.
 */
final class SuspendingQuestionResponder implements QuestionResponderInterface
{
    /** @var callable */
    private $sleeper;

    public function __construct(
        private readonly QuestionPersistence $persistence,
        private readonly SessionStorage $storage,
        private readonly string $sessionId,
        private readonly string $turnProcessId,
        private readonly ?CancellationTokenInterface $cancellationToken = null,
        private readonly int $pollIntervalMicros = 200000,
        private readonly int $timeoutSeconds = 1800,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn(int $micros) => usleep($micros);
    }

    public function ask(QuestionRequest $question): ?QuestionResponse
    {
        $this->persistence->persistAsked($this->sessionId, $question, 'suspending', $this->turnProcessId);
        $this->storage->appendTurnEvent($this->turnProcessId, 'question', $question->toArray());

        $deadline = hrtime(true) + ($this->timeoutSeconds * 1_000_000_000);

        while (true) {
            $row = $this->storage->getQuestion($question->id);
            if (is_array($row) && ($row['status'] ?? '') === 'answered' && is_string($row['answer'] ?? null)) {
                return QuestionResponse::fromArray(
                    json_decode($row['answer'], true, 512, JSON_THROW_ON_ERROR),
                );
            }

            if ($this->cancellationToken?->isCancelled() === true) {
                throw new QuestionUnansweredException('Turn cancelled before the question was answered.');
            }
            if (hrtime(true) >= $deadline) {
                throw new QuestionUnansweredException('Timed out waiting for an answer.');
            }

            ($this->sleeper)($this->pollIntervalMicros);
        }
    }
}
```

`src/Question/QuestionUnansweredException.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

/**
 * A suspended API question ended without an answer (cancel or timeout).
 * Caught at the ask_user tool boundary and surfaced as a terminal ToolResult.
 */
final class QuestionUnansweredException extends \RuntimeException
{
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Question/SuspendingQuestionResponderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Question/SuspendingQuestionResponder.php src/Question/QuestionUnansweredException.php tests/Unit/Question/SuspendingQuestionResponderTest.php
git commit -m "feat(questions): SuspendingQuestionResponder for API turns (persist + SSE event + block-poll)"
```

---

## Task 6: `QuestionToolkit` and the `ask_user` tool

**Files:**
- Create: `src/Toolkit/QuestionToolkit.php`
- Test: `tests/Unit/Toolkit/QuestionToolkitTest.php`

**Interfaces:**
- Consumes: `QuestionResponderInterface`, `QuestionRequest/Response/Format/Option`, `QuestionUnansweredException`, php-agents `Tool`, `ToolResult`, `StringParameter`, `EnumParameter`, `BoolParameter`, `ArrayParameter` (verify the array param class name via `ls vendor/carmelosantana/php-agents/src/Tool/Parameter/`; if there is no `ArrayParameter`, accept `options`/`suggested` as JSON strings via `StringParameter` and `json_decode` them in the callback — the test covers both option and free-text paths regardless).
- Produces `final class QuestionToolkit implements ToolkitInterface` with `__construct(QuestionResponderInterface $responder, ?string $idPrefix = 'q')`; `tools()` returns one `ask_user` `Tool`; `guidelines()` returns terse usage guidance (including: in autonomous loop contexts a `block` question halts the stage until the operator answers).
- The `ask_user` params: `prompt` (string, required), `format` (enum `single_select|multi_select|free_text`, required), `options` (array/JSON, optional — objects `{label, description?}` or bare strings), `suggested` (object/JSON, required — `{selected?: string[], text?: string}`), `header` (string, optional), `allow_other` (bool, optional).
- Callback builds a `QuestionRequest` (generating `id` from `$idPrefix` + a hex suffix via `IdGenerator::hex`), validates params (bad format, select missing options, missing/invalid `suggested`) returning `ToolResult::error(...)` on failure, delegates to `$responder->ask()`, and returns `ToolResult` with the validated answer serialized. A **`null` return** (block mode escalated the loop) → a terminal hard-STOP `ToolResult` (`QUESTION_BLOCKED: STOP IMMEDIATELY …`) instructing the agent to take no further action. It **catches** `QuestionUnansweredException` (API cancel/timeout) → `ToolResult::error('No answer received …')`.

- [ ] **Step 1: Write the failing test** (use a fake responder to isolate the tool)

`tests/Unit/Toolkit/QuestionToolkitTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Toolkit\QuestionToolkit;

function fakeResponder(callable $onAsk): QuestionResponderInterface
{
    return new class($onAsk) implements QuestionResponderInterface {
        /** @var callable */
        private $onAsk;
        public ?QuestionRequest $received = null;
        public function __construct(callable $onAsk) { $this->onAsk = $onAsk; }
        public function ask(QuestionRequest $question): ?QuestionResponse
        {
            $this->received = $question;
            return ($this->onAsk)($question);
        }
    };
}

function askUserTool(QuestionToolkit $kit)
{
    foreach ($kit->tools() as $tool) {
        if ($tool->name() === 'ask_user') {
            return $tool;
        }
    }
    throw new RuntimeException('ask_user tool missing');
}

test('ask_user builds a single-select request and returns the answer', function () {
    $responder = fakeResponder(fn(QuestionRequest $q) => new QuestionResponse(['pear']));
    $tool = askUserTool(new QuestionToolkit($responder));

    $result = $tool->call([
        'prompt' => 'Which fruit?',
        'format' => 'single_select',
        'options' => [['label' => 'apple'], ['label' => 'pear']],
        'suggested' => ['selected' => ['apple']],
    ]);

    expect($responder->received->prompt)->toBe('Which fruit?');
    expect((string) $result)->toContain('pear');
});

test('ask_user rejects a select with no options', function () {
    $tool = askUserTool(new QuestionToolkit(fakeResponder(fn() => new QuestionResponse([]))));

    $result = $tool->call([
        'prompt' => 'Pick', 'format' => 'single_select', 'suggested' => ['selected' => ['a']],
    ]);

    expect((string) $result)->toContain('option');
});

test('ask_user rejects a missing suggested answer', function () {
    $tool = askUserTool(new QuestionToolkit(fakeResponder(fn() => new QuestionResponse([]))));

    $result = $tool->call([
        'prompt' => 'Colours?', 'format' => 'free_text',
    ]);

    expect((string) $result)->toContain('suggested');
});

test('ask_user surfaces a null (blocked) return as a hard-STOP terminal result', function () {
    $responder = fakeResponder(fn(QuestionRequest $q): ?QuestionResponse => null);
    $tool = askUserTool(new QuestionToolkit($responder));

    $result = $tool->call([
        'prompt' => 'Proceed?', 'format' => 'single_select',
        'options' => [['label' => 'yes'], ['label' => 'no']],
        'suggested' => ['selected' => ['yes']],
    ]);

    expect((string) $result)->toContain('QUESTION_BLOCKED');
    expect((string) $result)->toContain('STOP');
});
```

> Verify the exact `Tool` accessor/exec names (`name()`, `call()`/`execute()`, and `ToolResult`'s string cast) against `vendor/carmelosantana/php-agents/src/Tool/Tool.php` and `.../Tool/ToolResult.php` before finalizing; adjust the test's `name()`/`call()` calls to match. `ArtifactToolkit`/`MemoryToolkit` callbacks return `ToolResult` — mirror their return idiom.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Toolkit/QuestionToolkitTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the toolkit** (adjust parameter classes/`ToolResult` factory names to the installed php-agents API — grep `src/Toolkit/MemoryToolkit.php` for the exact imports)

`src/Toolkit/QuestionToolkit.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionUnansweredException;
use CoquiBot\Coqui\Support\IdGenerator;

/**
 * Exposes the single `ask_user` tool. Context-agnostic: it never knows whether
 * it runs in a REPL, an API turn, or a loop — the runtime wires the responder,
 * exactly as it wires the execution policy.
 */
final class QuestionToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly QuestionResponderInterface $responder,
        private readonly string $idPrefix = 'q',
    ) {}

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return [$this->askUserTool()];
    }

    public function guidelines(): string
    {
        return <<<GUIDELINES
        <ASK-USER-GUIDELINES>
        Use `ask_user` to ask the user ONE structured question and get a validated answer.
        - `format`: `single_select` (pick one), `multi_select` (pick zero or more), or `free_text`.
        - Provide `options` for selects (objects `{"label": "...", "description": "..."}` or bare strings). Set `allow_other: true` to let the user type a value not in the list.
        - ALWAYS provide `suggested` — your best-guess default — as `{"selected": ["label"]}` for selects or `{"text": "..."}` for free-text. It pre-selects a default and is auto-taken in non-interactive `default` mode.
        - Yes/no is a two-option `single_select`. Ask sequentially if you need multiple answers (one question per call).
        - In an autonomous loop with `on_question: block`, calling `ask_user` halts the stage until an operator answers; the loop shows as `blocked`.
        </ASK-USER-GUIDELINES>
        GUIDELINES;
    }

    private function askUserTool(): ToolInterface
    {
        return new Tool(
            name: 'ask_user',
            description: 'Ask the user ONE structured question (single-select, multi-select, or free-text) and receive a validated answer. Always include a suggested default.',
            parameters: [
                new StringParameter('prompt', 'The question to ask the user', required: true),
                new EnumParameter('format', 'Answer shape', ['single_select', 'multi_select', 'free_text'], required: true),
                new StringParameter('options', 'JSON array of choices for selects: objects {"label","description"?} or bare strings. Omit for free_text.', required: false),
                new StringParameter('suggested', 'JSON best-guess default: {"selected":["label"]} for selects or {"text":"..."} for free-text. REQUIRED.', required: true),
                new StringParameter('header', 'Short chip label for the question', required: false),
                new BoolParameter('allow_other', 'Selects only: allow a free-text "Other" answer', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->handle($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function handle(array $input): ToolResult
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            return ToolResult::error('ask_user: "prompt" is required.');
        }

        $format = QuestionFormat::tryFrom((string) ($input['format'] ?? ''));
        if ($format === null) {
            return ToolResult::error('ask_user: "format" must be single_select, multi_select, or free_text.');
        }

        $options = $this->parseOptions($input['options'] ?? null);
        if ($format->isSelect() && $options === []) {
            return ToolResult::error('ask_user: select formats require a non-empty "options" array.');
        }
        if (!$format->isSelect() && $options !== []) {
            return ToolResult::error('ask_user: free_text must not include "options".');
        }

        $suggestedRaw = $this->decodeJsonObject($input['suggested'] ?? null);
        if ($suggestedRaw === null) {
            return ToolResult::error('ask_user: "suggested" is required and must be a JSON object.');
        }
        $suggested = QuestionResponse::fromArray($suggestedRaw);

        try {
            $request = new QuestionRequest(
                id: $this->idPrefix . '_' . IdGenerator::hex(6),
                prompt: $prompt,
                format: $format,
                options: $options,
                allowOther: (bool) ($input['allow_other'] ?? false),
                suggested: $suggested,
                header: isset($input['header']) && $input['header'] !== '' ? (string) $input['header'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error('ask_user: ' . $e->getMessage());
        }

        try {
            $answer = $this->responder->ask($request);
        } catch (QuestionUnansweredException $e) {
            return ToolResult::error('ask_user: no answer received (' . $e->getMessage() . ').');
        }

        // null → the question was escalated to an operator (loop block mode).
        // Return a hard STOP: the stage process keeps running until the turn
        // ends, so the agent must take NO further action — any work it does now
        // is discarded when the stage is re-run with the operator's answer.
        if ($answer === null) {
            return ToolResult::success(
                'QUESTION_BLOCKED: STOP IMMEDIATELY. Your question has been escalated to the operator and this '
                . 'loop stage is now BLOCKED awaiting their answer. Do NOT call any more tools, write any files, '
                . 'or take any further action. End your turn now with no further output. This stage will be '
                . 're-run from the start with the operator\'s answer once they respond; anything you do now is discarded.',
            );
        }

        return ToolResult::success(json_encode([
            'answered' => true,
            'selected' => $answer->selected,
            'text' => $answer->text,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<QuestionOption>
     */
    private function parseOptions(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $options = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) && $entry !== '') {
                $options[] = new QuestionOption($entry);
            } elseif (is_array($entry) && isset($entry['label'])) {
                $options[] = QuestionOption::fromArray($entry);
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Toolkit/QuestionToolkitTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Toolkit/QuestionToolkit.php tests/Unit/Toolkit/QuestionToolkitTest.php
git commit -m "feat(questions): QuestionToolkit exposing the context-agnostic ask_user tool"
```

---

## Task 7: Responder wiring (dependencies → agent → toolkit → 3 entry points)

**Files:**
- Modify: `src/Agent/OrchestratorDependencies.php` (add `public ?QuestionResponderInterface $questionResponder = null;` near the `executionPolicy` field at `:57`)
- Modify: `src/Agent/OrchestratorAgent.php` (store `$deps->questionResponder`; construct `QuestionToolkit` after the LoopToolkit block ~`:526`, guarded on non-null responder; register via `addSystemToolkit`)
- Modify: `src/Agent/AgentRunner.php` (add `?QuestionResponderInterface $questionResponder = null` to `run`, `runForTask`, `runWithObserver`, `runSegment`, `doRun`, `executeSegment`, `createAgent`; forward into `OrchestratorDependencies` at `:958`)
- Modify: `src/Command/RunCommand.php` (build `InteractiveQuestionResponder` on the interactive path; pass `null` on the non-interactive path)
- Modify: `src/Command/TurnRunCommand.php` (build `SuspendingQuestionResponder` at `:146`, pass into `runWithObserver`/`runSegment`)
- Modify: `src/Command/TaskRunCommand.php` (build `PolicyQuestionResponder` at `:164`, pass into `runForTask`)
- Test: `tests/Unit/Agent/QuestionResponderWiringTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–6.
- Produces: an agent that, when a `QuestionResponderInterface` is supplied, includes an `ask_user` tool; when none is supplied, does not (back-compat).

- [ ] **Step 1: Write the failing test** (assert the toolkit wiring: with a responder the agent exposes `ask_user`; without one it does not)

`tests/Unit/Agent/QuestionResponderWiringTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Toolkit\QuestionToolkit;

test('QuestionToolkit exposes exactly one ask_user tool', function () {
    $responder = new class implements QuestionResponderInterface {
        public function ask(QuestionRequest $q): QuestionResponse { return $q->suggested; }
    };
    $kit = new QuestionToolkit($responder);
    $names = array_map(fn($t) => $t->name(), $kit->tools());
    expect($names)->toBe(['ask_user']);
});
```

> The full end-to-end wiring (each entry point injecting the right responder) is covered by the integration tests in Tasks 8 and 9. This unit test locks the toolkit contract; the wiring edits below are verified by `composer analyse` (signature consistency) + the Task 8/9 integration runs.

- [ ] **Step 2: Run the test to verify it fails/passes appropriately**

Run: `./vendor/bin/pest tests/Unit/Agent/QuestionResponderWiringTest.php`
Expected: PASS for the toolkit contract test (it only needs Task 6). Proceed to wire the plumbing.

- [ ] **Step 3: Add the dependency field**

In `src/Agent/OrchestratorDependencies.php`, next to the `executionPolicy` property (~`:57`), add (and add the `use CoquiBot\Coqui\Contract\QuestionResponderInterface;` import):

```php
    public ?QuestionResponderInterface $questionResponder = null,
```

(Match the existing promoted-constructor-parameter style of that file — if the deps are constructor-promoted, add the parameter in the same block with a `= null` default so all existing call sites remain valid.)

- [ ] **Step 4: Construct the toolkit in `OrchestratorAgent`**

In `src/Agent/OrchestratorAgent.php`, add near the other `$deps->` reads (~`:259`):

```php
        $questionResponder = $deps->questionResponder;
```

Then, immediately after the LoopToolkit registration block (~`:526`), add:

```php
        if ($questionResponder !== null) {
            $this->addSystemToolkit(new \CoquiBot\Coqui\Toolkit\QuestionToolkit($questionResponder));
        }
```

(Use the same `addSystemToolkit(...)` call the neighboring toolkits use; if they pass an eager/deferred flag, match LoopToolkit's call exactly.)

- [ ] **Step 5: Thread the parameter through `AgentRunner`**

For each of `run()`, `runForTask()`, `runWithObserver()`, `runSegment()`, `doRun()`, `executeSegment()`, and `createAgent()` in `src/Agent/AgentRunner.php`, add a trailing optional parameter `?QuestionResponderInterface $questionResponder = null` (import the interface at the top). In `doRun()`/`executeSegment()` pass it into `createAgent(...)`; in `createAgent()` forward it into the `new OrchestratorDependencies(...)` call (~`:958`) as `questionResponder: $questionResponder`. Public run methods pass it straight through to `doRun`/`executeSegment`. Keep every new parameter last with a `= null` default so existing callers compile unchanged.

- [ ] **Step 6: Build the responder at each entry point**

REPL — `src/Command/RunCommand.php`, interactive path (where `buildInteractive()` is used, ~`:370-384`): after resolving `$io` and per-turn `$sessionId`/`$turnId`, construct:

```php
$questionResponder = new \CoquiBot\Coqui\Question\InteractiveQuestionResponder(
    $io,
    new \CoquiBot\Coqui\Question\QuestionPersistence($storage),
    $sessionId,
    $turnId,
);
```

and pass `$questionResponder` into the `agentRunner->run(...)`/turn-executor call alongside `$executionPolicy`. Non-interactive path (`:766-775`): pass `questionResponder: null`.

API turn — `src/Command/TurnRunCommand.php` after the policy at `:150`:

```php
$questionResponder = new \CoquiBot\Coqui\Question\SuspendingQuestionResponder(
    new \CoquiBot\Coqui\Question\QuestionPersistence($storage),
    $storage,
    $sessionId,
    $turnProcessId,
);
```

Pass `questionResponder: $questionResponder` into `runWithObserver(...)` (`:205`) and `runSegment(...)` (`:190`).

Background/loop — `src/Command/TaskRunCommand.php` after the policy at `:168`. Resolve `on_question` + loop/stage context from the task's handoff metadata (loop stages carry `LoopStageHandoffMetadata` in `metadata`; `loop_id`/`stage_id` live there — read the exact keys from `src/Contract/LoopStageHandoffMetadata.php::toArray()`), and load the loop's `on_question` from its stored configuration via `LoopStore`:

```php
$onQuestion = \CoquiBot\Coqui\Contract\OnQuestionPolicy::Block;
$loopId = null; $stageId = null; $loopBlock = null;
$meta = is_string($task['metadata'] ?? null) ? json_decode($task['metadata'], true) : null;
if (is_array($meta) && isset($meta['loop_id'])) {
    $loopId = (string) $meta['loop_id'];
    $stageId = isset($meta['stage_id']) ? (string) $meta['stage_id'] : null;
    $loopStore = new \CoquiBot\Coqui\Storage\LoopStore($storage->getPdo());
    $loopBlock = new \CoquiBot\Coqui\Question\LoopQuestionBlockNotifier($loopStore); // Task 9
    $loopRow = $loopStore->getLoop($loopId);
    $config = is_array($loopRow) && is_string($loopRow['configuration'] ?? null)
        ? json_decode($loopRow['configuration'], true) : null;
    $onQuestion = \CoquiBot\Coqui\Contract\OnQuestionPolicy::fromString(
        is_array($config) ? ($config['on_question'] ?? null) : null,
    );
}
$questionResponder = new \CoquiBot\Coqui\Question\PolicyQuestionResponder(
    $onQuestion,
    new \CoquiBot\Coqui\Question\QuestionPersistence($storage),
    $sessionId,
    $loopBlock,
    turnId: null,
    loopId: $loopId,
    stageId: $stageId,
);
```

Pass `questionResponder: $questionResponder` into `runForTask(...)` (`:176`). (Confirm the `LoopStore` constructor signature — recon shows `new LoopStore($pdo, ...)` at `OrchestratorAgent.php:510`; match it.)

- [ ] **Step 7: Run the analyser + the full toolkit/wiring tests**

Run: `composer analyse` — Expected: zero errors (this proves every `AgentRunner`/deps signature stayed consistent).
Run: `./vendor/bin/pest tests/Unit/Agent/QuestionResponderWiringTest.php tests/Unit/Toolkit/QuestionToolkitTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Agent/OrchestratorDependencies.php src/Agent/OrchestratorAgent.php src/Agent/AgentRunner.php src/Command/RunCommand.php src/Command/TurnRunCommand.php src/Command/TaskRunCommand.php tests/Unit/Agent/QuestionResponderWiringTest.php
git commit -m "feat(questions): wire QuestionResponderInterface from all 3 entry points into ask_user"
```

---

## Task 8: REST `QuestionHandler` (GET pending, POST answer) + routes + SSE `question` event

**Files:**
- Create: `src/Api/Handler/QuestionHandler.php`
- Modify: `src/Command/ApiCommand.php` (construct the handler at ~`:298-355`; add it to the `registerRoutes(...)` signature `:553` + call site `:360`; register the two routes in the "Sessions" block after `:596`)
- Modify: `src/Api/Handler/MessageHandler.php` (only if its SSE poller allowlists event types — forward the `question` type; verify at `:192-215`)
- Test: `tests/Integration/Api/QuestionHandlerTest.php`

**Interfaces:**
- Consumes: `QuestionPersistence`, `QuestionResponse`, `SessionStorage`, the `DecodesRequestBody` trait (see `ArtifactHandler.php:30`), and a reopen hook `QuestionAnswerReopener` from Task 9 (nullable — `null` for non-loop questions).
- Produces `final class QuestionHandler` with:
  - `__construct(QuestionPersistence $persistence, SessionStorage $storage, ?\CoquiBot\Coqui\Api\QuestionAnswerReopener $reopener = null)`
  - `list(ServerRequestInterface $request, string $id): ResponseInterface` — 200 `{ questions: QuestionRecord[] }` for session `$id` (pending only).
  - `answer(ServerRequestInterface $request, string $id, string $questionId): ResponseInterface` — body `{ selected?: string[], text?: string }`; 404 if unknown/wrong session; 409 if already answered; 422 if `!isValidFor`; on success persist the answer and, if the question carries a `loop_id`, invoke the reopener; return 200 `{ answered: true }`.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Api/QuestionHandlerTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\QuestionHandler;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;

function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
{
    return (new ServerRequest($method, $path, ['Content-Type' => 'application/json'], json_encode($body)));
}

test('GET questions lists pending questions for the session', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->list(jsonRequest('GET', "/sessions/{$sessionId}/questions"), $sessionId);
    $payload = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect(count($payload['questions']))->toBe(1);
    expect($payload['questions'][0]['id'])->toBe('q1');
});

test('POST answer with a valid answer resolves the question', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->answer(
        jsonRequest('POST', "/sessions/{$sessionId}/questions/q1/answer", ['selected' => ['pear']]),
        $sessionId,
        'q1',
    );

    expect($response->getStatusCode())->toBe(200);
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});

test('POST answer with an invalid answer returns 422 and stays pending', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->answer(
        jsonRequest('POST', "/sessions/{$sessionId}/questions/q1/answer", ['selected' => ['not-an-option']]),
        $sessionId,
        'q1',
    );

    expect($response->getStatusCode())->toBe(422);
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
});

test('POST answer to an already-answered question returns 409', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);
    $handler->answer(jsonRequest('POST', '/', ['selected' => ['pear']]), $sessionId, 'q1');

    $second = $handler->answer(jsonRequest('POST', '/', ['selected' => ['apple']]), $sessionId, 'q1');
    expect($second->getStatusCode())->toBe(409);
});
```

> Confirm the response class the other handlers use (`React\Http\Message\Response` — see `SseStream::response()` and `ArtifactHandler`) and how they read the JSON body (the `DecodesRequestBody` trait). Match those exactly; adjust `jsonRequest` if the repo has a request test helper under `tests/Support/`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Integration/Api/QuestionHandlerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the handler**

`src/Api/Handler/QuestionHandler.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\QuestionAnswerReopener;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Core authenticated REST surface for structured questions.
 *   GET  /api/v1/sessions/{id}/questions
 *   POST /api/v1/sessions/{id}/questions/{questionId}/answer
 */
final class QuestionHandler
{
    public function __construct(
        private readonly QuestionPersistence $persistence,
        private readonly SessionStorage $storage,
        private readonly ?QuestionAnswerReopener $reopener = null,
    ) {}

    public function list(ServerRequestInterface $request, string $id): ResponseInterface
    {
        $questions = array_map(
            static fn($record): array => $record->toArray(),
            $this->persistence->pending($id),
        );

        return $this->json(200, ['questions' => array_values($questions)]);
    }

    public function answer(ServerRequestInterface $request, string $id, string $questionId): ResponseInterface
    {
        $record = $this->persistence->find($questionId);
        if ($record === null || $record->sessionId !== $id) {
            return $this->json(404, ['error' => 'Question not found']);
        }
        if ($record->status !== 'pending') {
            return $this->json(409, ['error' => 'Question already answered']);
        }

        $body = json_decode((string) $request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $answer = QuestionResponse::fromArray($body);

        if (!$answer->isValidFor($record->request)) {
            return $this->json(422, ['error' => 'Answer is not valid for this question']);
        }

        $ok = $this->persistence->persistAnswered($questionId, $id, $record->request, $answer);
        if (!$ok) {
            return $this->json(409, ['error' => 'Question could not be answered']);
        }

        if ($record->loopId !== null && $this->reopener !== null) {
            $this->reopener->reopen($record->loopId, $record->stageId, $record->request, $answer);
        }

        return $this->json(200, ['answered' => true]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }
}
```

> `QuestionAnswerReopener` is created in Task 9. To keep Task 8 independently green, define the interface stub now in `src/Api/QuestionAnswerReopener.php`:
>
> ```php
> <?php
> declare(strict_types=1);
> namespace CoquiBot\Coqui\Api;
> use CoquiBot\Coqui\Contract\QuestionRequest;
> use CoquiBot\Coqui\Contract\QuestionResponse;
> interface QuestionAnswerReopener {
>     public function reopen(string $loopId, ?string $stageId, QuestionRequest $question, QuestionResponse $answer): void;
> }
> ```

- [ ] **Step 4: Run the handler test to verify it passes**

Run: `./vendor/bin/pest tests/Integration/Api/QuestionHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: Register the handler + routes in `ApiCommand`**

Construct the handler alongside the others (~`:298-355`), reusing the same `$persistence`/`$storage`/loop dependencies:

```php
$questionHandler = new \CoquiBot\Coqui\Api\Handler\QuestionHandler(
    new \CoquiBot\Coqui\Question\QuestionPersistence($storage),
    $storage,
    $questionAnswerReopener, // built in Task 9; pass null until then
);
```

Add `\CoquiBot\Coqui\Api\Handler\QuestionHandler $questionHandler` to the `registerRoutes(...)` parameter list (`:553`) and pass `$questionHandler` at the call site (`:360`). In the Sessions block of `registerRoutes` (after `:596`), add the CORE authenticated routes (NOT `addPublicRoute`):

```php
        $router->get($v1 . '/sessions/{id}/questions', [$questionHandler, 'list']);
        $router->post($v1 . '/sessions/{id}/questions/{questionId}/answer', [$questionHandler, 'answer']);
```

- [ ] **Step 6: Verify the SSE `question` event passes through**

Read `src/Api/Handler/MessageHandler.php:192-215`. The poller forwards every `turn_events` row via `writeSseEvent`. If it filters by an allowlist of `event_type`s, add `'question'`. If it forwards all types unchanged (expected), no edit is needed — add a one-line comment noting `question` is a recognized turn-event type so future readers know.

- [ ] **Step 7: Run the analyser + tests**

Run: `composer analyse` — Expected: zero errors.
Run: `./vendor/bin/pest tests/Integration/Api/QuestionHandlerTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Api/Handler/QuestionHandler.php src/Api/QuestionAnswerReopener.php src/Command/ApiCommand.php src/Api/Handler/MessageHandler.php tests/Integration/Api/QuestionHandlerTest.php
git commit -m "feat(questions): REST QuestionHandler (GET pending / POST answer) + routes + SSE question event"
```

---

## Task 9: Loop integration — `on_question` field, block escalation, answer-driven reopen

**Files:**
- Modify: `src/Contract/LoopDefinition.php` (add `on_question` — `OnQuestionPolicy`, default `Block` — to the constructor, `fromArray`, `toArray`)
- Create: `src/Question/LoopQuestionBlockNotifier.php` (implements `LoopBlockNotifier` over `LoopStore`)
- Create: `src/Api/LoopQuestionAnswerReopener.php` (implements `QuestionAnswerReopener`; reuses the #3 retry machinery)
- Modify: `src/Agent/LoopExecutor.php` (inject an `## Answer to Your Earlier Question` section in `prepareNextStage()`, mirroring `pending_guidance`)
- Test: `tests/Unit/Contract/LoopDefinitionTest.php` (extend), `tests/Integration/Loop/LoopQuestionFlowTest.php`

**Interfaces:**
- `LoopDefinition` gains `public OnQuestionPolicy $onQuestion` (default `OnQuestionPolicy::Block`); `toArray` emits `'on_question' => $this->onQuestion->value`; `fromArray` reads it via `OnQuestionPolicy::fromString($data['on_question'] ?? null)`.
- `LoopQuestionBlockNotifier::block(string $loopId, ?string $stageId, QuestionRequest $q)`: writes `metadata.escalation = { reason, question: $q->toArray(), at }` and flips the loop to `blocked` + the latest iteration to `needs_rework` (reuse `LoopStore::updateLoopMetadata` / `updateLoopStatus` / `updateIterationStatus` — the same writes `LoopExecutor::escalateBlocked()` makes at `src/Agent/LoopExecutor.php:801-813`).
- `LoopQuestionAnswerReopener::reopen(loopId, stageId, question, answer)`: writes the answer into loop metadata as `pending_answer = { question: ..., answer: ... }` (and clears `escalation`), then performs the #3 reopen: `resetStagesForIteration` + `resetIterationForRetry` + `updateLoopStatus(running)` + `updateLoopProgress(iteration, 0)` for the latest iteration — the exact sequence in `LoopHandler::retryIteration()` (`src/Api/Handler/LoopHandler.php:904-918`). Extract that sequence into a shared method if practical; otherwise replicate it.
- `LoopExecutor::prepareNextStage()`: after the `pending_guidance` handling (~`:242-269`), read `metadata.pending_answer`; if present, append to `$sections` an `## Answer to Your Earlier Question` block built from the stored question prompt + the answer (labels joined, or the free text), then clear `pending_answer` (one-shot, like guidance).

- [ ] **Step 1: Write the failing tests**

Extend `tests/Unit/Contract/LoopDefinitionTest.php` with:

```php
test('on_question defaults to block and round-trips', function () {
    $def = \CoquiBot\Coqui\Contract\LoopDefinition::fromArray([
        'name' => 'demo',
        'description' => 'demo loop',
        'roles' => [['role' => 'coder', 'prompt' => 'do it']],
        'termination_condition' => ['type' => 'iteration_bound', 'max_iterations' => 1],
    ]);
    expect($def->onQuestion)->toBe(\CoquiBot\Coqui\Contract\OnQuestionPolicy::Block);
    expect($def->toArray()['on_question'])->toBe('block');

    $withDefault = \CoquiBot\Coqui\Contract\LoopDefinition::fromArray(
        ['on_question' => 'default'] + $def->toArray(),
    );
    expect($withDefault->onQuestion)->toBe(\CoquiBot\Coqui\Contract\OnQuestionPolicy::DefaultAnswer);
});
```

`tests/Integration/Loop/LoopQuestionFlowTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopQuestionAnswerReopener;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\LoopQuestionBlockNotifier;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

// Helper: create a running loop with one iteration + one stage.
// (Use the LoopStore API the same way tests/Unit/... or tests/Integration/Loop existing tests do —
//  grep an existing LoopStore-based test to reuse its loop-bootstrap helper.)

test('block notifier flips the loop to blocked with the question as escalation', function () {
    $storage = new SessionStorage(':memory:');
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = bootstrapRunningLoop($loopStore);           // helper reused from existing loop tests
    $notifier = new LoopQuestionBlockNotifier($loopStore);

    $notifier->block($loopId, null, sampleRequest());

    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('blocked');
    $meta = json_decode($loop['metadata'], true);
    expect($meta['escalation']['question']['id'])->toBe('q1');
});

test('answer reopener unblocks the loop and stages the answer for injection', function () {
    $storage = new SessionStorage(':memory:');
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = bootstrapRunningLoop($loopStore);
    (new LoopQuestionBlockNotifier($loopStore))->block($loopId, null, sampleRequest());

    $reopener = new LoopQuestionAnswerReopener($loopStore);
    $reopener->reopen($loopId, null, sampleRequest(), new QuestionResponse(['pear']));

    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');
    $meta = json_decode($loop['metadata'], true);
    expect($meta['pending_answer']['answer']['selected'])->toBe(['pear']);
});
```

> `bootstrapRunningLoop()` and `sampleRequest()`: reuse the loop-bootstrap helper from the existing loop test suite (grep `tests/Integration/Loop` / `tests/Unit/Agent/LoopExecutorTest*` for how they create a loop + iteration + stage via `LoopStore`), and put `bootstrapRunningLoop` in `tests/Support/`. Confirm `LoopStore`'s constructor + `getLoop`/`updateLoopMetadata`/`updateLoopStatus`/`resetStagesForIteration`/`resetIterationForRetry`/`updateLoopProgress` signatures before writing the implementations.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Contract/LoopDefinitionTest.php tests/Integration/Loop/LoopQuestionFlowTest.php`
Expected: FAIL — `onQuestion`/classes not found.

- [ ] **Step 3: Add `on_question` to `LoopDefinition`**

In `src/Contract/LoopDefinition.php`: import `OnQuestionPolicy`; add `public OnQuestionPolicy $onQuestion = OnQuestionPolicy::Block` as the last constructor parameter; in `fromArray()` pass `onQuestion: OnQuestionPolicy::fromString($data['on_question'] ?? null)`; in `toArray()` add `'on_question' => $this->onQuestion->value` (place it before the optional `parameters` key).

- [ ] **Step 4: Implement the block notifier**

`src/Question/LoopQuestionBlockNotifier.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\LoopBlockNotifier;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Support\Clock;

/**
 * Escalates a loop to `blocked` with a QuestionRequest as the escalation
 * payload — mirroring LoopExecutor::escalateBlocked() so the existing
 * blocked-loop UI + notifications surface it.
 */
final class LoopQuestionBlockNotifier implements LoopBlockNotifier
{
    public function __construct(private readonly LoopStore $loopStore) {}

    public function block(string $loopId, ?string $stageId, QuestionRequest $question): void
    {
        $this->loopStore->updateLoopMetadata($loopId, [
            'escalation' => [
                'reason' => 'Agent asked the operator a question: ' . $question->prompt,
                'question' => $question->toArray(),
                'at' => Clock::nowUtc(),
            ],
        ]);
        $state = $this->loopStore->getCurrentState($loopId);
        if (is_array($state) && is_array($state['iteration'] ?? null)) {
            $this->loopStore->updateIterationStatus((string) $state['iteration']['id'], 'needs_rework', 'Blocked awaiting an answer');
        }
        $this->loopStore->updateLoopStatus($loopId, 'blocked');
    }
}
```

- [ ] **Step 5: Implement the answer reopener**

`src/Api/LoopQuestionAnswerReopener.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Support\Clock;

/**
 * On an operator answer to a block-mode loop question, reopens the current
 * iteration exactly like a #3 retry, staging the answer for injection into the
 * reopened stage prompt (LoopExecutor::prepareNextStage reads pending_answer).
 */
final class LoopQuestionAnswerReopener implements QuestionAnswerReopener
{
    public function __construct(private readonly LoopStore $loopStore) {}

    public function reopen(string $loopId, ?string $stageId, QuestionRequest $question, QuestionResponse $answer): void
    {
        $state = $this->loopStore->getCurrentState($loopId);
        if (!is_array($state) || !is_array($state['iteration'] ?? null)) {
            return;
        }
        $iterationId = (string) $state['iteration']['id'];
        $iterationNumber = (int) $state['iteration']['iteration_number'];

        $this->loopStore->updateLoopMetadata($loopId, [
            'escalation' => null,
            'rework_attempts' => 0,
            'pending_answer' => [
                'question' => $question->toArray(),
                'answer' => $answer->toArray(),
                'at' => Clock::nowUtc(),
            ],
        ]);

        // Reopen the iteration — the #3 retry sequence (LoopHandler::retryIteration).
        $this->loopStore->resetStagesForIteration($iterationId);
        $this->loopStore->resetIterationForRetry($iterationId);
        $this->loopStore->updateLoopStatus($loopId, 'running');
        $this->loopStore->updateLoopProgress($loopId, $iterationNumber, 0);
    }
}
```

> If `LoopStore` lacks `resetStagesForIteration`/`resetIterationForRetry`, they exist behind `LoopHandler::retryIteration()` — locate the exact store methods it calls (recon: `LoopHandler.php:904-905`) and use those names. Do not duplicate reset logic; call the same store methods.

- [ ] **Step 6: Inject the answer in `LoopExecutor::prepareNextStage`**

In `src/Agent/LoopExecutor.php`, after the `pending_guidance` extraction/clear (~`:242-269`) and before/near the `pendingGuidance` section append (~`:932-934`), read and inject `pending_answer`:

```php
        // One-shot: inject the operator's answer to a blocked-question into the
        // reopened stage, then clear it so it does not leak into later stages.
        $pendingAnswer = null;
        if (is_string($loop['metadata'] ?? null) && $loop['metadata'] !== '') {
            $meta = json_decode($loop['metadata'], true);
            if (is_array($meta) && is_array($meta['pending_answer'] ?? null)) {
                $pendingAnswer = $meta['pending_answer'];
            }
        }
```

and where sections are assembled (alongside the `## Operator Guidance` block ~`:932-934`), add:

```php
        if ($pendingAnswer !== null) {
            $q = $pendingAnswer['question'] ?? [];
            $a = $pendingAnswer['answer'] ?? [];
            $chosen = $a['text'] ?? implode(', ', $a['selected'] ?? []);
            $sections[] = "## Answer to Your Earlier Question\n"
                . "You asked: " . (string) ($q['prompt'] ?? '') . "\n"
                . "The operator answered: " . (string) $chosen;
            $this->loopStore->updateLoopMetadata($loopId, ['pending_answer' => null]);
        }
```

(Thread `$pendingAnswer` into `buildStagePrompt(...)` the same way `$pendingGuidance` is threaded, OR — simpler — append the section to the returned prompt string in `prepareNextStage` after `buildStagePrompt` returns, mirroring wherever guidance is injected. Match the file's existing structure exactly.)

- [ ] **Step 7: Wire the reopener into `ApiCommand`**

In `src/Command/ApiCommand.php`, construct `$questionAnswerReopener = new \CoquiBot\Coqui\Api\LoopQuestionAnswerReopener($loopStore);` (reuse the `LoopStore` already built for `LoopHandler`) and pass it into the `QuestionHandler` constructor from Task 8, Step 5 (replace the `null` placeholder).

- [ ] **Step 8: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Contract/LoopDefinitionTest.php tests/Integration/Loop/LoopQuestionFlowTest.php`
Expected: PASS.

- [ ] **Step 9: Run the full suite + analyser**

Run: `composer test`
Expected: fully green.
Run: `composer analyse`
Expected: zero errors.

- [ ] **Step 10: Commit**

```bash
git add src/Contract/LoopDefinition.php src/Question/LoopQuestionBlockNotifier.php src/Api/LoopQuestionAnswerReopener.php src/Agent/LoopExecutor.php src/Command/ApiCommand.php tests/Unit/Contract/LoopDefinitionTest.php tests/Integration/Loop/LoopQuestionFlowTest.php
git commit -m "feat(questions): loop on_question policy + block escalation + answer-driven reopen"
```

---

## Task 10: Documentation + source map

**Files:**
- Create: `docs/QUESTIONS.md`
- Modify: `docs/API.md` (the two new endpoints + the `question` SSE event)
- Modify: `docs/LOOPS.md` (the `on_question` field: `block` | `default`, default `block`)
- Modify: `AGENTS.md` (add `docs/QUESTIONS.md` to the "Read This First" list)
- Modify: `config/source.json` (add the new `src/Contract/*`, `src/Question/*`, `src/Toolkit/QuestionToolkit.php`, `src/Api/Handler/QuestionHandler.php`, `src/Api/LoopQuestionAnswerReopener.php`, `src/Api/QuestionAnswerReopener.php` entries)
- **Do NOT touch `config/documentation.json`** (generated + git-ignored).

- [ ] **Step 1: Write `docs/QUESTIONS.md`**

Cover: what structured questions are; the three formats + `allow_other`; the `ask_user` tool params + a worked example (single-select yes/no, multi-select, free-text); the three responders and when each is active (REPL prompt / API suspend-and-ask / loop-or-background policy); `suggested` semantics; the `on_question` loop policy (`block` default vs `default`); how block-mode surfaces (loop `blocked`, GET `.../questions`, POST `.../answer`); audit-log actions (`question_asked` / `question_answered`); and the v1 limits (one question per call; no batching; no role-level override; no extra formats). Keep it concise and link to `docs/API.md` and `docs/LOOPS.md` per the docs policy (link, don't restate).

- [ ] **Step 2: Update `docs/API.md`**

Document:

```
GET  /api/v1/sessions/{id}/questions            → { questions: QuestionRecord[] }  (pending only; also streamed live via the SSE `question` event)
POST /api/v1/sessions/{id}/questions/{questionId}/answer
     body { selected?: string[], text?: string }
     200 { answered: true } | 404 unknown | 409 already answered | 422 invalid-for-question
```

Note both are CORE authenticated routes and that answering a block-mode loop question reopens the blocked stage. Add `question` to the list of SSE event types emitted on the turn stream.

- [ ] **Step 3: Update `docs/LOOPS.md`**

Document the new loop-definition field:

```
"on_question": "block" | "default"   // default: "block"
```

`block` → a stage's `ask_user` call escalates the loop to `blocked` with the question as the escalation payload; an operator answers via `POST /api/v1/sessions/{id}/questions/{questionId}/answer` and the stage reopens with the answer injected. `default` → the agent's `suggested` answer is auto-taken and the stage continues (logged). Role-level override is not supported in v1.

- [ ] **Step 4: Update `config/source.json`**

Add entries for every new source file (mirror the shape of existing entries — path + responsibility summary). Include: the six `src/Contract/Question*`/`OnQuestionPolicy`/`LoopBlockNotifier` files, the `src/Question/*` responders + `QuestionPersistence` + exceptions + `LoopQuestionBlockNotifier`, `src/Toolkit/QuestionToolkit.php`, `src/Api/Handler/QuestionHandler.php`, `src/Api/QuestionAnswerReopener.php`, `src/Api/LoopQuestionAnswerReopener.php`. Also note the `on_question` addition to `LoopDefinition` if entries track fields.

- [ ] **Step 5: Validate docs (links + commands) and regenerate the index locally**

Run: `composer regen-docs` (regenerates the git-ignored `config/documentation.json`; do not stage it).
Manually confirm every command/route/field named in the new docs matches the code.

- [ ] **Step 6: Commit**

```bash
git add docs/QUESTIONS.md docs/API.md docs/LOOPS.md AGENTS.md config/source.json
git commit -m "docs(questions): QUESTIONS guide + API/LOOPS updates + source map"
```

---

## Final Verification (before handing back)

- [ ] Run the full suite: `composer test` — must be **fully green**.
- [ ] Run the analyser: `composer analyse` — must report **zero errors**.
- [ ] Confirm `git status` does not include `config/documentation.json`.
- [ ] Confirm back-compat: existing loops/turns without `ask_user` are unaffected (no responder wired ⇒ no `ask_user` tool); a non-interactive path with no wired responder is impossible to reach for `ask_user` (the tool only exists when a responder is present), and a loop with no `on_question` defaults to `block`.
- [ ] Push the branch: `git push origin feat/structured-questions`. **Leave the PR to the user — do NOT open or merge.**

---

## Self-Review Notes (author → reviewer)

**Spec coverage map:**
- Shared representation + `isValidFor` → Task 1. Storage/audit → Task 2. Interactive/Suspending/Policy responders → Tasks 3/5/4. `ask_user` tool → Task 6. Responder wiring mirroring `ExecutionPolicyFactory` → Task 7. REST GET/POST + SSE `question` → Task 8. Loop `on_question` + block/default + reopen → Task 9. Docs + source map → Task 10. Audit rows → Task 2 (`QuestionPersistence`), exercised in Tasks 2/8.
- **§4 unknown → resolved** in "Resolved Design Decision": loop stages are discrete task processes ⇒ block mode = reopen-à-la-#3-retry (durable), API turns block-poll inline.

**Deviations from the spec's literal wording (flagged for review):**
1. The spec says `SuspendingQuestionResponder` uses `Fiber::suspend`. The code has no live fiber-suspension path; interactive API turns run as `turn:run` child processes. The plan implements "suspend-and-ask" as **persist + emit `question` turn-event + block-poll the DB in the child process**, which yields the same behavior (turn awaits a validated answer, streamed over SSE) and reuses the existing out-of-process + DB-poll substrate. Class name kept as the spec specifies.
2. Block mode uses a **nullable `ask()` return as the sentinel** (no exception): `PolicyQuestionResponder` escalates the loop and returns `null`; the `ask_user` tool maps `null` → a hard-STOP `ToolResult`. `QuestionResponderInterface::ask()` is therefore `?QuestionResponse`. This keeps `ask()` from fabricating an answer, avoids `AgentRunner`'s catch-all entirely, and (per review) is cleaner than a control exception. The hard-STOP sentinel explicitly instructs the agent to take no further action, because the stage process keeps running until the turn ends and its remaining output is discarded on reopen.
3. `ask_user`'s `options`/`suggested` are accepted as JSON (string or array) because a dedicated `ArrayParameter` may not exist in the installed php-agents; the plan says to confirm and simplify to a native array param if one is available.

**Open confirmations the implementer must make against vendored php-agents / repo idioms (called out inline in the tasks):** exact `Tool`/`ToolResult`/parameter-class API (Task 6), `SymfonyStyle` scripted-input idiom (Task 3), `LoopStore` reset-method names (Task 9), `ServerRequest`/`Response` + body-decoding idiom (Task 8), and Pest shared-helper placement (Task 2).
