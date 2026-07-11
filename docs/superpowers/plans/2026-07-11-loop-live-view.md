# Loop Live View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /api/v1/loops/{id}/live` returning a rich, poll-friendly snapshot of an in-flight loop — current stage + model, consumption budget (tokens/iterations/time), per-stage breakdown, and a recent-event feed.

**Architecture:** A pure read-model, `LoopLiveViewBuilder`, composes **existing** store methods (`LoopStore::getLoop/listIterations/listStages`, `SessionStorage::getTask/getTurns/getTaskEvents`) over the `loop → stage(task_id) → task → session → turns` chain. Each loop stage runs in its own session, so per-stage model + token attribution is a clean per-session lookup and the loop rollup is a straight sum. No schema changes, no new storage queries, no change to loop execution.

**Tech Stack:** PHP 8.4 (`declare(strict_types=1)`, `final readonly`), Pest (`composer test`), PHPStan level 8 (`composer analyse`).

**Spec:** `docs/superpowers/specs/2026-07-10-loop-live-view-design.md`.

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, `final` by default, one class per file, 4-space indent, constructor injection.
- Work on branch **`feat/loop-live-view`** (already holds the spec + this plan). If a fresh agent implements this, it branches off `origin/feat/loop-live-view`: `git fetch origin && git checkout -b feat/loop-live-view-impl origin/feat/loop-live-view`.
- **Never `git add -A` / `git add .`** — the primary checkout carries two intentional unstaged edits (`.gitignore` modified, `.vscode/settings.json` deleted) that must stay unstaged. Stage only exact paths. (In a fresh worktree these don't exist — status starts clean.)
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- `composer test` and `composer analyse` both green before every commit.
- **Additive only:** do not modify `LoopManager`, `LoopExecutor`, `TaskRunCommand`, or any schema. No new dependencies.
- Timestamps are ISO-8601 UTC strings (`Clock::nowUtc()` → `2026-07-11T15:17:22Z`); they sort lexicographically. `task_events` are returned id-ASC; `id` is the ordering cursor. `turns` are returned turn_number-ASC (latest = last). `getTurns` returns `tools_used` already decoded to an array.

---

### Task 1: Snapshot contracts

**Files:**
- Create: `src/Contract/LoopLiveEvent.php`
- Create: `src/Contract/LoopLiveStage.php`
- Create: `src/Contract/LoopLiveSnapshot.php`
- Test: `tests/Unit/Contract/LoopLiveSnapshotTest.php`

**Interfaces produced:**
- `LoopLiveEvent(string $timestamp, ?string $stageId, ?string $role, string $type, string $summary)` + `toArray(): array`
- `LoopLiveStage(int $iterationNumber, int $stageIndex, string $role, ?string $model, string $status, int $promptTokens, int $completionTokens, int $totalTokens, int $durationMs, list<string> $toolsUsed, ?string $resultSummary, ?string $startedAt, ?string $completedAt, ?string $taskId)` + `toArray(): array`
- `LoopLiveSnapshot(array $loop, array $position, ?array $currentStage, array $budget, list<LoopLiveStage> $stages, list<LoopLiveEvent> $recentEvents)` + `toArray(): array`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Contract/LoopLiveSnapshotTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopLiveEvent;
use CoquiBot\Coqui\Contract\LoopLiveSnapshot;
use CoquiBot\Coqui\Contract\LoopLiveStage;

test('LoopLiveEvent serializes to snake_case array', function (): void {
    $event = new LoopLiveEvent('2026-07-11T10:00:00Z', 'stage-1', 'plan', 'tool_call', 'Called grep');

    expect($event->toArray())->toBe([
        'timestamp' => '2026-07-11T10:00:00Z',
        'stage_id' => 'stage-1',
        'role' => 'plan',
        'type' => 'tool_call',
        'summary' => 'Called grep',
    ]);
});

test('LoopLiveStage nests tokens and lists tools', function (): void {
    $stage = new LoopLiveStage(
        iterationNumber: 1,
        stageIndex: 0,
        role: 'plan',
        model: 'claude-sonnet-5',
        status: 'completed',
        promptTokens: 100,
        completionTokens: 50,
        totalTokens: 150,
        durationMs: 1200,
        toolsUsed: ['grep', 'read'],
        resultSummary: 'done',
        startedAt: '2026-07-11T10:00:00Z',
        completedAt: '2026-07-11T10:00:05Z',
        taskId: 'task-1',
    );

    expect($stage->toArray())->toMatchArray([
        'iteration_number' => 1,
        'stage_index' => 0,
        'role' => 'plan',
        'model' => 'claude-sonnet-5',
        'status' => 'completed',
        'tokens' => ['prompt' => 100, 'completion' => 50, 'total' => 150],
        'duration_ms' => 1200,
        'tools_used' => ['grep', 'read'],
        'result_summary' => 'done',
        'task_id' => 'task-1',
    ]);
});

test('LoopLiveSnapshot maps nested stages and events', function (): void {
    $snapshot = new LoopLiveSnapshot(
        loop: ['id' => 'loop-1'],
        position: ['current_iteration' => 1],
        currentStage: null,
        budget: ['tokens' => ['total' => 150]],
        stages: [new LoopLiveStage(1, 0, 'plan', null, 'completed', 0, 0, 0, 0, [], null, null, null, null)],
        recentEvents: [new LoopLiveEvent('2026-07-11T10:00:00Z', null, null, 'failed', 'boom')],
    );

    $array = $snapshot->toArray();
    expect($array['loop'])->toBe(['id' => 'loop-1']);
    expect($array['current_stage'])->toBeNull();
    expect($array['stages'])->toHaveCount(1);
    expect($array['stages'][0]['role'])->toBe('plan');
    expect($array['recent_events'][0]['type'])->toBe('failed');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Contract/LoopLiveSnapshotTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Contract\LoopLiveEvent" not found`.

- [ ] **Step 3: Implement the three contracts**

`src/Contract/LoopLiveEvent.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * One entry in a loop's recent-activity feed.
 */
final readonly class LoopLiveEvent
{
    public function __construct(
        public string $timestamp,
        public ?string $stageId,
        public ?string $role,
        public string $type,
        public string $summary,
    ) {}

    /**
     * @return array{timestamp: string, stage_id: ?string, role: ?string, type: string, summary: string}
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'stage_id' => $this->stageId,
            'role' => $this->role,
            'type' => $this->type,
            'summary' => $this->summary,
        ];
    }
}
```

`src/Contract/LoopLiveStage.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * One stage's rolled-up execution data in the loop live view.
 */
final readonly class LoopLiveStage
{
    /**
     * @param list<string> $toolsUsed
     */
    public function __construct(
        public int $iterationNumber,
        public int $stageIndex,
        public string $role,
        public ?string $model,
        public string $status,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public int $durationMs,
        public array $toolsUsed,
        public ?string $resultSummary,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $taskId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'iteration_number' => $this->iterationNumber,
            'stage_index' => $this->stageIndex,
            'role' => $this->role,
            'model' => $this->model,
            'status' => $this->status,
            'tokens' => [
                'prompt' => $this->promptTokens,
                'completion' => $this->completionTokens,
                'total' => $this->totalTokens,
            ],
            'duration_ms' => $this->durationMs,
            'tools_used' => $this->toolsUsed,
            'result_summary' => $this->resultSummary,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'task_id' => $this->taskId,
        ];
    }
}
```

`src/Contract/LoopLiveSnapshot.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Rich, poll-friendly snapshot of an in-flight loop.
 */
final readonly class LoopLiveSnapshot
{
    /**
     * @param array<string, mixed>      $loop
     * @param array<string, mixed>      $position
     * @param array<string, mixed>|null $currentStage
     * @param array<string, mixed>      $budget
     * @param list<LoopLiveStage>       $stages
     * @param list<LoopLiveEvent>       $recentEvents
     */
    public function __construct(
        public array $loop,
        public array $position,
        public ?array $currentStage,
        public array $budget,
        public array $stages,
        public array $recentEvents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'loop' => $this->loop,
            'position' => $this->position,
            'current_stage' => $this->currentStage,
            'budget' => $this->budget,
            'stages' => array_map(static fn(LoopLiveStage $s): array => $s->toArray(), $this->stages),
            'recent_events' => array_map(static fn(LoopLiveEvent $e): array => $e->toArray(), $this->recentEvents),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Contract/LoopLiveSnapshotTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Contract/LoopLiveEvent.php src/Contract/LoopLiveStage.php src/Contract/LoopLiveSnapshot.php tests/Unit/Contract/LoopLiveSnapshotTest.php
git commit -m "$(cat <<'EOF'
feat(loops): add loop live-view snapshot contracts

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: LoopLiveViewBuilder

**Files:**
- Create: `src/Api/LoopLiveViewBuilder.php`
- Test: `tests/Unit/Api/LoopLiveViewBuilderTest.php`

**Interfaces:**
- Consumes: the Task 1 contracts; `LoopStore::getLoop/listIterations/listStages`, `SessionStorage::getTask/getTurns/getTaskEvents` (all existing).
- Produces: `LoopLiveViewBuilder(LoopStore $loopStore, SessionStorage $storage)` with `build(string $loopId, ?string $now = null): ?LoopLiveSnapshot` — returns `null` for an unknown loop.

- [ ] **Step 1: Write the failing test (fixtures + all behaviors)**

`tests/Unit/Api/LoopLiveViewBuilderTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopLiveViewBuilder;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Seed a loop with one completed stage (with a turn + a tool_call event) and
 * one running stage (task started, no turn yet). Returns [builder, loopId].
 *
 * @return array{0: LoopLiveViewBuilder, 1: string, 2: SessionStorage, 3: LoopStore}
 */
function seedLiveLoop(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-loop-live-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());

    $loopId = $loopStore->createLoop(
        definitionName: 'harness',
        goal: 'Ship the feature',
        configuration: ['name' => 'harness', 'roles' => [['role' => 'plan'], ['role' => 'reviewer']]],
        maxIterations: 4,
    );
    $loopStore->updateLoopProgress($loopId, 1, 1);

    $iterationId = $loopStore->createIteration($loopId, 1);

    // Stage 0 — completed, with a session + turn + event.
    $stage0 = $loopStore->createStage($iterationId, 0, 'plan');
    $session0 = $storage->createSession(modelRole: 'plan', model: '', visibility: 'hidden');
    $task0 = $storage->createTask(sessionId: $session0, prompt: 'plan it', role: 'plan');
    $turn0 = $storage->createTurn($session0, 'plan it', 'claude-sonnet-5');
    $storage->completeTurn($turn0, 'planned', 100, 50, 150, 1, 1200, json_encode(['grep', 'read']), 0);
    $storage->appendTaskEvent($task0, 'tool_call', ['tool' => 'grep']);
    $loopStore->updateStage($stage0, 'completed', taskId: $task0, resultSummary: 'planned');

    // Stage 1 — running, task started, no turn yet.
    $stage1 = $loopStore->createStage($iterationId, 1, 'reviewer');
    $session1 = $storage->createSession(modelRole: 'reviewer', model: '', visibility: 'hidden');
    $task1 = $storage->createTask(sessionId: $session1, prompt: 'review it', role: 'reviewer');
    $storage->updateTaskHeartbeat($task1);
    $storage->appendTaskEvent($task1, 'tool_call', ['tool' => 'read']);
    $loopStore->updateStage($stage1, 'running', taskId: $task1);

    return [new LoopLiveViewBuilder($loopStore, $storage), $loopId, $storage, $loopStore];
}

test('returns null for an unknown loop', function (): void {
    [$builder] = seedLiveLoop();
    expect($builder->build('does-not-exist'))->toBeNull();
});

test('surfaces loop meta and position', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $snapshot = $builder->build($loopId)->toArray();

    expect($snapshot['loop']['id'])->toBe($loopId);
    expect($snapshot['loop']['definition_name'])->toBe('harness');
    expect($snapshot['loop']['goal'])->toBe('Ship the feature');
    expect($snapshot['position']['current_iteration'])->toBe(1);
    expect($snapshot['position']['max_iterations'])->toBe(4);
    expect($snapshot['position']['stages_per_iteration'])->toBe(2);
    expect($snapshot['position']['current_stage_role'])->toBe('reviewer');
});

test('rolls up per-stage model and tokens', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $snapshot = $builder->build($loopId)->toArray();

    expect($snapshot['stages'])->toHaveCount(2);
    $plan = $snapshot['stages'][0];
    expect($plan['role'])->toBe('plan');
    expect($plan['model'])->toBe('claude-sonnet-5');
    expect($plan['tokens'])->toBe(['prompt' => 100, 'completion' => 50, 'total' => 150]);
    expect($plan['tools_used'])->toBe(['grep', 'read']);
    expect($plan['status'])->toBe('completed');
});

test('sums the loop token budget across stages', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $budget = $builder->build($loopId)->toArray()['budget'];

    expect($budget['tokens'])->toBe(['prompt' => 100, 'completion' => 50, 'total' => 150]);
    expect($budget['iterations'])->toBe(['used' => 1, 'max' => 4]);
});

test('computes elapsed and remaining time against an injected now', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loop-time-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = $loopStore->createLoop(
        definitionName: 'harness',
        goal: 'g',
        configuration: ['roles' => [['role' => 'plan']]],
        maxIterations: 2,
        deadline: '2026-07-11T10:10:00Z',
    );
    // started_at is set by createLoop to "now"; override for a deterministic test.
    $loopStore->updateLoop($loopId, ['started_at' => '2026-07-11T10:00:00Z']);

    $budget = (new LoopLiveViewBuilder($loopStore, $storage))
        ->build($loopId, '2026-07-11T10:03:00Z')
        ->toArray()['budget'];

    expect($budget['time']['elapsed_seconds'])->toBe(180);
    expect($budget['time']['remaining_seconds'])->toBe(420);
    expect($budget['time']['deadline'])->toBe('2026-07-11T10:10:00Z');
});

test('identifies the running stage with model, heartbeat and latest activity', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $current = $builder->build($loopId)->toArray()['current_stage'];

    expect($current)->not->toBeNull();
    expect($current['role'])->toBe('reviewer');
    expect($current['status'])->toBe('running');
    expect($current['last_heartbeat_at'])->not->toBeNull();
    expect($current['latest_activity']['type'])->toBe('tool_call');
    expect($current['latest_activity']['summary'])->toBe('Called read');
});

test('current stage is null when nothing is running', function (): void {
    [$builder, $loopId, $storage, $loopStore] = seedLiveLoop();
    // Complete the running stage.
    foreach ($loopStore->listIterations($loopId) as $iteration) {
        foreach ($loopStore->listStages((string) $iteration['id']) as $stage) {
            if (($stage['status'] ?? null) === 'running') {
                $loopStore->updateStage((string) $stage['id'], 'completed');
            }
        }
    }
    expect($builder->build($loopId)->toArray()['current_stage'])->toBeNull();
});

test('builds a newest-first recent-event feed', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $events = $builder->build($loopId)->toArray()['recent_events'];

    expect($events)->toHaveCount(2);
    // Stage 1's 'read' event was appended after stage 0's 'grep' event → newest first.
    expect($events[0]['summary'])->toBe('Called read');
    expect($events[0]['role'])->toBe('reviewer');
    expect($events[1]['summary'])->toBe('Called grep');
});

test('a running stage with no turn yet reports zero tokens and null model', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $reviewer = $builder->build($loopId)->toArray()['stages'][1];

    expect($reviewer['role'])->toBe('reviewer');
    expect($reviewer['model'])->toBeNull();
    expect($reviewer['tokens'])->toBe(['prompt' => 0, 'completion' => 0, 'total' => 0]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/LoopLiveViewBuilderTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Api\LoopLiveViewBuilder" not found`.

- [ ] **Step 3: Implement the builder**

`src/Api/LoopLiveViewBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\LoopLiveEvent;
use CoquiBot\Coqui\Contract\LoopLiveSnapshot;
use CoquiBot\Coqui\Contract\LoopLiveStage;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\Clock;

/**
 * Assembles a rich, poll-friendly snapshot of an in-flight loop from existing
 * loop / task / turn / event data. Pure read; no side effects.
 */
final readonly class LoopLiveViewBuilder
{
    private const int RECENT_EVENTS_LIMIT = 50;

    public function __construct(
        private LoopStore $loopStore,
        private SessionStorage $storage,
    ) {}

    public function build(string $loopId, ?string $now = null): ?LoopLiveSnapshot
    {
        $loop = $this->loopStore->getLoop($loopId);
        if ($loop === null) {
            return null;
        }
        $now ??= Clock::nowUtc();

        $stages = [];
        /** @var list<array{id: int, event: LoopLiveEvent}> $eventRows */
        $eventRows = [];
        $totalPrompt = 0;
        $totalCompletion = 0;
        $totalTokens = 0;
        $currentStage = null;

        foreach ($this->loopStore->listIterations($loopId) as $iteration) {
            $iterationNumber = (int) ($iteration['iteration_number'] ?? 0);

            foreach ($this->loopStore->listStages((string) $iteration['id']) as $stage) {
                $taskId = isset($stage['task_id']) && (string) $stage['task_id'] !== ''
                    ? (string) $stage['task_id']
                    : null;
                $task = $taskId !== null ? $this->storage->getTask($taskId) : null;
                $sessionId = is_array($task) && isset($task['session_id'])
                    ? (string) $task['session_id']
                    : null;

                [$prompt, $completion, $total, $durationMs, $model, $tools] = $this->summarizeSession($sessionId);
                $totalPrompt += $prompt;
                $totalCompletion += $completion;
                $totalTokens += $total;

                $status = (string) ($stage['status'] ?? 'unknown');

                $stages[] = new LoopLiveStage(
                    iterationNumber: $iterationNumber,
                    stageIndex: (int) ($stage['stage_index'] ?? 0),
                    role: (string) ($stage['role'] ?? ''),
                    model: $model,
                    status: $status,
                    promptTokens: $prompt,
                    completionTokens: $completion,
                    totalTokens: $total,
                    durationMs: $durationMs,
                    toolsUsed: $tools,
                    resultSummary: isset($stage['result_summary']) ? (string) $stage['result_summary'] : null,
                    startedAt: isset($stage['started_at']) ? (string) $stage['started_at'] : null,
                    completedAt: isset($stage['completed_at']) ? (string) $stage['completed_at'] : null,
                    taskId: $taskId,
                );

                if ($taskId !== null) {
                    foreach ($this->storage->getTaskEvents($taskId, null, self::RECENT_EVENTS_LIMIT) as $event) {
                        $eventRows[] = [
                            'id' => (int) ($event['id'] ?? 0),
                            'event' => new LoopLiveEvent(
                                timestamp: (string) ($event['created_at'] ?? ''),
                                stageId: (string) ($stage['id'] ?? ''),
                                role: (string) ($stage['role'] ?? ''),
                                type: (string) ($event['event_type'] ?? 'event'),
                                summary: $this->summarizeEvent((string) ($event['event_type'] ?? ''), $event['data'] ?? null),
                            ),
                        ];
                    }
                }

                if ($status === 'running') {
                    $currentStage = [
                        'stage_id' => (string) ($stage['id'] ?? ''),
                        'iteration_number' => $iterationNumber,
                        'stage_index' => (int) ($stage['stage_index'] ?? 0),
                        'role' => (string) ($stage['role'] ?? ''),
                        'model' => $model,
                        'status' => $status,
                        'task_id' => $taskId,
                        'session_id' => $sessionId,
                        'started_at' => isset($stage['started_at']) ? (string) $stage['started_at'] : null,
                        'last_heartbeat_at' => is_array($task) && isset($task['last_heartbeat_at'])
                            ? (string) $task['last_heartbeat_at']
                            : null,
                        'latest_activity' => $this->latestActivity($taskId),
                    ];
                }
            }
        }

        usort($eventRows, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
        $recentEvents = array_map(
            static fn(array $row): LoopLiveEvent => $row['event'],
            array_slice($eventRows, 0, self::RECENT_EVENTS_LIMIT),
        );

        return new LoopLiveSnapshot(
            loop: $this->loopMeta($loop),
            position: $this->position($loop, $currentStage),
            currentStage: $currentStage,
            budget: $this->budget($loop, $now, $totalPrompt, $totalCompletion, $totalTokens),
            stages: $stages,
            recentEvents: $recentEvents,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: ?string, 5: list<string>}
     */
    private function summarizeSession(?string $sessionId): array
    {
        if ($sessionId === null) {
            return [0, 0, 0, 0, null, []];
        }

        $prompt = 0;
        $completion = 0;
        $total = 0;
        $durationMs = 0;
        $model = null;
        $tools = [];

        foreach ($this->storage->getTurns($sessionId) as $turn) {
            $prompt += (int) ($turn['prompt_tokens'] ?? 0);
            $completion += (int) ($turn['completion_tokens'] ?? 0);
            $total += (int) ($turn['total_tokens'] ?? 0);
            $durationMs += (int) ($turn['duration_ms'] ?? 0);
            if (isset($turn['model']) && (string) $turn['model'] !== '') {
                $model = (string) $turn['model'];
            }
            foreach ((array) ($turn['tools_used'] ?? []) as $tool) {
                $name = is_string($tool) ? $tool : (string) (is_array($tool) ? ($tool['name'] ?? '') : '');
                if ($name !== '' && !in_array($name, $tools, true)) {
                    $tools[] = $name;
                }
            }
        }

        return [$prompt, $completion, $total, $durationMs, $model, $tools];
    }

    /**
     * @return array{type: string, summary: string, timestamp: string}|null
     */
    private function latestActivity(?string $taskId): ?array
    {
        if ($taskId === null) {
            return null;
        }
        $events = $this->storage->getTaskEvents($taskId, null, self::RECENT_EVENTS_LIMIT);
        if ($events === []) {
            return null;
        }
        $last = $events[array_key_last($events)];

        return [
            'type' => (string) ($last['event_type'] ?? ''),
            'summary' => $this->summarizeEvent((string) ($last['event_type'] ?? ''), $last['data'] ?? null),
            'timestamp' => (string) ($last['created_at'] ?? ''),
        ];
    }

    private function summarizeEvent(string $type, mixed $data): string
    {
        $decoded = is_string($data) ? json_decode($data, true) : $data;
        if (is_array($decoded)) {
            if (isset($decoded['tool']) && is_string($decoded['tool'])) {
                return $type === 'tool_call' ? 'Called ' . $decoded['tool'] : $decoded['tool'];
            }
            if (isset($decoded['message']) && is_string($decoded['message'])) {
                return $decoded['message'];
            }
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $loop
     * @return array<string, mixed>
     */
    private function loopMeta(array $loop): array
    {
        return [
            'id' => (string) ($loop['id'] ?? ''),
            'definition_name' => (string) ($loop['definition_name'] ?? ''),
            'goal' => (string) ($loop['goal'] ?? ''),
            'status' => (string) ($loop['status'] ?? ''),
            'project_id' => isset($loop['project_id']) ? (string) $loop['project_id'] : null,
            'work_scope_session_id' => isset($loop['session_id']) ? (string) $loop['session_id'] : null,
            'started_at' => isset($loop['started_at']) ? (string) $loop['started_at'] : null,
            'last_activity_at' => isset($loop['last_activity_at']) ? (string) $loop['last_activity_at'] : null,
            'completed_at' => isset($loop['completed_at']) ? (string) $loop['completed_at'] : null,
            'deadline' => isset($loop['deadline']) ? (string) $loop['deadline'] : null,
        ];
    }

    /**
     * @param array<string, mixed>      $loop
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function position(array $loop, ?array $current): array
    {
        $config = json_decode((string) ($loop['configuration'] ?? '{}'), true);
        $stagesPerIteration = is_array($config) && isset($config['roles']) && is_array($config['roles'])
            ? count($config['roles'])
            : null;

        return [
            'current_iteration' => (int) ($loop['current_iteration'] ?? 0),
            'max_iterations' => isset($loop['max_iterations']) ? (int) $loop['max_iterations'] : null,
            'current_stage_index' => (int) ($loop['current_stage'] ?? 0),
            'current_stage_role' => $current['role'] ?? null,
            'stages_per_iteration' => $stagesPerIteration,
        ];
    }

    /**
     * @param array<string, mixed> $loop
     * @return array<string, mixed>
     */
    private function budget(array $loop, string $now, int $prompt, int $completion, int $total): array
    {
        $startedAt = isset($loop['started_at']) ? (string) $loop['started_at'] : null;
        $completedAt = isset($loop['completed_at']) ? (string) $loop['completed_at'] : null;
        $deadline = isset($loop['deadline']) ? (string) $loop['deadline'] : null;

        $elapsed = null;
        if ($startedAt !== null) {
            $end = $completedAt ?? $now;
            $elapsed = max(0, strtotime($end) - strtotime($startedAt));
        }
        $remaining = $deadline !== null ? strtotime($deadline) - strtotime($now) : null;

        return [
            'tokens' => ['prompt' => $prompt, 'completion' => $completion, 'total' => $total],
            'iterations' => [
                'used' => (int) ($loop['current_iteration'] ?? 0),
                'max' => isset($loop['max_iterations']) ? (int) $loop['max_iterations'] : null,
            ],
            'time' => [
                'elapsed_seconds' => $elapsed,
                'deadline' => $deadline,
                'remaining_seconds' => $remaining,
            ],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Api/LoopLiveViewBuilderTest.php`
Expected: PASS (9 tests). If `updateLoop` rejects the `started_at` patch in the time test, confirm `LoopStore::updateLoop` whitelists `started_at`; if not, set the deadline test's expectation using the auto `started_at` via `$loopStore->getLoop($loopId)['started_at']` and a `now` offset instead.

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Api/LoopLiveViewBuilder.php src/Contract/LoopLive*.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Api/LoopLiveViewBuilder.php tests/Unit/Api/LoopLiveViewBuilderTest.php
git commit -m "$(cat <<'EOF'
feat(loops): add LoopLiveViewBuilder read-model

Aggregates loop -> stage -> task -> session -> turns into a live snapshot:
per-stage model/tokens, consumption budget, current running stage with latest
activity, and a newest-first recent-event feed. Composes existing store methods;
no schema or execution changes.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `GET /loops/{id}/live` endpoint

**Files:**
- Modify: `src/Api/Handler/LoopHandler.php` (add `use` import, `live()` method, docblock line, route registration)
- Test: `tests/Unit/Api/Handler/LoopHandlerTest.php` (add `/live` cases)

**Interfaces:**
- Consumes: `LoopLiveViewBuilder` (Task 2), the handler's existing `$store` (`LoopStore`) and `$storage` (`?SessionStorage`).
- Produces: `LoopHandler::live(ServerRequestInterface $request, string $id): Response`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Api/Handler/LoopHandlerTest.php`. The file already has `createLoopHandlerFixture()` (returns keys `dbPath`, `workspacePath`, `storage`, `projectStore`, `loopStore`, `handler`), `cleanupLoopHandlerFixture()`, and imports `React\Http\Message\ServerRequest` — mirror the existing try/finally pattern:

```php
test('GET /loops/{id}/live returns a snapshot for a known loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $loopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'do the thing',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 3,
        );

        $response = $fixture['handler']->live(
            new ServerRequest('GET', "/api/v1/loops/{$loopId}/live"),
            $loopId,
        );
        expect($response->getStatusCode())->toBe(200);

        $body = json_decode((string) $response->getBody(), true);
        expect($body['loop']['id'])->toBe($loopId);
        expect($body['loop']['goal'])->toBe('do the thing');
        expect($body['budget']['iterations'])->toBe(['used' => 0, 'max' => 3]);
        expect($body)->toHaveKeys(['loop', 'position', 'current_stage', 'budget', 'stages', 'recent_events']);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops/{id}/live 404s for an unknown loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $response = $fixture['handler']->live(
            new ServerRequest('GET', '/api/v1/loops/nope/live'),
            'nope',
        );
        expect($response->getStatusCode())->toBe(404);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php`
Expected: FAIL — `Call to undefined method ...::live()`.

- [ ] **Step 3: Implement the handler method + route**

In `src/Api/Handler/LoopHandler.php`:

Add the import near the other `use` statements:

```php
use CoquiBot\Coqui\Api\LoopLiveViewBuilder;
```

Add the method (place it next to `metrics()`):

```php
/**
 * GET /api/v1/loops/{id}/live
 */
public function live(ServerRequestInterface $request, string $id): Response
{
    if ($this->storage === null) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Loop live view is not available');
    }

    $snapshot = (new LoopLiveViewBuilder($this->store, $this->storage))->build($id);
    if ($snapshot === null) {
        return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
    }

    return Router::jsonResponse($snapshot->toArray());
}
```

Register the route in `register()`, right after the `metrics` line:

```php
$router->get($v1 . '/loops/{id}/metrics', [$this, 'metrics']);
$router->get($v1 . '/loops/{id}/live', [$this, 'live']);
```

Add to the class docblock route list (after the `/metrics` line):

```php
 * GET    /api/v1/loops/{id}/live         — get rich live snapshot (current stage, model, budget, events)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php`
Expected: PASS (existing tests + the 2 new ones).

- [ ] **Step 5: Commit**

```bash
git add src/Api/Handler/LoopHandler.php tests/Unit/Api/Handler/LoopHandlerTest.php
git commit -m "$(cat <<'EOF'
feat(loops): expose GET /loops/{id}/live endpoint

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Docs + source map

**Files:**
- Modify: `docs/API.md` (document the endpoint + response shape)
- Modify: `docs/LOOPS.md` (mention the live view as the way to observe a running loop)
- Modify: `config/source.json` (add the builder + 3 contracts)

- [ ] **Step 1: Document the endpoint in `docs/API.md`**

Add a `GET /api/v1/loops/{id}/live` entry near the other loop endpoints, with the response shape from the spec (loop / position / current_stage / budget / stages / recent_events) and a note that it is poll-based (streaming is a future addition).

- [ ] **Step 2: Note the live view in `docs/LOOPS.md`**

Add a short "Observing a running loop" note pointing at `GET /loops/{id}/live` for current stage, model, and consumption budget.

- [ ] **Step 3: Add entries to `config/source.json`**

Add records for `src/Api/LoopLiveViewBuilder.php`, `src/Contract/LoopLiveSnapshot.php`, `src/Contract/LoopLiveStage.php`, `src/Contract/LoopLiveEvent.php`, matching the surrounding entry format (path, fqcn, description).

- [ ] **Step 4: Verify the whole change is green**

```bash
composer test && composer analyse
```
Expected: full suite green; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add docs/API.md docs/LOOPS.md config/source.json
git commit -m "$(cat <<'EOF'
docs(loops): document the loop live-view endpoint

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

- **Spec coverage:** `LoopLiveViewBuilder` ✓ (Task 2), `LoopLiveSnapshot`/`Stage`/`Event` ✓ (Task 1), `LoopHandler::live` + route ✓ (Task 3), snapshot fields (loop/position/current_stage/budget/stages/recent_events) ✓ (Tasks 1–2), consumption budget tokens+iterations+time ✓ (Task 2 `budget()`), graceful nulls ✓ (Task 2 tests), injected clock via `now` param ✓, docs + source.json ✓ (Task 4). Deviation from spec (improvement): **no new `LoopStore`/`SessionStorage` queries** — existing `getTask`/`getTurns`/`getTaskEvents` suffice; noted in Architecture.
- **Placeholder scan:** none — Task 3's test now uses the real fixture keys (`handler`/`loopStore`), `ServerRequest`, and the `cleanupLoopHandlerFixture` try/finally pattern verified from the file. Task 2 Step 4 carries one conditional fallback for the `started_at` patch, which is a genuine runtime branch, not a TBD. All code steps show complete code.
- **Type consistency:** contract signatures in Task 1 match their construction in Task 2 (`LoopLiveStage(iterationNumber, stageIndex, role, model, status, promptTokens, completionTokens, totalTokens, durationMs, toolsUsed, resultSummary, startedAt, completedAt, taskId)`; `LoopLiveEvent(timestamp, stageId, role, type, summary)`); `build(string, ?string): ?LoopLiveSnapshot` used consistently in Tasks 2–3.

## Execution Handoff

This plan becomes a `/prompt-agent-task` hand-off. Two execution options:
1. **Subagent-Driven (recommended)** — fresh subagent per task, review between.
2. **Inline** — execute here with `superpowers:executing-plans`.
