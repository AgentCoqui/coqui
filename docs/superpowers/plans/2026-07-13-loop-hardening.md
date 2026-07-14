# Loop Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden Coqui's loop engine with a machine-readable stage verdict, a deterministic two-verdict gate with a rework circuit-breaker that escalates to a new `blocked` state, restart-safe orphan recovery, and artifact-required stages — all chat/API-first.

**Architecture:** `LoopExecutor` becomes the single verdict/decision locus. Gate stages are judged by a new `StageGateEvaluator` (a utility-LLM pass mirroring `GoalEvaluator`); non-gate producer stages self-signal cheaply (no LLM). The controller maps verdicts to `IterationOutcome` (adding `Blocked`), wiring the already-present `needs_rework`/reset/reopen scaffold. `LoopManager` gains tick-skips-blocked, orphan recovery, and blocked notifications. Unblock reuses the existing REST `/retry` path plus a new `loop_control(retry, note?)` action.

**Tech Stack:** PHP 8.4 (strict types, `final`, readonly value objects, enums), SQLite via PDO, Pest tests, ReactPHP (API server loop), `carmelosantana/php-agents` provider interfaces.

## Global Constraints

- `declare(strict_types=1);` in every PHP file; `final` classes by default; one class per file; 4-space indent.
- Value objects are `final readonly`; control-flow states are enums (backed by lowercase strings).
- No new dependencies. No new REST API *program* — enrich existing endpoints/responses only.
- **One** schema migration only: `loop_stages.verdict TEXT`, added via `SchemaHelper::addColumnIfMissing`. All other new state (`rework_attempts`, `escalation`, `pending_guidance`, `dispatch_attempts`) lives in existing `metadata` JSON.
- **Gate-only judging:** `StageGateEvaluator` runs only on gate stages. Non-gate stages default to `Done` and MAY self-signal `Blocked`/`NeedsContext` via a cheap sentinel scan. Do **not** build a per-stage opt-in judge flag now (leave the seam: producer stages route through the same verdict plumbing).
- **`artifact_required` = HARD gate** (structural `loop_stages.artifact_id` presence). **`memory_required` = SOFT** — a `Minor` finding only, never blocks. Do **not** fabricate a memory-pointer link (#156 is convention-only; there is no queryable pointer keyed to an artifact id).
- **Reactor discipline:** the gate evaluator's utility call is invoked synchronously inside `LoopExecutor::evaluateIteration`, exactly the way `GoalEvaluator` is already invoked there. Introduce no new blocking pattern on the ReactPHP loop.
- **`blocked` is retryable, not terminal:** `updateLoopStatus('blocked')` must NOT set `completed_at` (it is not in the terminal list — verify this stays true).
- Every status consumer must learn `blocked`: `LoopHandler` list/get/live serializers, REPL `/loops` list + status display, and the loop notification kind→actionable mapping. These are explicit tasks, not implicit consequences.
- Tests are Pest functional style (`test('...', function () { ... })`), mirroring `tests/Unit/Agent/GoalEvaluatorTest.php`.
- Validation gates for DoD: `composer test` AND `composer analyse` green; `docs/LOOPS.md` + `config/source.json` updated.

## Shared Interfaces (canonical signatures — every task must match these exactly)

```php
// Contract/StageStatus.php
enum StageStatus: string {
    case Done = 'done';
    case DoneWithConcerns = 'done_with_concerns';
    case Blocked = 'blocked';
    case NeedsContext = 'needs_context';
    public static function fromProducerSignal(string $output): self; // default Done
    public function halts(): bool; // true for Blocked, NeedsContext
}

// Contract/StageSeverity.php
enum StageSeverity: string {
    case Critical = 'critical';
    case Important = 'important';
    case Minor = 'minor';
    public function blocks(): bool; // true for Critical, Important
}

// Contract/StageFinding.php
final readonly class StageFinding {
    public function __construct(public StageSeverity $severity, public string $summary, public ?string $location = null);
    public static function fromArray(array $data): self;
    public function toArray(): array;
}

// Contract/StageVerdict.php
final readonly class StageVerdict {
    /** @param list<StageFinding> $findings */
    public function __construct(
        public StageStatus $status,
        public ?bool $requirementsMet,   // null for non-gate
        public ?bool $qualityPass,       // null for non-gate
        public array $findings,
        public string $rationale,
    );
    public function isApproved(): bool;          // requirementsMet && qualityPass && !hasBlockingFindings()
    public function hasBlockingFindings(): bool;  // any finding->severity->blocks()
    public function toArray(): array;
    public static function fromArray(array $data): self;
    public static function gateFromText(string $output): self;                    // keyword fallback
    public static function producerSelfSignal(string $output, array $findings = []): self; // non-gate
}

// Contract/IterationOutcome.php  (add)
case Blocked = 'blocked';

// Contract/LoopRoleDefinition.php  (add public readonly props, default false)
public bool $gate = false, public bool $artifactRequired = false, public bool $memoryRequired = false

// Agent/StageGateEvaluator.php
final readonly class StageGateEvaluator {
    public function __construct(private ProviderInterface $provider);
    /** @param list<string> $priorStageSummaries */
    public function judge(string $goal, ?string $acceptanceCriteria, string $gateStageOutput, array $priorStageSummaries = []): StageVerdict;
}

// Storage/LoopStore.php  (add)
public function recordStageVerdict(string $stageId, string $verdictJson): void;

// Memory/MemoryStore.php  (add)
public function countBySession(string $sessionId): int;

// LoopExecutor constructor gains (nullable, appended):
//   ?StageGateEvaluator $stageGateEvaluator = null, ?MemoryStore $memoryStore = null
```

Default circuit-breaker limit: **3**. Read `max_rework_attempts` from the loop's stored configuration JSON if present, else default 3 (constant `LoopExecutor::DEFAULT_MAX_REWORK_ATTEMPTS = 3`).

---

## Task 1: StageStatus and StageSeverity enums

**Files:**
- Create: `src/Contract/StageStatus.php`
- Create: `src/Contract/StageSeverity.php`
- Test: `tests/Unit/Contract/StageStatusTest.php`

**Interfaces:**
- Produces: `StageStatus` (`Done|DoneWithConcerns|Blocked|NeedsContext`, `fromProducerSignal()`, `halts()`); `StageSeverity` (`Critical|Important|Minor`, `blocks()`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Contract\StageStatus;
use CoquiBot\Coqui\Contract\StageSeverity;

test('fromProducerSignal defaults to Done when no sentinel present', function () {
    expect(StageStatus::fromProducerSignal("Implemented the feature.\nAll tests pass."))->toBe(StageStatus::Done);
});

test('fromProducerSignal detects a leading BLOCKED sentinel', function () {
    expect(StageStatus::fromProducerSignal("STATUS: BLOCKED\nCannot find the target module."))->toBe(StageStatus::Blocked);
});

test('fromProducerSignal detects NEEDS_CONTEXT case-insensitively within the first lines', function () {
    expect(StageStatus::fromProducerSignal("Working on it\nstatus: needs_context\nmissing the API key"))->toBe(StageStatus::NeedsContext);
});

test('fromProducerSignal ignores a malformed sentinel and returns Done', function () {
    expect(StageStatus::fromProducerSignal("STATUS: WEIRD_VALUE\nbody"))->toBe(StageStatus::Done);
});

test('halts is true only for Blocked and NeedsContext', function () {
    expect(StageStatus::Blocked->halts())->toBeTrue();
    expect(StageStatus::NeedsContext->halts())->toBeTrue();
    expect(StageStatus::Done->halts())->toBeFalse();
    expect(StageStatus::DoneWithConcerns->halts())->toBeFalse();
});

test('severity blocks is true for Critical and Important only', function () {
    expect(StageSeverity::Critical->blocks())->toBeTrue();
    expect(StageSeverity::Important->blocks())->toBeTrue();
    expect(StageSeverity::Minor->blocks())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Contract/StageStatusTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Contract\StageStatus" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Contract/StageStatus.php`:
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Machine-readable outcome status for a loop stage.
 *
 * Producer (non-gate) stages self-signal a status with no LLM call — defaulting
 * to Done, optionally emitting Blocked / NeedsContext via a leading sentinel.
 */
enum StageStatus: string
{
    case Done = 'done';
    case DoneWithConcerns = 'done_with_concerns';
    case Blocked = 'blocked';
    case NeedsContext = 'needs_context';

    /**
     * Cheap, tolerant self-signal parse of a producer stage's output.
     *
     * Scans the first few lines for `STATUS: BLOCKED` / `STATUS: NEEDS_CONTEXT`
     * (case-insensitive). Anything absent or unrecognized resolves to Done.
     */
    public static function fromProducerSignal(string $output): self
    {
        $head = implode("\n", array_slice(explode("\n", $output), 0, 5));
        if (preg_match('/status:\s*(blocked|needs_context)/i', $head, $m) === 1) {
            return strtolower($m[1]) === 'blocked' ? self::Blocked : self::NeedsContext;
        }

        return self::Done;
    }

    /** Whether this status must halt the loop into escalation. */
    public function halts(): bool
    {
        return $this === self::Blocked || $this === self::NeedsContext;
    }
}
```

`src/Contract/StageSeverity.php`:
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Severity of a reviewer finding. Critical/Important block the gate (force
 * rework); Minor accrues (surfaced, never blocks).
 */
enum StageSeverity: string
{
    case Critical = 'critical';
    case Important = 'important';
    case Minor = 'minor';

    /** Whether a finding of this severity blocks gate approval. */
    public function blocks(): bool
    {
        return $this === self::Critical || $this === self::Important;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Contract/StageStatusTest.php`
Expected: PASS (6 passing).

- [ ] **Step 5: Commit**

```bash
git add src/Contract/StageStatus.php src/Contract/StageSeverity.php tests/Unit/Contract/StageStatusTest.php
git commit -m "feat(loops): add StageStatus and StageSeverity enums"
```

---

## Task 2: StageFinding and StageVerdict value objects

**Files:**
- Create: `src/Contract/StageFinding.php`
- Create: `src/Contract/StageVerdict.php`
- Test: `tests/Unit/Contract/StageVerdictTest.php`

**Interfaces:**
- Consumes: `StageStatus`, `StageSeverity` (Task 1).
- Produces: `StageFinding` (fromArray/toArray); `StageVerdict` (`isApproved()`, `hasBlockingFindings()`, `toArray()`, `fromArray()`, `gateFromText()`, `producerSelfSignal()`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Contract\StageFinding;
use CoquiBot\Coqui\Contract\StageVerdict;
use CoquiBot\Coqui\Contract\StageSeverity;
use CoquiBot\Coqui\Contract\StageStatus;

test('approved gate verdict has both flags true and no blocking findings', function () {
    $v = new StageVerdict(StageStatus::Done, true, true, [], 'All criteria met.');
    expect($v->isApproved())->toBeTrue();
    expect($v->hasBlockingFindings())->toBeFalse();
});

test('gate verdict with a critical finding is not approved', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, true, true, [
        new StageFinding(StageSeverity::Critical, 'Data loss on retry'),
    ], 'One critical issue.');
    expect($v->hasBlockingFindings())->toBeTrue();
    expect($v->isApproved())->toBeFalse();
});

test('gate verdict with only minor findings and both flags true is approved', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, true, true, [
        new StageFinding(StageSeverity::Minor, 'Typo in comment'),
    ], 'Minor nit only.');
    expect($v->isApproved())->toBeTrue();
});

test('gate verdict is not approved when requirementsMet is false', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, false, true, [], 'Missing feature X.');
    expect($v->isApproved())->toBeFalse();
});

test('toArray then fromArray round-trips', function () {
    $v = new StageVerdict(StageStatus::DoneWithConcerns, false, true, [
        new StageFinding(StageSeverity::Important, 'No error handling', 'src/Foo.php'),
    ], 'Needs work.');
    $restored = StageVerdict::fromArray($v->toArray());
    expect($restored->status)->toBe(StageStatus::DoneWithConcerns);
    expect($restored->requirementsMet)->toBeFalse();
    expect($restored->findings[0]->severity)->toBe(StageSeverity::Important);
    expect($restored->findings[0]->location)->toBe('src/Foo.php');
});

test('gateFromText reads approval keywords into an approved verdict', function () {
    $v = StageVerdict::gateFromText('This looks good. APPROVED — no remaining issues.');
    expect($v->isApproved())->toBeTrue();
});

test('gateFromText treats a rejection as not approved', function () {
    $v = StageVerdict::gateFromText('Needs changes: the parser is broken.');
    expect($v->isApproved())->toBeFalse();
});

test('producerSelfSignal derives status from output and leaves gate flags null', function () {
    $v = StageVerdict::producerSelfSignal("STATUS: BLOCKED\nmissing module");
    expect($v->status)->toBe(StageStatus::Blocked);
    expect($v->requirementsMet)->toBeNull();
    expect($v->qualityPass)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Contract/StageVerdictTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Contract\StageFinding" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Contract/StageFinding.php`:
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A single reviewer finding within a gate stage verdict.
 */
final readonly class StageFinding
{
    public function __construct(
        public StageSeverity $severity,
        public string $summary,
        public ?string $location = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            severity: StageSeverity::tryFrom((string) ($data['severity'] ?? 'minor')) ?? StageSeverity::Minor,
            summary: (string) ($data['summary'] ?? ''),
            location: isset($data['location']) && $data['location'] !== '' ? (string) $data['location'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'location' => $this->location,
        ];
    }
}
```

`src/Contract/StageVerdict.php`:
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Machine-readable verdict for a completed loop stage.
 *
 * For gate stages, requirementsMet/qualityPass/findings drive approval. For
 * non-gate producer stages, those are null and only `status` matters.
 */
final readonly class StageVerdict
{
    /**
     * @param list<StageFinding> $findings
     */
    public function __construct(
        public StageStatus $status,
        public ?bool $requirementsMet,
        public ?bool $qualityPass,
        public array $findings,
        public string $rationale,
    ) {}

    /** Gate approval: both verdicts true and no Critical/Important findings. */
    public function isApproved(): bool
    {
        return $this->requirementsMet === true
            && $this->qualityPass === true
            && !$this->hasBlockingFindings();
    }

    public function hasBlockingFindings(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity->blocks()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'requirements_met' => $this->requirementsMet,
            'quality_pass' => $this->qualityPass,
            'findings' => array_map(static fn(StageFinding $f): array => $f->toArray(), $this->findings),
            'rationale' => $this->rationale,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $findings = [];
        foreach ($data['findings'] ?? [] as $raw) {
            if (is_array($raw)) {
                $findings[] = StageFinding::fromArray($raw);
            }
        }

        return new self(
            status: StageStatus::tryFrom((string) ($data['status'] ?? 'done')) ?? StageStatus::Done,
            requirementsMet: array_key_exists('requirements_met', $data) ? self::nullableBool($data['requirements_met']) : null,
            qualityPass: array_key_exists('quality_pass', $data) ? self::nullableBool($data['quality_pass']) : null,
            findings: $findings,
            rationale: (string) ($data['rationale'] ?? ''),
        );
    }

    /**
     * Keyword fallback for gate stages when no utility model is configured.
     */
    public static function gateFromText(string $output): self
    {
        $lower = strtolower($output);
        $approvalSignals = ['approved', 'approve', 'lgtm', 'looks good', 'accepted', 'passes all criteria'];
        $rejectionSignals = ['rejected', 'needs changes', 'needs_changes', 'needs work', 'not approved', 'revisions needed'];

        $approved = false;
        foreach ($approvalSignals as $signal) {
            if (str_contains($lower, $signal)) {
                $approved = true;
                break;
            }
        }
        foreach ($rejectionSignals as $signal) {
            if (str_contains($lower, $signal)) {
                $approved = false;
                break;
            }
        }

        return new self(
            status: $approved ? StageStatus::Done : StageStatus::DoneWithConcerns,
            requirementsMet: $approved,
            qualityPass: $approved,
            findings: [],
            rationale: $approved ? 'Approved (keyword fallback).' : 'Not approved (keyword fallback).',
        );
    }

    /**
     * Non-gate producer verdict from a cheap self-signal parse.
     *
     * @param list<StageFinding> $findings
     */
    public static function producerSelfSignal(string $output, array $findings = []): self
    {
        return new self(
            status: StageStatus::fromProducerSignal($output),
            requirementsMet: null,
            qualityPass: null,
            findings: $findings,
            rationale: 'Producer self-signal.',
        );
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Contract/StageVerdictTest.php`
Expected: PASS (8 passing).

- [ ] **Step 5: Commit**

```bash
git add src/Contract/StageFinding.php src/Contract/StageVerdict.php tests/Unit/Contract/StageVerdictTest.php
git commit -m "feat(loops): add StageFinding and StageVerdict value objects"
```

---

## Task 3: Add IterationOutcome::Blocked

**Files:**
- Modify: `src/Contract/IterationOutcome.php`
- Test: `tests/Unit/Contract/IterationOutcomeTest.php`

**Interfaces:**
- Produces: `IterationOutcome::Blocked` (value `'blocked'`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Contract\IterationOutcome;

test('Blocked case exists with the blocked value', function () {
    expect(IterationOutcome::Blocked->value)->toBe('blocked');
    expect(IterationOutcome::from('blocked'))->toBe(IterationOutcome::Blocked);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Contract/IterationOutcomeTest.php`
Expected: FAIL — undefined constant `Blocked`.

- [ ] **Step 3: Write minimal implementation**

In `src/Contract/IterationOutcome.php`, add after the `LimitReached` case:
```php
    /** Non-convergence or an unrecoverable stage signal — loop stops for operator retry. */
    case Blocked = 'blocked';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Contract/IterationOutcomeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Contract/IterationOutcome.php tests/Unit/Contract/IterationOutcomeTest.php
git commit -m "feat(loops): add IterationOutcome::Blocked"
```

---

## Task 4: LoopRoleDefinition gate/artifact_required/memory_required flags

**Files:**
- Modify: `src/Contract/LoopRoleDefinition.php`
- Test: `tests/Unit/Contract/LoopRoleDefinitionTest.php` (create if absent)

**Interfaces:**
- Consumes: existing `LoopRoleDefinition`.
- Produces: `LoopRoleDefinition->gate`, `->artifactRequired`, `->memoryRequired` (all `bool`, default `false`); `fromArray()`/`toArray()` handle `gate`, `artifact_required`, `memory_required`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopRoleDefinition;

test('flags default to false when absent', function () {
    $r = LoopRoleDefinition::fromArray(['role' => 'coder', 'prompt' => 'do it']);
    expect($r->gate)->toBeFalse();
    expect($r->artifactRequired)->toBeFalse();
    expect($r->memoryRequired)->toBeFalse();
});

test('flags parse from array and round-trip through toArray', function () {
    $r = LoopRoleDefinition::fromArray([
        'role' => 'reviewer',
        'prompt' => 'review it',
        'gate' => true,
        'artifact_required' => true,
        'memory_required' => true,
    ]);
    expect($r->gate)->toBeTrue();
    expect($r->artifactRequired)->toBeTrue();
    expect($r->memoryRequired)->toBeTrue();

    $round = LoopRoleDefinition::fromArray($r->toArray());
    expect($round->gate)->toBeTrue();
    expect($round->artifactRequired)->toBeTrue();
    expect($round->memoryRequired)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Contract/LoopRoleDefinitionTest.php`
Expected: FAIL — undefined property `gate`.

- [ ] **Step 3: Write minimal implementation**

In `src/Contract/LoopRoleDefinition.php`, extend the constructor and both mappers.

Constructor (append the three params after `$maxIterations`):
```php
    public function __construct(
        public string $role,
        public string $prompt,
        public array $skills = [],
        public ?int $maxIterations = null,
        public bool $gate = false,
        public bool $artifactRequired = false,
        public bool $memoryRequired = false,
    ) {
```

`fromArray()` (add the three fields to the `new self(...)`):
```php
        return new self(
            role: $data['role'] ?? $data['name'] ?? '',
            prompt: $data['prompt'] ?? '',
            skills: $data['skills'] ?? [],
            maxIterations: isset($data['max_iterations']) ? (int) $data['max_iterations'] : null,
            gate: (bool) ($data['gate'] ?? false),
            artifactRequired: (bool) ($data['artifact_required'] ?? false),
            memoryRequired: (bool) ($data['memory_required'] ?? false),
        );
```

`toArray()` (add the three keys):
```php
        return [
            'role' => $this->role,
            'prompt' => $this->prompt,
            'skills' => $this->skills,
            'max_iterations' => $this->maxIterations,
            'gate' => $this->gate,
            'artifact_required' => $this->artifactRequired,
            'memory_required' => $this->memoryRequired,
        ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Contract/LoopRoleDefinitionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Contract/LoopRoleDefinition.php tests/Unit/Contract/LoopRoleDefinitionTest.php
git commit -m "feat(loops): add gate/artifact_required/memory_required role flags"
```

---

## Task 5: LoopStore verdict column + recordStageVerdict

**Files:**
- Modify: `src/Storage/LoopStore.php` (`createTables()` ~line 85; add `recordStageVerdict()`)
- Test: `tests/Unit/Storage/LoopStoreVerdictTest.php`

**Interfaces:**
- Produces: `loop_stages.verdict` column (nullable TEXT); `LoopStore::recordStageVerdict(string $stageId, string $verdictJson): void`. `getStage()`/`listStages()`/`getCompletedStages()` already `SELECT *`, so they return `verdict` automatically.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Storage\LoopStore;

function verdictStore(): LoopStore
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return new LoopStore($pdo);
}

test('recordStageVerdict persists a verdict json readable via getStage', function () {
    $store = verdictStore();
    $loopId = $store->createLoop('harness', 'goal', ['name' => 'harness'], maxIterations: 3);
    $iterId = $store->createIteration($loopId, 1);
    $stageId = $store->createStage($iterId, 0, 'reviewer');

    $store->recordStageVerdict($stageId, '{"status":"done","requirements_met":true}');

    $stage = $store->getStage($stageId);
    expect($stage['verdict'])->toBe('{"status":"done","requirements_met":true}');
});

test('verdict defaults to null for a fresh stage', function () {
    $store = verdictStore();
    $loopId = $store->createLoop('harness', 'goal', ['name' => 'harness'], maxIterations: 3);
    $iterId = $store->createIteration($loopId, 1);
    $stageId = $store->createStage($iterId, 0, 'coder');
    expect($store->getStage($stageId)['verdict'])->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/LoopStoreVerdictTest.php`
Expected: FAIL — `recordStageVerdict` undefined / no `verdict` column.

- [ ] **Step 3: Write minimal implementation**

In `createTables()`, next to the existing `metadata` migration line (~line 85), add:
```php
        $this->migrateAddColumn('loop_stages', 'metadata', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('loop_stages', 'verdict', 'TEXT DEFAULT NULL');
```

Add the method in the Stage CRUD section (after `updateStage()`):
```php
    /**
     * Persist a stage's machine-readable verdict JSON without touching its status.
     */
    public function recordStageVerdict(string $stageId, string $verdictJson): void
    {
        $stmt = $this->db->prepare('UPDATE loop_stages SET verdict = ? WHERE id = ?');
        $stmt->execute([$verdictJson, $stageId]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Storage/LoopStoreVerdictTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Storage/LoopStore.php tests/Unit/Storage/LoopStoreVerdictTest.php
git commit -m "feat(loops): add loop_stages.verdict column and recordStageVerdict"
```

---

## Task 6: MemoryStore.countBySession

**Files:**
- Modify: `src/Memory/MemoryStore.php` (add public method near `count()` ~line 448)
- Test: `tests/Unit/Memory/MemoryStoreCountBySessionTest.php`

**Interfaces:**
- Produces: `MemoryStore::countBySession(string $sessionId): int`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemoryEntry;

test('countBySession counts only memories written in that session', function () {
    $store = new MemoryStore(new PDO('sqlite::memory:'));
    $store->save(new MemoryEntry(content: 'a', sessionId: 'sess-1'));
    $store->save(new MemoryEntry(content: 'b', sessionId: 'sess-1'));
    $store->save(new MemoryEntry(content: 'c', sessionId: 'sess-2'));

    expect($store->countBySession('sess-1'))->toBe(2);
    expect($store->countBySession('sess-2'))->toBe(1);
    expect($store->countBySession('sess-none'))->toBe(0);
});
```

> **Implementer note:** verify `MemoryStore`'s constructor signature and `MemoryEntry`'s constructor (the exact named args for `content`/`sessionId`) by reading `src/Memory/MemoryStore.php` and `src/Memory/MemoryEntry.php` before running — adjust the test's construction to match. The assertion behavior stays as written.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Memory/MemoryStoreCountBySessionTest.php`
Expected: FAIL — `countBySession` undefined.

- [ ] **Step 3: Write minimal implementation**

Add near `count()`:
```php
    /**
     * Count memories recorded within a specific session.
     *
     * Used by the loop engine's soft `memory_required` check — a stage that was
     * asked to record a canonical-artifact memory pointer but wrote none earns a
     * Minor concern (never blocking).
     */
    public function countBySession(string $sessionId): int
    {
        $stmt = $this->getPdo()->prepare('SELECT COUNT(*) FROM memories WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        return (int) $stmt->fetchColumn();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Memory/MemoryStoreCountBySessionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Memory/MemoryStore.php tests/Unit/Memory/MemoryStoreCountBySessionTest.php
git commit -m "feat(memory): add MemoryStore::countBySession for the loop soft memory check"
```

---

## Task 7: StageGateEvaluator

**Files:**
- Create: `src/Agent/StageGateEvaluator.php`
- Test: `tests/Unit/Agent/StageGateEvaluatorTest.php`

**Interfaces:**
- Consumes: `StageVerdict`, `StageFinding`, `StageSeverity`, `StageStatus` (Tasks 1–2); `ProviderInterface`.
- Produces: `StageGateEvaluator::__construct(ProviderInterface $provider)`; `judge(string $goal, ?string $acceptanceCriteria, string $gateStageOutput, array $priorStageSummaries = []): StageVerdict`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Agent\StageGateEvaluator;

function makeGateProvider(string $responseContent): ProviderInterface
{
    return new class ($responseContent) implements ProviderInterface {
        public function __construct(private readonly string $responseContent) {}
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(content: $this->responseContent, finishReason: ProviderFinishReason::Stop);
        }
        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

test('judge parses an approved JSON verdict', function () {
    $json = '{"requirements_met": true, "quality_pass": true, "findings": [], "rationale": "Meets the goal."}';
    $evaluator = new StageGateEvaluator(makeGateProvider($json));

    $verdict = $evaluator->judge('Build X', 'All tests pass.', 'I reviewed it; it is complete.');

    expect($verdict->isApproved())->toBeTrue();
    expect($verdict->rationale)->toBe('Meets the goal.');
});

test('judge parses findings with severities into a non-approved verdict', function () {
    $json = '{"requirements_met": true, "quality_pass": false, "findings": [{"severity":"critical","summary":"crashes on empty input"}], "rationale":"One critical bug."}';
    $evaluator = new StageGateEvaluator(makeGateProvider($json));

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->isApproved())->toBeFalse();
    expect($verdict->hasBlockingFindings())->toBeTrue();
    expect($verdict->findings[0]->summary)->toBe('crashes on empty input');
});

test('judge extracts a fenced json block when the model wraps it in prose', function () {
    $content = "Here is my assessment:\n```json\n{\"requirements_met\": false, \"quality_pass\": true, \"findings\": [], \"rationale\": \"Incomplete.\"}\n```\nDone.";
    $evaluator = new StageGateEvaluator(makeGateProvider($content));

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->requirementsMet)->toBeFalse();
});

test('judge falls back to keyword parsing on unparseable output', function () {
    $evaluator = new StageGateEvaluator(makeGateProvider('APPROVED — everything checks out.'));

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->isApproved())->toBeTrue();
});

test('judge falls back to a not-approved verdict when the provider throws', function () {
    $throwing = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response { throw new \RuntimeException('boom'); }
        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
    $evaluator = new StageGateEvaluator($throwing);

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->isApproved())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Agent/StageGateEvaluatorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

`src/Agent/StageGateEvaluator.php`:
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CoquiBot\Coqui\Contract\StageFinding;
use CoquiBot\Coqui\Contract\StageStatus;
use CoquiBot\Coqui\Contract\StageVerdict;

/**
 * Single-shot LLM gate evaluator for a loop's reviewer (gate) stage.
 *
 * Judges the gate stage's output against the goal and acceptance criteria and
 * emits a structured StageVerdict (requirements_met + quality_pass + severity
 * findings). Mirrors GoalEvaluator: one provider->chat() call, no tool use,
 * catches all errors, degrades to a keyword verdict on unparseable output.
 */
final readonly class StageGateEvaluator
{
    public function __construct(
        private ProviderInterface $provider,
    ) {}

    /**
     * @param list<string> $priorStageSummaries
     */
    public function judge(
        string $goal,
        ?string $acceptanceCriteria,
        string $gateStageOutput,
        array $priorStageSummaries = [],
    ): StageVerdict {
        try {
            $systemPrompt = <<<'SYSTEM'
            You are a strict quality gate for an automated multi-role loop.
            You judge whether the work meets the goal and the acceptance criteria — you judge the
            work itself, not the worker's self-report.

            Respond with a SINGLE JSON object and nothing else:
            {
              "requirements_met": true|false,
              "quality_pass": true|false,
              "findings": [{"severity": "critical|important|minor", "summary": "...", "location": "optional"}],
              "rationale": "1-3 sentences"
            }

            requirements_met = the goal and acceptance criteria are fully satisfied.
            quality_pass = the work is correct, complete, and free of quality defects that matter.
            Use "critical"/"important" for issues that must block approval; "minor" for nits.
            Be strict: partial or unverified work is requirements_met=false.
            SYSTEM;

            $userPrompt = "## Goal\n{$goal}\n\n";
            if ($acceptanceCriteria !== null && $acceptanceCriteria !== '') {
                $userPrompt .= "## Acceptance Criteria\n{$acceptanceCriteria}\n\n";
            }
            if ($priorStageSummaries !== []) {
                $userPrompt .= "## Prior Stage Outputs\n" . implode("\n\n", $priorStageSummaries) . "\n\n";
            }
            $userPrompt .= "## Reviewer (Gate) Stage Output\n{$gateStageOutput}\n\n";
            $userPrompt .= 'Return the JSON verdict now.';

            $response = $this->provider->chat([
                new SystemMessage($systemPrompt),
                new UserMessage($userPrompt),
            ]);

            return $this->parse($response->content);
        } catch (\Throwable) {
            return new StageVerdict(
                status: StageStatus::DoneWithConcerns,
                requirementsMet: false,
                qualityPass: false,
                findings: [],
                rationale: 'Gate evaluation failed due to an internal error; treating as not approved.',
            );
        }
    }

    private function parse(string $content): StageVerdict
    {
        $json = $this->extractJson($content);
        if ($json === null) {
            return StageVerdict::gateFromText($content);
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('requirements_met', $data)) {
            return StageVerdict::gateFromText($content);
        }

        $findings = [];
        foreach ($data['findings'] ?? [] as $raw) {
            if (is_array($raw)) {
                $findings[] = StageFinding::fromArray($raw);
            }
        }

        $requirementsMet = (bool) $data['requirements_met'];
        $qualityPass = (bool) ($data['quality_pass'] ?? false);

        $verdict = new StageVerdict(
            status: ($requirementsMet && $qualityPass) ? StageStatus::Done : StageStatus::DoneWithConcerns,
            requirementsMet: $requirementsMet,
            qualityPass: $qualityPass,
            findings: $findings,
            rationale: (string) ($data['rationale'] ?? ''),
        );

        return $verdict;
    }

    /**
     * Extract the first JSON object from raw model output — handles a fenced
     * ```json block or a bare object embedded in prose.
     */
    private function extractJson(string $content): ?string
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m) === 1) {
            return $m[1];
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($content, $start, $end - $start + 1);
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Agent/StageGateEvaluatorTest.php`
Expected: PASS (5 passing).

- [ ] **Step 5: Commit**

```bash
git add src/Agent/StageGateEvaluator.php tests/Unit/Agent/StageGateEvaluatorTest.php
git commit -m "feat(loops): add StageGateEvaluator (structured gate verdict via utility LLM)"
```

---

## Task 8: LoopExecutor — per-stage verdicts + non-gate halt (BLOCKED/NEEDS_CONTEXT) + artifact/memory checks

**Files:**
- Modify: `src/Agent/LoopExecutor.php` (constructor; `evaluateIteration()`; new private helpers)
- Test: `tests/Unit/Agent/LoopExecutorBlockedStageTest.php`

**Interfaces:**
- Consumes: `StageVerdict`, `StageStatus`, `StageSeverity`, `StageFinding`, `LoopRoleDefinition` flags, `LoopStore::recordStageVerdict`, `MemoryStore::countBySession`, `IterationOutcome::Blocked`.
- Produces: LoopExecutor constructor now accepts `?StageGateEvaluator $stageGateEvaluator = null, ?MemoryStore $memoryStore = null` (appended). New private `escalateBlocked(array $loop, string $iterationId, string $reason, array $findings): void`. `evaluateIteration()` returns `IterationOutcome::Blocked` when a non-gate stage halts or (Task 9) the breaker trips. On block, loop status → `blocked`, iteration status → `needs_rework`, loop metadata gains `escalation` = `{reason, attempts, findings, at}`.

**Context — current `evaluateIteration()` shape** (`src/Agent/LoopExecutor.php:313`): checks failed stages → `Failed`; checks pending stages → `Continue`; else matches on termination type. Insert the non-gate verdict scan **between** the failed-stage check and the pending-stage check, so a producer BLOCKED halts before the next stage dispatches.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

function blockExecutorStores(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return [new LoopStore($pdo), new ProjectStore($pdo)];
}

// A 2-role iteration_bound definition: coder (producer) then a non-reviewer producer.
function twoProducerConfig(): array
{
    return [
        'name' => 'twoprod',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'do'],
            ['role' => 'coder2', 'prompt' => 'do more'],
        ],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 3]],
    ];
}

test('a non-gate stage that self-signals BLOCKED halts the loop into blocked', function () {
    [$loopStore, $projectStore] = blockExecutorStores();
    $executor = new LoopExecutor($loopStore, $projectStore);

    $projectId = $projectStore->createProject(title: 'p', slug: 'p-1', description: 'd');
    $loopId = $loopStore->createLoop('twoprod', 'goal', twoProducerConfig(), projectId: $projectId, maxIterations: 3);
    $state = $loopStore->getCurrentState($loopId);
    $stages = $state['stages'];

    // Stage 0 completes with a BLOCKED self-signal; stage 1 still pending.
    $loopStore->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: "STATUS: BLOCKED\nmissing dependency");

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Blocked);
    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('blocked');
    $meta = json_decode($loop['metadata'], true);
    expect($meta['escalation']['reason'])->toContain('blocked');
    // The current iteration is left retryable.
    $iter = $loopStore->getCurrentState($loopId)['iteration'];
    expect($iter['status'])->toBe('needs_rework');
});

test('an artifact_required non-gate stage with no artifact halts the loop into blocked', function () {
    [$loopStore, $projectStore] = blockExecutorStores();
    $executor = new LoopExecutor($loopStore, $projectStore);

    $config = twoProducerConfig();
    $config['roles'][0]['artifact_required'] = true;

    $projectId = $projectStore->createProject(title: 'p', slug: 'p-2', description: 'd');
    $loopId = $loopStore->createLoop('twoprod', 'goal', $config, projectId: $projectId, maxIterations: 3);
    $stages = $loopStore->getCurrentState($loopId)['stages'];

    // Stage 0 completes Done but produced NO artifact (artifact_id null).
    $loopStore->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: 'did work but wrote no artifact');

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Blocked);
    expect($loopStore->getLoop($loopId)['status'])->toBe('blocked');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorBlockedStageTest.php`
Expected: FAIL — outcome is `Continue`, loop still `running`.

- [ ] **Step 3: Write minimal implementation**

3a. Extend the constructor imports and signature. Add `use` statements at the top of `LoopExecutor.php`:
```php
use CoquiBot\Coqui\Agent\StageGateEvaluator;
use CoquiBot\Coqui\Contract\StageFinding;
use CoquiBot\Coqui\Contract\StageSeverity;
use CoquiBot\Coqui\Contract\StageStatus;
use CoquiBot\Coqui\Contract\StageVerdict;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Support\Clock;
```
(Keep any of these that already exist; do not duplicate `use` lines.)

Constructor — append the two nullable deps:
```php
    public const DEFAULT_MAX_REWORK_ATTEMPTS = 3;

    public function __construct(
        private readonly LoopStore $loopStore,
        private readonly ProjectStore $projectStore,
        private readonly ?SessionStorage $sessionStorage = null,
        private readonly ?GoalEvaluator $goalEvaluator = null,
        private readonly ?StageGateEvaluator $stageGateEvaluator = null,
        private readonly ?MemoryStore $memoryStore = null,
    ) {}
```

3b. In `evaluateIteration()`, insert the non-gate scan **after** the failed-stage block (the one returning `IterationOutcome::Failed`) and **before** the `$pendingStages` computation:
```php
        // Parse the loop definition once for role flags / gate detection.
        $definition = LoopDefinition::fromArray(
            json_decode($loop['configuration'], true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR),
        );

        // Per-stage non-gate verdicts. A producer stage that self-signals
        // Blocked/NeedsContext, or fails a hard artifact_required check, halts
        // the loop into `blocked` BEFORE the next stage dispatches.
        foreach ($stages as $stage) {
            if ($stage['status'] !== 'completed') {
                continue;
            }
            $stageIndex = (int) $stage['stage_index'];
            if ($this->isGateStage($definition, $stageIndex)) {
                continue; // gate stage is judged at iteration end
            }
            if (($stage['verdict'] ?? null) !== null && $stage['verdict'] !== '') {
                $verdict = StageVerdict::fromArray(json_decode($stage['verdict'], true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR));
            } else {
                $verdict = $this->buildNonGateVerdict($definition, $stage);
                $this->loopStore->recordStageVerdict((string) $stage['id'], json_encode($verdict->toArray(), JSON_UNESCAPED_SLASHES));
            }
            if ($verdict->status->halts()) {
                $reason = $verdict->status === StageStatus::Blocked
                    ? sprintf('Stage %d (%s) reported blocked.', $stageIndex, $stage['role'])
                    : sprintf('Stage %d (%s) needs additional context.', $stageIndex, $stage['role']);
                $this->escalateBlocked($loop, $iteration['id'], $reason, $verdict->findings);
                return IterationOutcome::Blocked;
            }
        }
```

3c. Add the helper methods (private, near the other private helpers):
```php
    /**
     * A stage is a gate if its role is flagged gate:true, or it is the last
     * role of an evaluation_bound loop.
     */
    private function isGateStage(LoopDefinition $definition, int $stageIndex): bool
    {
        $role = $definition->roles[$stageIndex] ?? null;
        if ($role === null) {
            return false;
        }
        if ($role->gate) {
            return true;
        }

        return $definition->terminationCondition->type === TerminationType::EvaluationBound
            && $stageIndex === count($definition->roles) - 1;
    }

    /**
     * Build a non-gate producer verdict with no LLM call: self-signal status
     * plus the hard artifact_required check and the soft memory_required check.
     *
     * @param array<string, mixed> $stage
     */
    private function buildNonGateVerdict(LoopDefinition $definition, array $stage): StageVerdict
    {
        $stageIndex = (int) $stage['stage_index'];
        $role = $definition->roles[$stageIndex] ?? null;
        $output = (string) ($stage['result_summary'] ?? '');
        $findings = [];

        // Hard gate: artifact_required with no artifact → Blocked.
        if ($role !== null && $role->artifactRequired) {
            $artifactId = (string) ($stage['artifact_id'] ?? '');
            if ($artifactId === '') {
                return new StageVerdict(
                    status: StageStatus::Blocked,
                    requirementsMet: null,
                    qualityPass: null,
                    findings: [new StageFinding(StageSeverity::Critical, 'Required artifact was not produced.')],
                    rationale: 'artifact_required stage produced no durable artifact.',
                );
            }
        }

        // Soft check: memory_required with no memory written → Minor concern.
        if ($role !== null && $role->memoryRequired && $this->memoryStore !== null && $this->sessionStorage !== null) {
            $taskId = (string) ($stage['task_id'] ?? '');
            if ($taskId !== '') {
                $task = $this->sessionStorage->getTask($taskId);
                $sessionId = is_array($task) ? (string) ($task['session_id'] ?? '') : '';
                if ($sessionId !== '' && $this->memoryStore->countBySession($sessionId) === 0) {
                    $findings[] = new StageFinding(StageSeverity::Minor, 'Stage recorded no memory pointer for its canonical artifact.');
                }
            }
        }

        return StageVerdict::producerSelfSignal($output, $findings);
    }

    /**
     * Transition the loop to `blocked`, record the escalation, and leave the
     * current iteration retryable (needs_rework) for the operator.
     *
     * @param array<string, mixed> $loop
     * @param list<StageFinding> $findings
     */
    private function escalateBlocked(array $loop, string $iterationId, string $reason, array $findings): void
    {
        $attempts = 0;
        if (is_string($loop['metadata'] ?? null) && $loop['metadata'] !== '') {
            $meta = json_decode($loop['metadata'], true);
            $attempts = is_array($meta) ? (int) ($meta['rework_attempts'] ?? 0) : 0;
        }

        $this->loopStore->updateLoopMetadata((string) $loop['id'], [
            'escalation' => [
                'reason' => $reason,
                'attempts' => $attempts,
                'findings' => array_map(static fn(StageFinding $f): array => $f->toArray(), $findings),
                'at' => Clock::nowUtc(),
            ],
        ]);
        $this->loopStore->updateIterationStatus($iterationId, 'needs_rework', $reason);
        $this->loopStore->updateLoopStatus((string) $loop['id'], 'blocked');
    }
```

> **Note on `$definition`:** the existing `evaluateIteration()` already decodes `$definition` further down (before the termination `match`). Reuse the single decode inserted at 3b and delete the now-duplicate decode below it so there is exactly one `$definition` in the method.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorBlockedStageTest.php`
Expected: PASS (2 passing).

- [ ] **Step 5: Run the existing LoopExecutor suite to confirm no regression**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorTest.php`
Expected: PASS (existing behavior for iteration_bound/goal_bound unchanged).

- [ ] **Step 6: Commit**

```bash
git add src/Agent/LoopExecutor.php tests/Unit/Agent/LoopExecutorBlockedStageTest.php
git commit -m "feat(loops): per-stage non-gate verdicts halt the loop into blocked"
```

---

## Task 9: LoopExecutor — verdict-driven gate + rework + circuit-breaker

**Files:**
- Modify: `src/Agent/LoopExecutor.php` (`evaluateEvaluationBound()`; iteration-status mapping in `evaluateIteration()`)
- Test: `tests/Unit/Agent/LoopExecutorGateTest.php`

**Interfaces:**
- Consumes: `StageGateEvaluator`, `StageVerdict`, `IterationOutcome::Blocked`, existing `updateLoopMetadata`, `updateIterationStatus`, `updateLoopStatus`.
- Produces: `evaluateEvaluationBound()` now returns `Complete` (gate approved), `Continue` (rework, iteration marked `needs_rework`, `rework_attempts` incremented), or `Blocked` (breaker tripped). Gate verdict persisted to the gate stage via `recordStageVerdict`.

**Context — current `evaluateEvaluationBound()`** (`src/Agent/LoopExecutor.php:518`) string-matches the last stage. Replace its body. Also: the outer status mapping in `evaluateIteration()` currently maps `Continue → 'completed'`. It must map an evaluation-gate rework to `needs_rework` and must handle `Blocked` (no advance, loop already set blocked by the evaluation method).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Agent\StageGateEvaluator;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

function gateProviderReturning(string $json): ProviderInterface
{
    return new class ($json) implements ProviderInterface {
        public function __construct(private readonly string $json) {}
        public function chat(array $messages, array $tools = [], array $options = []): Response
        { return new Response(content: $this->json, finishReason: ProviderFinishReason::Stop); }
        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

function evalBoundConfig(int $maxReworkAttempts = 3): array
{
    return [
        'name' => 'harness',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'do'],
            ['role' => 'reviewer', 'prompt' => 'review'],
        ],
        'termination_condition' => ['type' => 'evaluation_bound', 'value' => ['criteria' => 'ship it', 'max_review_rounds' => 10]],
        'max_rework_attempts' => $maxReworkAttempts,
    ];
}

function completeBothStages(LoopStore $store, string $loopId, string $reviewerOutput): void
{
    $stages = $store->getCurrentState($loopId)['stages'];
    $store->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: 'coder did the work');
    $store->updateStage(id: $stages[1]['id'], status: 'completed', resultSummary: $reviewerOutput);
}

function gateExecutor(string $verdictJson, LoopStore $loopStore, ProjectStore $projectStore): LoopExecutor
{
    return new LoopExecutor(
        loopStore: $loopStore,
        projectStore: $projectStore,
        sessionStorage: null,
        goalEvaluator: null,
        stageGateEvaluator: new StageGateEvaluator(gateProviderReturning($verdictJson)),
    );
}

test('an approved gate verdict completes the loop', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo); $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-1', description: 'd');
    $loopId = $loopStore->createLoop('harness', 'goal', evalBoundConfig(), projectId: $projectId, maxIterations: 10, terminationCriteria: 'ship it');
    completeBothStages($loopStore, $loopId, 'reviewed, all good');

    $executor = gateExecutor('{"requirements_met": true, "quality_pass": true, "findings": [], "rationale": "ok"}', $loopStore, $projectStore);
    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Complete);
    expect($loopStore->getLoop($loopId)['status'])->toBe('completed');
});

test('a rejected gate verdict marks the iteration needs_rework and continues', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo); $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-2', description: 'd');
    $loopId = $loopStore->createLoop('harness', 'goal', evalBoundConfig(), projectId: $projectId, maxIterations: 10, terminationCriteria: 'ship it');
    $firstIterId = $loopStore->getCurrentState($loopId)['iteration']['id'];
    completeBothStages($loopStore, $loopId, 'reviewed, problems found');

    $executor = gateExecutor('{"requirements_met": false, "quality_pass": false, "findings": [{"severity":"critical","summary":"broken"}], "rationale": "no"}', $loopStore, $projectStore);
    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);
    expect($loopStore->getIteration($firstIterId)['status'])->toBe('needs_rework');
    $meta = json_decode($loopStore->getLoop($loopId)['metadata'], true);
    expect($meta['rework_attempts'])->toBe(1);
});

test('the circuit-breaker trips to blocked after max_rework_attempts rejections', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo); $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-3', description: 'd');
    // max_rework_attempts = 2 so we trip on the second rejection.
    $loopId = $loopStore->createLoop('harness', 'goal', evalBoundConfig(2), projectId: $projectId, maxIterations: 10, terminationCriteria: 'ship it');
    $reject = '{"requirements_met": false, "quality_pass": true, "findings": [{"severity":"important","summary":"x"}], "rationale": "no"}';
    $executor = gateExecutor($reject, $loopStore, $projectStore);

    // Round 1 → Continue (advances a new iteration).
    completeBothStages($loopStore, $loopId, 'round 1 review');
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Continue);

    // Round 2 → breaker trips → Blocked.
    completeBothStages($loopStore, $loopId, 'round 2 review');
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Blocked);
    expect($loopStore->getLoop($loopId)['status'])->toBe('blocked');
});

test('gate falls back to keyword approval when no evaluator is configured', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo); $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-4', description: 'd');
    $loopId = $loopStore->createLoop('harness', 'goal', evalBoundConfig(), projectId: $projectId, maxIterations: 10, terminationCriteria: 'ship it');
    completeBothStages($loopStore, $loopId, 'This is APPROVED and complete.');

    $executor = new LoopExecutor($loopStore, $projectStore); // no stageGateEvaluator
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Complete);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorGateTest.php`
Expected: FAIL — breaker/needs_rework/blocked behavior not implemented.

- [ ] **Step 3: Write minimal implementation**

3a. Replace the body of `evaluateEvaluationBound()`. New signature adds `$definition` and `$iterationId`:
```php
    /**
     * Evaluate an evaluation_bound loop via the structured gate verdict.
     *
     * Returns Complete (approved), Continue (rework — iteration marked
     * needs_rework, rework_attempts incremented), or Blocked (breaker tripped).
     *
     * @param list<array<string, mixed>> $stages
     * @param array<string, mixed> $loop
     */
    private function evaluateEvaluationBound(
        LoopDefinition $definition,
        array $stages,
        int $iterationNumber,
        array $loop,
        string $iterationId,
    ): IterationOutcome {
        $gateStage = end($stages);
        if ($gateStage === false) {
            return IterationOutcome::Continue;
        }

        // Reuse a persisted verdict if present (idempotent across reconcile ticks).
        $verdict = null;
        if (($gateStage['verdict'] ?? null) !== null && $gateStage['verdict'] !== '') {
            $verdict = StageVerdict::fromArray(json_decode($gateStage['verdict'], true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR));
        }
        if ($verdict === null) {
            $gateOutput = (string) ($gateStage['result_summary'] ?? '');
            if ($this->stageGateEvaluator !== null) {
                $priorSummaries = [];
                foreach ($stages as $s) {
                    if ((int) $s['stage_index'] !== (int) $gateStage['stage_index']) {
                        $priorSummaries[] = sprintf('%s: %s', $s['role'], (string) ($s['result_summary'] ?? ''));
                    }
                }
                $verdict = $this->stageGateEvaluator->judge(
                    goal: (string) $loop['goal'],
                    acceptanceCriteria: $loop['termination_criteria'] ?? null,
                    gateStageOutput: $gateOutput,
                    priorStageSummaries: $priorSummaries,
                );
            } else {
                $verdict = StageVerdict::gateFromText($gateOutput);
            }
            $this->loopStore->recordStageVerdict((string) $gateStage['id'], json_encode($verdict->toArray(), JSON_UNESCAPED_SLASHES));
        }

        if ($verdict->isApproved()) {
            return IterationOutcome::Complete;
        }

        // Rework: increment the breaker counter, mark the iteration needs_rework.
        $attempts = $this->reworkAttempts($loop) + 1;
        $this->loopStore->updateLoopMetadata((string) $loop['id'], ['rework_attempts' => $attempts]);
        $this->loopStore->updateIterationStatus($iterationId, 'needs_rework', $this->buildIterationSummary($stages));

        $maxAttempts = $this->maxReworkAttempts($loop);
        if ($attempts >= $maxAttempts) {
            $this->escalateBlocked($loop, $iterationId, sprintf('Not converging: %d rework attempts without approval.', $attempts), $verdict->findings);
            return IterationOutcome::Blocked;
        }

        return IterationOutcome::Continue;
    }

    /**
     * @param array<string, mixed> $loop
     */
    private function reworkAttempts(array $loop): int
    {
        if (is_string($loop['metadata'] ?? null) && $loop['metadata'] !== '') {
            $meta = json_decode($loop['metadata'], true);
            if (is_array($meta)) {
                return (int) ($meta['rework_attempts'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $loop
     */
    private function maxReworkAttempts(array $loop): int
    {
        $config = json_decode((string) $loop['configuration'], true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        $value = is_array($config) ? (int) ($config['max_rework_attempts'] ?? self::DEFAULT_MAX_REWORK_ATTEMPTS) : self::DEFAULT_MAX_REWORK_ATTEMPTS;

        return $value > 0 ? $value : self::DEFAULT_MAX_REWORK_ATTEMPTS;
    }
```

3b. Update the termination `match` in `evaluateIteration()` to pass the new args:
```php
            $outcome = match ($definition->terminationCondition->type) {
                TerminationType::EvaluationBound => $this->evaluateEvaluationBound($definition, $stages, $iterationNumber, $loop, $iteration['id']),
                TerminationType::IterationBound => $this->evaluateIterationBound($iterationNumber, $loop),
                TerminationType::GoalBound => $this->evaluateGoalBound($definition, $stages, $iterationNumber, $loop),
            };
```

3c. Update the iteration-status mapping and terminal handling below the match. Replace the existing mapping block with:
```php
        // Update iteration status based on outcome. Blocked leaves the iteration
        // needs_rework (already set by escalateBlocked) so an operator retry can
        // reopen it.
        if ($outcome !== IterationOutcome::Blocked) {
            $iterationStatus = match ($outcome) {
                IterationOutcome::Complete, IterationOutcome::LimitReached => 'completed',
                IterationOutcome::Continue => $definition->terminationCondition->type === TerminationType::EvaluationBound
                    ? 'needs_rework'
                    : 'completed',
                IterationOutcome::Failed => 'failed',
                IterationOutcome::Blocked => 'needs_rework',
            };
            $summary = $this->buildIterationSummary($stages);
            $this->loopStore->updateIterationStatus($iteration['id'], $iterationStatus, $summary);
        }

        if ($outcome === IterationOutcome::Continue) {
            $this->advanceIteration($loopId, $definition, $loop['project_id'], $loop['goal']);
        }

        if ($outcome === IterationOutcome::Complete || $outcome === IterationOutcome::LimitReached) {
            $this->loopStore->updateLoopStatus($loopId, 'completed');
        }

        if ($outcome === IterationOutcome::Failed) {
            $this->loopStore->updateLoopStatus($loopId, 'failed');
        }
        // Blocked: escalateBlocked already set loop status = blocked; do not advance.

        return $outcome;
```
Remove the now-superseded original mapping/advance/status block so this logic exists once.

> **Note:** `evaluateEvaluationBound` previously received `($stages, $iterationNumber, $loop)`. The `$iterationNumber` param is retained for signature stability even though the new body keys off `rework_attempts`; keep it to avoid touching the `match` arm ordering.

3d. Make `max_rework_attempts` configurable per definition. In `startLoop()`, after `$configuration = $definition->toArray();` (and the `resolved_parameters` block), preserve the raw override so it survives the snapshot that `maxReworkAttempts()` later reads:
```php
        if (isset($substitutedData['max_rework_attempts'])) {
            $configuration['max_rework_attempts'] = (int) $substitutedData['max_rework_attempts'];
        }
```
(Placed alongside the existing `$configuration['resolved_parameters'] = ...` assignment. `$substitutedData` is the parameter-substituted raw definition already in scope in `startLoop()`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorGateTest.php`
Expected: PASS (4 passing).

- [ ] **Step 5: Run the full Agent + Storage suites**

Run: `./vendor/bin/pest tests/Unit/Agent tests/Unit/Storage tests/Unit/Contract`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Agent/LoopExecutor.php tests/Unit/Agent/LoopExecutorGateTest.php
git commit -m "feat(loops): verdict-driven gate with needs_rework circuit-breaker to blocked"
```

---

## Task 10: LoopExecutor — operator-guidance injection on retry

**Files:**
- Modify: `src/Agent/LoopExecutor.php` (`prepareNextStage()`, `buildStagePrompt()`)
- Test: `tests/Unit/Agent/LoopExecutorGuidanceTest.php`

**Interfaces:**
- Consumes: loop metadata key `pending_guidance` (string), written by the retry path (Tasks 12/13).
- Produces: when `pending_guidance` is present, the next stage's prompt includes an `## Operator Guidance` section; the key is cleared after it is consumed (so it injects once, into the reopened stage).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

test('pending_guidance is injected into the next stage prompt and then cleared', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo); $projectStore = new ProjectStore($pdo);
    $executor = new LoopExecutor($loopStore, $projectStore);

    $projectId = $projectStore->createProject(title: 'p', slug: 'gd-1', description: 'd');
    $config = [
        'name' => 'harness',
        'roles' => [['role' => 'coder', 'prompt' => 'implement it']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 3]],
    ];
    $loopId = $loopStore->createLoop('harness', 'goal', $config, projectId: $projectId, maxIterations: 3);
    $loopStore->updateLoopMetadata($loopId, ['pending_guidance' => 'Use approach B instead of A.']);

    $result = $executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('## Operator Guidance');
    expect($result->prompt)->toContain('Use approach B instead of A.');

    // Consumed and cleared.
    $meta = json_decode($loopStore->getLoop($loopId)['metadata'], true);
    expect($meta['pending_guidance'] ?? null)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorGuidanceTest.php`
Expected: FAIL — no `## Operator Guidance` section.

- [ ] **Step 3: Write minimal implementation**

In `prepareNextStage()`, after `$loop` is fetched and before building the prompt, read the guidance and pass it in. Locate the `$prompt = $this->buildStagePrompt(...)` call and add a `pendingGuidance` argument sourced from loop metadata:
```php
        $pendingGuidance = null;
        if (is_string($loop['metadata'] ?? null) && $loop['metadata'] !== '') {
            $meta = json_decode($loop['metadata'], true);
            if (is_array($meta) && is_string($meta['pending_guidance'] ?? null) && $meta['pending_guidance'] !== '') {
                $pendingGuidance = (string) $meta['pending_guidance'];
            }
        }
```
Add `pendingGuidance: $pendingGuidance,` to the `buildStagePrompt(...)` call.

After the prompt is built (still inside `prepareNextStage`, before `return new LoopStageResult(...)`), clear it so it injects once:
```php
        if ($pendingGuidance !== null) {
            $this->loopStore->updateLoopMetadata($loopId, ['pending_guidance' => null]);
        }
```

In `buildStagePrompt()`, add the parameter (append to the signature, default null):
```php
        ?string $projectId = null,
        ?string $pendingGuidance = null,
    ): string {
```
And inject the section — insert just before the final `$sections[] = "## Your Task\n{$rolePrompt}";` line:
```php
        if ($pendingGuidance !== null && $pendingGuidance !== '') {
            $sections[] = "## Operator Guidance\nThe operator retried this loop with the following direction. Follow it:\n{$pendingGuidance}";
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorGuidanceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Agent/LoopExecutor.php tests/Unit/Agent/LoopExecutorGuidanceTest.php
git commit -m "feat(loops): inject operator guidance note into the reopened stage prompt"
```

---

## Task 11: LoopManager — tick skips blocked + orphan recovery + dispatch idempotency

**Files:**
- Modify: `src/Api/LoopManager.php` (`advanceLoop()`, `reconcileLoop()`)
- Test: `tests/Unit/Api/LoopManagerDurabilityTest.php`

**Interfaces:**
- Consumes: `LoopStore` state, `SessionStorage::getTask`.
- Produces: `blocked` loops are never advanced (they are absent from `listLoops('running')`; assert no dispatch). A `running` stage whose task is missing is recovered: reset to `pending` with a `dispatch_attempts` guard in stage metadata; over the bound → stage failed. A `pending` stage that already carries a `task_id` reconciles that task instead of re-dispatching.

**Context:** `tick()` and `reconcile()` iterate `listLoops('running')`, so a `blocked` loop is naturally skipped. This task makes it an explicit, tested guard and adds recovery in `advanceLoop()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;

function durabilityHarness(): array
{
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $sessionStorage = new SessionStorage($pdo);
    $artifactStore = new ArtifactStore($pdo);
    $executor = new LoopExecutor($loopStore, $projectStore, $sessionStorage);
    $manager = new LoopManager($sessionStorage, $loopStore, $executor, $artifactStore);
    return [$manager, $loopStore, $projectStore, $sessionStorage];
}

test('tick does not advance a blocked loop', function () {
    [$manager, $loopStore, $projectStore] = durabilityHarness();
    $projectId = $projectStore->createProject(title: 'p', slug: 'd-1', description: 'd');
    $config = ['name' => 'x', 'roles' => [['role' => 'coder', 'prompt' => 'do']], 'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 1]]];
    $loopId = $loopStore->createLoop('x', 'goal', $config, projectId: $projectId, maxIterations: 1);
    $iterId = $loopStore->createIteration($loopId, 1);
    $loopStore->createStage($iterId, 0, 'coder');
    $loopStore->updateLoopStatus($loopId, 'blocked');

    $manager->tick();

    // No stage dispatched — the single stage is still pending with no task.
    $stage = $loopStore->getCurrentState($loopId)['stages'][0];
    expect($stage['status'])->toBe('pending');
    expect($stage['task_id'])->toBeNull();
});

test('a running stage whose task is missing is reset to pending for re-dispatch', function () {
    [$manager, $loopStore, $projectStore] = durabilityHarness();
    $projectId = $projectStore->createProject(title: 'p', slug: 'd-2', description: 'd');
    $config = ['name' => 'x', 'roles' => [['role' => 'coder', 'prompt' => 'do']], 'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 1]]];
    $loopId = $loopStore->createLoop('x', 'goal', $config, projectId: $projectId, maxIterations: 1);
    $iterId = $loopStore->createIteration($loopId, 1);
    $stageId = $loopStore->createStage($iterId, 0, 'coder');
    // Simulate a crashed dispatch: stage running, but its task id does not exist.
    $loopStore->updateStage(id: $stageId, status: 'running', taskId: 'ghost-task-id');

    $manager->reconcile();

    $stage = $loopStore->getStage($stageId);
    expect(in_array($stage['status'], ['pending', 'failed'], true))->toBeTrue();
    $meta = $stage['metadata'] !== null ? json_decode($stage['metadata'], true) : [];
    expect(($meta['dispatch_attempts'] ?? 0))->toBeGreaterThanOrEqual(1);
});
```

> **Implementer note:** confirm the `SessionStorage` and `ArtifactStore` constructors accept a single `PDO` (read `src/Storage/SessionStorage.php` / `ArtifactStore.php`). If they need more, build them the way `tests/Unit/Api` or existing LoopManager tests already do — search `tests/` for an existing `new LoopManager(` to copy the exact wiring.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/LoopManagerDurabilityTest.php`
Expected: FAIL — orphaned running stage is never recovered (loop stalls; `dispatch_attempts` absent).

- [ ] **Step 3: Write minimal implementation**

3a. In `advanceLoop()`, the guard "if any stage is running, return" must first check whether that running stage's task is recoverable. Replace the running-stage loop:
```php
        // If any stage is currently running, verify its task is alive; recover
        // orphans (missing task) rather than stalling forever.
        foreach ($stages as $stage) {
            if ($stage['status'] !== 'running') {
                continue;
            }
            $taskId = (string) ($stage['task_id'] ?? '');
            $task = $taskId !== '' ? $this->storage->getTask($taskId) : null;
            if ($task === null) {
                $this->recoverOrphanStage($stage);
                return; // recovered this tick; next tick re-dispatches or fails
            }

            return; // a live task is running — wait for reconciliation
        }
```

3b. Add the recovery helper (private):
```php
    /**
     * Recover a running stage whose background task has vanished (crashed
     * dispatch or deleted task). Resets to pending for one re-dispatch, bounded
     * by a dispatch_attempts guard; over the bound, the stage fails.
     *
     * @param array<string, mixed> $stage
     */
    private function recoverOrphanStage(array $stage): void
    {
        $meta = [];
        if (is_string($stage['metadata'] ?? null) && $stage['metadata'] !== '') {
            $decoded = json_decode($stage['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $attempts = (int) ($meta['dispatch_attempts'] ?? 0) + 1;
        $meta['dispatch_attempts'] = $attempts;

        if ($attempts > 2) {
            $this->executor->failStage((string) $stage['id'], 'Stage task was lost and exceeded re-dispatch attempts.');
            $this->loopStore->updateStage(id: (string) $stage['id'], status: 'failed', metadata: $meta);
            return;
        }

        // Reset to pending with a cleared task link so the next tick re-dispatches.
        $this->loopStore->updateStage(id: (string) $stage['id'], status: 'pending', metadata: $meta);
        $this->clearStageTask((string) $stage['id']);
    }

    /**
     * Null out a stage's task_id so it re-dispatches cleanly. Uses a direct
     * update because updateStage() COALESCEs (won't overwrite with null).
     */
    private function clearStageTask(string $stageId): void
    {
        $this->loopStore->clearStageTask($stageId);
    }
```

3c. Because `LoopStore::updateStage()` COALESCEs `task_id` (cannot null it), add a small helper to `LoopStore` (Stage CRUD section):
```php
    /**
     * Clear a stage's task link so it can be re-dispatched after orphan recovery.
     */
    public function clearStageTask(string $stageId): void
    {
        $stmt = $this->db->prepare('UPDATE loop_stages SET task_id = NULL WHERE id = ?');
        $stmt->execute([$stageId]);
    }
```

3d. Dispatch idempotency: in `advanceLoop()`, after `prepareNextStage()` returns a stage result but before creating a task, guard against a pending stage that already has a task:
```php
        $existingTaskId = (string) ($this->loopStore->getStage($stageResult->stageId)['task_id'] ?? '');
        if ($existingTaskId !== '') {
            $existingTask = $this->storage->getTask($existingTaskId);
            if ($existingTask !== null) {
                // A task already exists for this stage (crashed between create and
                // status update). Re-link as running; reconciliation handles it.
                $this->loopStore->updateStage(id: $stageResult->stageId, status: 'running', taskId: $existingTaskId);
                return;
            }
        }
```
Place this immediately after the `if ($stageResult === null) { ... }` early-return and before `$this->advancingLoops[$loopId] = true;`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Api/LoopManagerDurabilityTest.php`
Expected: PASS (2 passing).

- [ ] **Step 5: Commit**

```bash
git add src/Api/LoopManager.php src/Storage/LoopStore.php tests/Unit/Api/LoopManagerDurabilityTest.php
git commit -m "feat(loops): orphan-stage recovery, dispatch idempotency, explicit blocked-skip"
```

---

## Task 12: LoopManager — blocked notification + actionable kind

**Files:**
- Modify: `src/Api/LoopManager.php` (`evaluateAndAdvance()`, `publishLoopNotification()`)
- Test: `tests/Unit/Api/LoopManagerBlockedNotificationTest.php`

**Interfaces:**
- Consumes: `IterationOutcome::Blocked`, loop metadata `escalation`.
- Produces: on `IterationOutcome::Blocked`, `evaluateAndAdvance()` publishes a high-priority **actionable** notification with kind `loop.blocked`, detail = the escalation reason. `publishLoopNotification()` routes `loop.blocked` through `actionable()` (like `loop.failed`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Notification\NotificationPublisher;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;

test('a loop that blocks publishes an actionable loop.blocked notification', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $sessionStorage = new SessionStorage($pdo);
    $artifactStore = new ArtifactStore($pdo);

    $captured = [];
    $publisher = new class ($captured) extends NotificationPublisher {
        public array $kinds = [];
        public function __construct(private array &$captured) {}
        public function actionable(string $sessionId, string $kind, string $title, ?string $message = null, ?string $fingerprint = null, string $sourceType = '', string $sourceId = '', array $metadata = [], string $priority = 'normal'): void
        { $this->captured[] = ['method' => 'actionable', 'kind' => $kind]; }
        public function info(string $sessionId, string $kind, string $title, ?string $message = null, ?string $fingerprint = null, string $sourceType = '', string $sourceId = '', array $metadata = [], string $priority = 'normal'): void
        { $this->captured[] = ['method' => 'info', 'kind' => $kind]; }
    };

    $executor = new LoopExecutor($loopStore, $projectStore, $sessionStorage);
    $manager = new LoopManager($sessionStorage, $loopStore, $executor, $artifactStore, $publisher);

    // Build a loop whose single producer stage self-signals BLOCKED.
    $projectId = $projectStore->createProject(title: 'p', slug: 'bn-1', description: 'd');
    $config = ['name' => 'x', 'roles' => [['role' => 'coder', 'prompt' => 'do']], 'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 3]]];
    $loopId = $loopStore->createLoop('x', 'goal', $config, sessionId: 'sess-x', projectId: $projectId, maxIterations: 3);
    $stage = $loopStore->getCurrentState($loopId)['stages'][0];
    $loopStore->updateStage(id: $stage['id'], status: 'completed', resultSummary: "STATUS: BLOCKED\nstuck");

    $manager->reconcile();

    $actionable = array_filter($captured, static fn(array $c): bool => $c['method'] === 'actionable' && $c['kind'] === 'loop.blocked');
    expect($actionable)->not->toBeEmpty();
});
```

> **Implementer note:** verify the real `NotificationPublisher::actionable()`/`info()` signatures in `src/Notification/NotificationPublisher.php` and match the stub's parameter list exactly (adjust names/order/defaults if they differ). The assertion (an `actionable` call with kind `loop.blocked`) stays as written. If `NotificationPublisher` is `final`, make the stub `implements` its interface or use a partial mock via an anonymous subclass of a test double already used in `tests/Unit/Api`.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/LoopManagerBlockedNotificationTest.php`
Expected: FAIL — no `loop.blocked` actionable published.

- [ ] **Step 3: Write minimal implementation**

3a. In `evaluateAndAdvance()`, add a `Blocked` branch alongside the existing `Failed`/`Complete` handling:
```php
        } elseif ($outcome === IterationOutcome::Blocked) {
            $loop = $this->loopStore->getLoop($loopId);
            $reason = 'Loop blocked — operator retry required.';
            if ($loop !== null && is_string($loop['metadata'] ?? null) && $loop['metadata'] !== '') {
                $meta = json_decode($loop['metadata'], true);
                if (is_array($meta) && is_string($meta['escalation']['reason'] ?? null)) {
                    $reason = (string) $meta['escalation']['reason'];
                }
            }
            $this->publishLoopNotification(
                loopId: $loopId,
                outcome: 'blocked',
                title: 'Loop blocked — needs your input',
                detail: mb_substr($reason, 0, 200),
                priority: 'high',
            );
        }
```

3b. In `publishLoopNotification()`, route `loop.blocked` through `actionable()`. Change the actionable branch condition:
```php
            if ($kind === 'loop.failed' || $kind === 'loop.blocked') {
                $this->publisher->actionable(
```
(The existing `$kind` is already computed as `"loop.{$outcome}"`, so `outcome: 'blocked'` yields `loop.blocked` automatically.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Api/LoopManagerBlockedNotificationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Api/LoopManager.php tests/Unit/Api/LoopManagerBlockedNotificationTest.php
git commit -m "feat(loops): publish an actionable loop.blocked notification on escalation"
```

---

## Task 13: ApiCommand — build StageGateEvaluator + inject memoryStore

**Files:**
- Modify: `src/Command/ApiCommand.php` (~lines 271–290, the loop wiring block)
- Test: manual smoke via `composer test` (no unit test — this is DI wiring; covered indirectly by the suite booting).

**Interfaces:**
- Consumes: `StageGateEvaluator`, `MemoryStore`, existing `GoalEvaluator` wiring, `$boot->memoryStore()`.
- Produces: `LoopExecutor` constructed with `stageGateEvaluator` and `memoryStore` populated when a utility model is configured.

- [ ] **Step 1: Add the import**

At the top of `src/Command/ApiCommand.php`, alongside `use CoquiBot\Coqui\Agent\GoalEvaluator;`:
```php
use CoquiBot\Coqui\Agent\StageGateEvaluator;
```

- [ ] **Step 2: Build the evaluator and inject both deps**

Replace the loop-wiring block (currently building `$goalEvaluator` and `new LoopExecutor(...)`) with:
```php
            // Resolve utility model provider for goal_bound + gate evaluation.
            $goalEvaluator = null;
            $stageGateEvaluator = null;
            try {
                $factory = $boot->providerFactory(new ReactHttpClientAdapter());
                $utilityModel = $boot->roleResolver()->resolveUtility();
                if ($utilityModel !== '') {
                    $goalEvaluator = new GoalEvaluator($factory->create($utilityModel));
                    $stageGateEvaluator = new StageGateEvaluator($factory->create($utilityModel));
                }
            } catch (\Throwable) {
                // Evaluation degrades gracefully — gate falls back to keyword matching.
            }

            $loopExecutor = new LoopExecutor(
                loopStore: $loopStore,
                projectStore: $projectStore,
                sessionStorage: $storage,
                goalEvaluator: $goalEvaluator,
                stageGateEvaluator: $stageGateEvaluator,
                memoryStore: $boot->memoryStore(),
            );
            $loopManager = new LoopManager($storage, $loopStore, $loopExecutor, $artifactStore, $notificationPublisher);
```

- [ ] **Step 3: Run the full suite to confirm boot wiring is intact**

Run: `composer test`
Expected: PASS (no boot/DI regressions).

- [ ] **Step 4: Commit**

```bash
git add src/Command/ApiCommand.php
git commit -m "feat(loops): wire StageGateEvaluator and memoryStore into LoopExecutor"
```

---

## Task 14: LoopToolkit — loop_control retry action (+ optional note) and status surfacing

**Files:**
- Modify: `src/Toolkit/LoopToolkit.php` (`loopControlTool()` values + match; add `executeRetry()`)
- Test: `tests/Unit/Toolkit/LoopToolkitRetryTest.php`

**Interfaces:**
- Consumes: `LoopStore` reset/reopen helpers, `updateLoopStatus`, `updateLoopMetadata`, `listIterations`.
- Produces: `loop_control(action: "retry", id: ..., note?: ...)` — reopens the latest iteration of a `blocked` (or paused/stopped) loop, resets its stages, clears the breaker counter, stores `pending_guidance` when a note is given, and sets the loop `running`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Toolkit\LoopToolkit;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;

test('loop_control retry revives a blocked loop, clears the breaker, and stores the note', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);

    $config = ['name' => 'x', 'roles' => [['role' => 'coder', 'prompt' => 'do']], 'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 3]]];
    $loopId = $loopStore->createLoop('x', 'goal', $config, maxIterations: 3);
    $iterId = $loopStore->createIteration($loopId, 1);
    $stageId = $loopStore->createStage($iterId, 0, 'coder');
    $loopStore->updateStage(id: $stageId, status: 'completed', resultSummary: 'blocked round');
    $loopStore->updateIterationStatus($iterId, 'needs_rework', 'blocked');
    $loopStore->updateLoopMetadata($loopId, ['rework_attempts' => 3, 'escalation' => ['reason' => 'stuck']]);
    $loopStore->updateLoopStatus($loopId, 'blocked');

    $toolkit = new LoopToolkit($loopStore, new LoopDiscovery());
    $control = collect($toolkit->tools())->first(fn($t) => $t->name() === 'loop_control');
    $result = $control->handler()(['action' => 'retry', 'id' => $loopId, 'note' => 'Use approach B.']);

    expect($result->isError())->toBeFalse();
    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');
    $meta = json_decode($loop['metadata'], true);
    expect($meta['rework_attempts'])->toBe(0);
    expect($meta['pending_guidance'])->toBe('Use approach B.');
    // Stage reset to pending for re-dispatch.
    expect($loopStore->getStage($stageId)['status'])->toBe('pending');
});
```

> **Implementer note:** confirm how the tool's callable is invoked in existing LoopToolkit tests (the exact `Tool` accessor — `->name()`, `->handler()`, or a `call()` shim — and whether `collect()` is available or you should iterate `tools()` manually). Read `tests/Unit/Toolkit/` for the established pattern and match it; the assertions stay as written. Confirm `LoopDiscovery`'s constructor args the same way.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Toolkit/LoopToolkitRetryTest.php`
Expected: FAIL — `retry` is an unknown action.

- [ ] **Step 3: Write minimal implementation**

3a. In `loopControlTool()`, add `retry` to the action enum and a `note` parameter. Update the `values:` list to include `'retry'`, add a `StringParameter(name: 'note', ..., required: false)`, and extend the match:
```php
                return match ($action) {
                    'pause' => $this->executePause($id),
                    'resume' => $this->executeResume($id),
                    'stop' => $this->executeStop($id),
                    'retry' => $this->executeRetry($id, isset($input['note']) ? (string) $input['note'] : null),
                    default => ToolResult::error("Unknown action: {$action}"),
                };
```
Update the tool description to mention retry: `'Pause, resume, stop, or retry a loop. retry revives a blocked loop and reopens its latest iteration; pass an optional note to steer the rework.'`

3b. Add `executeRetry()` (near `executeResume()`):
```php
    private function executeRetry(string $id, ?string $note): ToolResult
    {
        $loop = $this->loopStore->getLoop($id);
        if ($loop === null) {
            return ToolResult::error("Loop \"{$id}\" not found.");
        }
        if (!in_array((string) $loop['status'], ['blocked', 'paused', 'cancelled'], true)) {
            return ToolResult::error("Cannot retry loop — current status is \"{$loop['status']}\". Retry applies to blocked, paused, or stopped loops.");
        }

        $iterations = $this->loopStore->listIterations($id);
        if ($iterations === []) {
            return ToolResult::error('Loop has no iterations to retry.');
        }
        $latest = $iterations[array_key_last($iterations)];
        $iterationId = (string) $latest['id'];

        $this->loopStore->resetStagesForIteration($iterationId);
        $this->loopStore->resetIterationForRetry($iterationId);
        $this->loopStore->updateLoopMetadata($id, [
            'rework_attempts' => 0,
            'pending_guidance' => ($note !== null && $note !== '') ? $note : null,
        ]);
        $this->loopStore->updateLoopProgress($id, (int) ($latest['iteration_number'] ?? 0), 0);
        $this->loopStore->updateLoopStatus($id, 'running');

        $suffix = ($note !== null && $note !== '') ? ' Guidance recorded for the next round.' : '';

        return ToolResult::success("Loop \"{$id}\" retried — reopened iteration {$latest['iteration_number']} and cleared the block.{$suffix}");
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Toolkit/LoopToolkitRetryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Toolkit/LoopToolkit.php tests/Unit/Toolkit/LoopToolkitRetryTest.php
git commit -m "feat(loops): loop_control retry action with optional guidance note"
```

---

## Task 15: API LoopHandler — /retry accepts note + applies on blocked; serializers learn blocked + expose verdict/escalation

**Files:**
- Modify: `src/Api/Handler/LoopHandler.php` (`retryIteration()` ~865; loop serialization ~403–425 and `live()` ~445; iteration/stage serialization to include `verdict`)
- Test: `tests/Unit/Api/LoopHandlerBlockedTest.php` (or extend an existing `LoopHandler` test file if one exists)

**Interfaces:**
- Consumes: `LoopStore`, request body.
- Produces: `POST /loops/{id}/iterations/{iterationId}/retry` accepts an optional `note` (stored as `pending_guidance`, `rework_attempts` cleared) and permits retry when the loop status is `blocked`; loop serialization exposes `escalation` (from metadata) and stage serialization exposes `verdict`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Storage\LoopStore;

// This test drives LoopStore state and asserts the handler's serialization
// includes escalation + verdict, and that retry accepts a note on a blocked loop.
// Construct the handler the way the existing LoopHandler tests do — read
// tests/Unit/Api/ for the established request/Response harness and copy it.

test('a blocked loop can be retried with a note via the REST retry path', function () {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);

    $config = ['name' => 'x', 'roles' => [['role' => 'coder', 'prompt' => 'do']], 'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 3]]];
    $loopId = $loopStore->createLoop('x', 'goal', $config, maxIterations: 3);
    $iterId = $loopStore->createIteration($loopId, 1);
    $stageId = $loopStore->createStage($iterId, 0, 'coder');
    $loopStore->updateStage(id: $stageId, status: 'completed', resultSummary: 'r');
    $loopStore->updateIterationStatus($iterId, 'needs_rework', 'blocked');
    $loopStore->updateLoopStatus($loopId, 'blocked');

    // Assert the store-level preconditions the handler relies on.
    expect($loopStore->getLoop($loopId)['status'])->toBe('blocked');
    expect($loopStore->getIteration($iterId)['status'])->toBe('needs_rework');
});
```

> **Implementer note:** the `LoopHandler` tests need the full handler harness (PSR-7 request, `LoopExecutor`, `Router`). Read `tests/Unit/Api/` for the existing `LoopHandler` test setup and extend it with: (a) a retry request carrying `{"note": "..."}` against a `blocked` loop returns 200 and sets `pending_guidance`; (b) `get()`/`live()` JSON includes `escalation` and each stage includes `verdict`. If no `LoopHandler` test harness exists, the store-level assertion above plus the code changes below are the minimum; add the HTTP-level assertions using the same harness the `retryIteration` endpoint is otherwise exercised by.

- [ ] **Step 2: Run test to verify it fails / establish baseline**

Run: `./vendor/bin/pest tests/Unit/Api/LoopHandlerBlockedTest.php`
Expected: PASS at the store level (baseline), then extend with HTTP-level assertions that FAIL until Step 3.

- [ ] **Step 3: Write minimal implementation**

3a. In `retryIteration()`, relax the loop-status precondition and accept a note. Change the guard that requires the loop not be running so it also permits `blocked`, and read the note from the body:
```php
        $currentStatus = (string) ($loop['status'] ?? '');
        if ($currentStatus === 'running') {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Pause or stop the loop before retrying an iteration.');
        }
        // blocked | paused | cancelled | failed-iteration are all retryable.

        $body = json_decode((string) $request->getBody(), true);
        $note = is_array($body) && is_string($body['note'] ?? null) && $body['note'] !== '' ? (string) $body['note'] : null;
```
Keep the existing iteration-status guard (`failed`/`needs_rework`). After the existing `resetStagesForIteration` + `resetIterationForRetry` calls, add the metadata update:
```php
        $this->store->updateLoopMetadata($id, [
            'rework_attempts' => 0,
            'pending_guidance' => $note,
        ]);
```

3b. In the loop serialization (the `get()`/list array with `'status' => $loop['status']`), add the escalation passthrough. Where the loop response array is built, add:
```php
            'escalation' => $this->decodeEscalation($loop),
```
And add the helper:
```php
    /**
     * @param array<string, mixed> $loop
     * @return array<string, mixed>|null
     */
    private function decodeEscalation(array $loop): ?array
    {
        if (!is_string($loop['metadata'] ?? null) || $loop['metadata'] === '') {
            return null;
        }
        $meta = json_decode($loop['metadata'], true);

        return is_array($meta) && is_array($meta['escalation'] ?? null) ? $meta['escalation'] : null;
    }
```

3c. In stage serialization (wherever a stage array is built for `get()`/`live()`/iterations — the loop over `$stages` that emits `'status' => $stageStatus`), add the verdict:
```php
                'verdict' => (isset($stage['verdict']) && $stage['verdict'] !== '')
                    ? json_decode((string) $stage['verdict'], true)
                    : null,
```

3d. If any status list/whitelist in this handler enumerates allowed statuses (e.g. the `list()` filter validates against a set), add `'blocked'` to it. Search the file for `'completed', 'failed', 'cancelled'` and include `'blocked'` wherever loop statuses are whitelisted.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Api/LoopHandlerBlockedTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Api/Handler/LoopHandler.php tests/Unit/Api/LoopHandlerBlockedTest.php
git commit -m "feat(loops): REST retry accepts note + blocked; expose verdict/escalation fields"
```

---

## Task 16: REPL /loops — display blocked status + escalation reason

**Files:**
- Modify: `src/Repl/Handler/LoopHandler.php` (`handleList()` filter ~131 + status match ~147; `handleStatus()` status/stage rendering ~224–249)
- Test: none (terminal display). Verify manually per Step 3.

**Interfaces:**
- Consumes: loop `status`, loop `metadata.escalation`.
- Produces: `/loops` list renders `blocked` with a distinct marker; `/loops status <id>` shows the escalation reason when the loop is blocked. No new execution code — display only.

- [ ] **Step 1: Add `blocked` to the list filter whitelist**

In `handleList()`, extend the allowed-status list:
```php
        $filter = in_array($statusFilter, ['running', 'paused', 'completed', 'failed', 'cancelled', 'blocked'], true)
            ? $statusFilter
            : null;
```

- [ ] **Step 2: Add `blocked` to the list status marker**

In the `handleList()` status `match ($loop['status'])`, add a `blocked` arm (use a distinct colored glyph consistent with the existing set):
```php
            $status = match ($loop['status']) {
                'running' => '<fg=green>▶</>',
                'paused' => '<fg=yellow>◉</>',
                'completed' => '<fg=cyan>✓</>',
                'failed' => '<fg=red>✗</>',
                'blocked' => '<fg=magenta>⚑</>',
                default => $loop['status'],
            };
```
(Match the exact glyphs/colors already present in the file — copy the existing arms verbatim and add only the `blocked` line.)

- [ ] **Step 3: Show the escalation reason in `handleStatus()`**

In `handleStatus()`, after the `['Status' => $loop['status']]` row is rendered, add a blocked-reason row:
```php
        if (($loop['status'] ?? '') === 'blocked' && is_string($loop['metadata'] ?? null) && $loop['metadata'] !== '') {
            $meta = json_decode($loop['metadata'], true);
            $reason = is_array($meta) && is_string($meta['escalation']['reason'] ?? null) ? $meta['escalation']['reason'] : 'Blocked — operator retry required.';
            $io->warning("Blocked: {$reason}");
            $io->text('Retry via the agent: loop_control(action: "retry", id: "' . $target . '", note: "…") or the API /retry endpoint.');
        }
```

- [ ] **Step 4: Manually verify rendering**

Run: `./vendor/bin/pest tests/Unit/Repl` (ensure no REPL tests broke), then eyeball the code path.
Expected: existing REPL tests PASS; `blocked` handled in both list and status views.

- [ ] **Step 5: Commit**

```bash
git add src/Repl/Handler/LoopHandler.php
git commit -m "feat(loops): REPL /loops displays blocked status and escalation reason"
```

---

## Task 17: Documentation + source map

**Files:**
- Modify: `docs/LOOPS.md`
- Modify: `config/source.json`

**Interfaces:** none (docs).

- [ ] **Step 1: Update `docs/LOOPS.md`**

Add/adjust these sections (verify wording against the shipped behavior):
- **Termination Conditions** table: for `evaluation_bound`, replace "Last stage output contains an approval keyword" with the structured gate — a `StageGateEvaluator` verdict (`requirements_met` + `quality_pass` + severity findings); keyword match is the no-utility-model fallback.
- New **Stage verdicts and the gate** subsection: the `StageStatus` taxonomy (`done`/`done_with_concerns`/`blocked`/`needs_context`), that gate stages are the last role of an `evaluation_bound` loop or a role with `gate: true`, and that non-gate producer stages self-signal (default done; `STATUS: BLOCKED`/`NEEDS_CONTEXT` sentinel).
- New **Circuit-breaker and the `blocked` state** subsection: rejected rounds mark the iteration `needs_rework` and increment `rework_attempts`; after `max_rework_attempts` (default 3, definition-overridable via top-level `max_rework_attempts`) the loop enters `blocked` — a retryable stop that fires an actionable notification, distinct from `completed (limit reached)`.
- New **Artifact-required stages** subsection: `artifact_required: true` is a hard gate (no artifact → blocked); `memory_required: true` is a soft Minor concern only.
- New **Unblocking** subsection: `loop_control(action: "retry", id, note?)` and `POST /loops/{id}/iterations/{iterationId}/retry` (optional `note`) reopen the latest iteration, clear the breaker, and inject the note as operator guidance.
- **Loop Lifecycle**: add `blocked` to the documented status set.

- [ ] **Step 2: Update `config/source.json`**

Add/adjust entries for the new/changed source: `src/Contract/StageStatus.php`, `StageSeverity.php`, `StageFinding.php`, `StageVerdict.php`, `src/Agent/StageGateEvaluator.php`, and note the new responsibilities on `src/Agent/LoopExecutor.php` (verdict-driven gate + breaker + blocked escalation), `src/Api/LoopManager.php` (orphan recovery, blocked notifications), `src/Storage/LoopStore.php` (verdict column, clearStageTask), `src/Memory/MemoryStore.php` (countBySession), `src/Toolkit/LoopToolkit.php` (retry action), `src/Api/Handler/LoopHandler.php` and `src/Repl/Handler/LoopHandler.php` (blocked-aware). Follow the existing JSON shape in the file.

- [ ] **Step 3: Validate the whole suite + static analysis**

Run: `composer test && composer analyse`
Expected: BOTH green.

- [ ] **Step 4: Commit**

```bash
git add docs/LOOPS.md config/source.json
git commit -m "docs(loops): document stage verdicts, gate, circuit-breaker, blocked, unblock"
```

---

## Final Verification (Definition of Done)

- [ ] `composer test` — green.
- [ ] `composer analyse` — green (PHPStan clean; the two new nullable LoopExecutor deps and all new value objects type-check).
- [ ] `docs/LOOPS.md` reflects the gate/verdict/breaker/blocked/unblock model.
- [ ] `config/source.json` lists the new contracts + evaluator and updated responsibilities.
- [ ] Manual smoke (optional but recommended): start the API server, launch a `harness` loop with an intentionally unsatisfiable goal, confirm it reaches `blocked` (not `completed (limit reached)`), fires an actionable notification, and that `loop_control(action:"retry", note:"…")` revives it.

## Self-Review Notes (coverage map — spec → task)

- Stage Verdict primitive → Tasks 1, 2.
- Gate-only judging + fallback → Tasks 7, 9.
- Machine-readable status + non-gate self-signal halt → Tasks 1, 8.
- Two-verdict gate + severity taxonomy → Tasks 2, 9.
- Circuit-breaker → `blocked` → Task 9.
- Orphan recovery + dispatch idempotency + tick-skips-blocked → Task 11.
- Artifact-required (hard) + memory-required (soft) → Tasks 4, 6, 8.
- `blocked` actionable notification → Task 12.
- Chat/API-first unblock (`loop_control retry` + note) → Task 14.
- REST `/retry` (+ note, on blocked) + serializers expose verdict/escalation → Task 15.
- Every status consumer learns `blocked` (LoopHandler serializers, REPL list+status, notification kind) → Tasks 12, 15, 16.
- Reactor discipline (gate call mirrors GoalEvaluator inside evaluateIteration) → Task 9.
- One migration only (`loop_stages.verdict`); rest in metadata JSON → Tasks 5, 8, 9.
- Docs + source map → Task 17.
