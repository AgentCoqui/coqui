# Loop Events Stream Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (or superpowers:subagent-driven-development) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /api/v1/loops/{id}/events` — an SSE stream of thin, typed nudges (`connected`, `stage_changed`, `activity`, `done`) that lets a client watch a running loop and refetch `GET /loops/{id}/live` on each signal.

**Architecture:** Extend Coqui's existing ReactPHP SSE pattern (`TaskHandler::events`). Change-detection is a pure, fully-tested unit (`LoopStreamTracker::diff`); a small `SseStream` helper owns frame formatting + the `text/event-stream` response; `LoopStore::latestActivityId` supplies the cheap activity cursor; `LoopHandler::events` wires them to a 1s ReactPHP timer. Purely additive — no schema change, no new dependency, the existing `TaskHandler`/`MessageHandler` SSE endpoints are untouched.

**Tech Stack:** PHP 8.4, ReactPHP (`react/http`, `react/stream`), Pest (`composer test`), PHPStan level 8 (`composer analyse`).

**Spec:** `docs/superpowers/specs/2026-07-11-loop-events-stream-design.md`.

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, `final` by default, one class per file, 4-space indent.
- Branch off `origin/feat/loop-events-stream` (= `main` + this spec/plan): `git fetch origin && git checkout -b feat/loop-events-stream-impl origin/feat/loop-events-stream`. (Base carries no code changes over main — only the planning docs.)
- **Never `git add -A` or `git add .`** — stage only exact paths.
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Both `composer test` and `composer analyse` must be green before every commit.
- Namespaces: `CoquiBot\Coqui\Api` (src/Api/), `CoquiBot\Coqui\Contract` (src/Contract/), `CoquiBot\Coqui\Api\Handler` (src/Api/Handler/), `CoquiBot\Coqui\Storage` (src/Storage/).

---

### Task 1: `SseStream` helper

**Files:**
- Create: `src/Api/SseStream.php`
- Test: `tests/Unit/Api/SseStreamTest.php`

**Interfaces produced:**
- `SseStream::format(string $type, array $data, ?int $id = null): string` — pure SSE frame formatter.
- `new SseStream()`, `->connected(array)`, `->event(string, array, ?int)`, `->done(array)`, `->end()`, `->onClose(callable)`, `->response(): React\Http\Message\Response`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\SseStream;

test('formats an SSE frame without an id', function (): void {
    expect(SseStream::format('activity', ['cursor' => 42]))
        ->toBe("event: activity\ndata: {\"cursor\":42}\n\n");
});

test('formats an SSE frame with an id line', function (): void {
    expect(SseStream::format('activity', ['cursor' => 42], 42))
        ->toBe("id: 42\nevent: activity\ndata: {\"cursor\":42}\n\n");
});

test('formats a connected frame with a string payload', function (): void {
    expect(SseStream::format('connected', ['loop_id' => 'lp_1']))
        ->toBe("event: connected\ndata: {\"loop_id\":\"lp_1\"}\n\n");
});

test('response is a 200 text/event-stream', function (): void {
    $response = (new SseStream())->response();
    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('Content-Type'))->toBe('text/event-stream');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/SseStreamTest.php`
Expected: FAIL — class `SseStream` not found.

- [ ] **Step 3: Implement `SseStream`**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * Small helper for Server-Sent Events over a ReactPHP ThroughStream.
 *
 * Encapsulates SSE frame formatting, the text/event-stream response headers,
 * and client-disconnect wiring so streaming endpoints share one implementation.
 */
final class SseStream
{
    private ThroughStream $stream;

    public function __construct()
    {
        $this->stream = new ThroughStream();
    }

    /**
     * Format a single SSE frame. Pure — no side effects.
     *
     * @param array<string, mixed> $data
     */
    public static function format(string $type, array $data, ?int $id = null): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $prefix = $id !== null ? "id: {$id}\n" : '';

        return "{$prefix}event: {$type}\ndata: {$json}\n\n";
    }

    /**
     * @param array<string, mixed> $data
     */
    public function connected(array $data): void
    {
        $this->stream->write(self::format('connected', $data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function event(string $type, array $data, ?int $id = null): void
    {
        $this->stream->write(self::format($type, $data, $id));
    }

    /**
     * Write a terminal `done` frame and close the stream.
     *
     * @param array<string, mixed> $data
     */
    public function done(array $data): void
    {
        $this->stream->write(self::format('done', $data));
        $this->stream->end();
    }

    public function end(): void
    {
        $this->stream->end();
    }

    public function onClose(callable $callback): void
    {
        $this->stream->on('close', $callback);
    }

    public function response(): Response
    {
        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
            $this->stream,
        );
    }
}
```

- [ ] **Step 4: Run tests + analyse**

Run: `./vendor/bin/pest tests/Unit/Api/SseStreamTest.php && composer analyse`
Expected: PASS; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add src/Api/SseStream.php tests/Unit/Api/SseStreamTest.php
git status --short   # verify no unexpected paths staged
git commit -m "$(cat <<'EOF'
feat(api): add SseStream helper for text/event-stream responses

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `LoopStreamState`, `LoopStreamEvent`, `LoopStreamTracker`

**Files:**
- Create: `src/Contract/LoopStreamState.php`
- Create: `src/Contract/LoopStreamEvent.php`
- Create: `src/Api/LoopStreamTracker.php`
- Test: `tests/Unit/Api/LoopStreamTrackerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `LoopStreamState(string $status, int $currentIteration, int $currentStage, ?int $latestActivityId)`; `LoopStreamEvent(string $type, array $data)`; `LoopStreamTracker::diff(?LoopStreamState $prev, LoopStreamState $now): ?LoopStreamEvent`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopStreamTracker;
use CoquiBot\Coqui\Contract\LoopStreamState;

function makeLoopStreamState(string $status = 'running', int $iter = 0, int $stage = 0, ?int $activity = null): LoopStreamState
{
    return new LoopStreamState($status, $iter, $stage, $activity);
}

test('initial running state emits stage_changed', function (): void {
    $event = LoopStreamTracker::diff(null, makeLoopStreamState('running', 0, 0, null));
    expect($event)->not->toBeNull();
    expect($event->type)->toBe('stage_changed');
    expect($event->data)->toBe(['iteration' => 0, 'stage_index' => 0, 'status' => 'running']);
});

test('initial terminal state emits done', function (): void {
    $event = LoopStreamTracker::diff(null, makeLoopStreamState('completed', 1, 2, 9));
    expect($event->type)->toBe('done');
    expect($event->data)->toBe(['status' => 'completed']);
});

test('stage advance emits stage_changed', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 0, 5), makeLoopStreamState('running', 0, 1, 5));
    expect($event->type)->toBe('stage_changed');
    expect($event->data['stage_index'])->toBe(1);
});

test('iteration advance emits stage_changed', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 1, 5), makeLoopStreamState('running', 1, 0, 5));
    expect($event->type)->toBe('stage_changed');
    expect($event->data['iteration'])->toBe(1);
});

test('pause emits stage_changed and keeps the stream open', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 0, 5), makeLoopStreamState('paused', 0, 0, 5));
    expect($event->type)->toBe('stage_changed');
    expect($event->data['status'])->toBe('paused');
});

test('new activity with unchanged position emits activity with cursor', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 0, 5), makeLoopStreamState('running', 0, 0, 12));
    expect($event->type)->toBe('activity');
    expect($event->data)->toBe(['cursor' => 12]);
});

test('no change emits null', function (): void {
    expect(LoopStreamTracker::diff(makeLoopStreamState('running', 1, 1, 7), makeLoopStreamState('running', 1, 1, 7)))->toBeNull();
});

test('running to terminal emits done', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 2, 1, 20), makeLoopStreamState('failed', 2, 1, 20));
    expect($event->type)->toBe('done');
    expect($event->data)->toBe(['status' => 'failed']);
});

test('already-terminal previous emits null (done not repeated)', function (): void {
    expect(LoopStreamTracker::diff(makeLoopStreamState('completed', 2, 1, 20), makeLoopStreamState('completed', 2, 1, 20)))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/LoopStreamTrackerTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the value objects**

`src/Contract/LoopStreamState.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * The minimal loop snapshot a single events-stream tick observes.
 */
final readonly class LoopStreamState
{
    public function __construct(
        public string $status,
        public int $currentIteration,
        public int $currentStage,
        public ?int $latestActivityId,
    ) {}
}
```

`src/Contract/LoopStreamEvent.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A single thin nudge emitted on the loop events stream.
 */
final readonly class LoopStreamEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $type,
        public array $data,
    ) {}
}
```

- [ ] **Step 4: Implement `LoopStreamTracker`**

`src/Api/LoopStreamTracker.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\LoopStreamEvent;
use CoquiBot\Coqui\Contract\LoopStreamState;

/**
 * Pure change-detection for the loop events stream. Given the previous and
 * current observed state, returns the single most-significant nudge to emit
 * (or null when nothing moved). No I/O, no ReactPHP — fully unit-testable.
 *
 * Precedence: done > stage_changed > activity. `diff(null, $now)` produces the
 * initial emit, unifying connect and per-tick logic.
 */
final class LoopStreamTracker
{
    /** @var list<string> */
    private const array TERMINAL = ['completed', 'failed', 'cancelled'];

    public static function diff(?LoopStreamState $prev, LoopStreamState $now): ?LoopStreamEvent
    {
        $nowTerminal = in_array($now->status, self::TERMINAL, true);
        $prevTerminal = $prev !== null && in_array($prev->status, self::TERMINAL, true);

        if ($nowTerminal) {
            return $prevTerminal ? null : new LoopStreamEvent('done', ['status' => $now->status]);
        }

        if ($prev === null
            || $now->status !== $prev->status
            || $now->currentIteration !== $prev->currentIteration
            || $now->currentStage !== $prev->currentStage
        ) {
            return new LoopStreamEvent('stage_changed', [
                'iteration' => $now->currentIteration,
                'stage_index' => $now->currentStage,
                'status' => $now->status,
            ]);
        }

        if ($now->latestActivityId !== $prev->latestActivityId) {
            return new LoopStreamEvent('activity', ['cursor' => $now->latestActivityId]);
        }

        return null;
    }
}
```

- [ ] **Step 5: Run tests + analyse**

Run: `./vendor/bin/pest tests/Unit/Api/LoopStreamTrackerTest.php && composer analyse`
Expected: PASS; `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Contract/LoopStreamState.php src/Contract/LoopStreamEvent.php \
        src/Api/LoopStreamTracker.php tests/Unit/Api/LoopStreamTrackerTest.php
git status --short
git commit -m "$(cat <<'EOF'
feat(loops): add LoopStreamTracker change-detection for loop events

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `LoopStore::latestActivityId`

**Files:**
- Modify: `src/Storage/LoopStore.php` (add one method after `listStages()`, ~line 411)
- Test: `tests/Unit/Storage/LoopStoreLatestActivityTest.php`

**Interfaces:**
- Consumes: existing `LoopStore` schema (`loop_iterations`, `loop_stages`, `task_events`), all on the same SQLite PDO.
- Produces: `LoopStore::latestActivityId(string $loopId): ?int`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

test('latestActivityId returns the max task_events id across a loop\'s stages', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loopstore-activity-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());

    try {
        $loopId = $loopStore->createLoop(
            definitionName: 'harness',
            goal: 'g',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 2,
        );
        $iterationId = $loopStore->createIteration($loopId, 1);
        $stageId = $loopStore->createStage($iterationId, 0, 'plan');
        $taskId = 'task-activity-1';
        $loopStore->updateStage($stageId, 'running', $taskId);

        $storage->appendTaskEvent($taskId, 'tool_call', ['tool' => 'a']);
        $storage->appendTaskEvent($taskId, 'tool_call', ['tool' => 'b']);

        $events = $storage->getTaskEvents($taskId);
        $expected = (int) $events[array_key_last($events)]['id'];

        expect($loopStore->latestActivityId($loopId))->toBe($expected);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('latestActivityId returns null when the loop has no events', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loopstore-activity-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());

    try {
        $loopId = $loopStore->createLoop(
            definitionName: 'harness',
            goal: 'g',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 2,
        );
        expect($loopStore->latestActivityId($loopId))->toBeNull();
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/LoopStoreLatestActivityTest.php`
Expected: FAIL — `latestActivityId` not defined.

- [ ] **Step 3: Implement the method**

Insert into `src/Storage/LoopStore.php` immediately after the `listStages()` method (~line 411):

```php
    /**
     * The highest task_events.id produced across all of a loop's stage tasks,
     * or null when the loop has produced no events yet. Cheap activity cursor
     * for the loop events stream (single aggregate query, same PDO).
     */
    public function latestActivityId(string $loopId): ?int
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT MAX(te.id) AS max_id
            FROM loop_iterations li
            JOIN loop_stages ls ON ls.iteration_id = li.id
            JOIN task_events te ON te.task_id = ls.task_id
            WHERE li.loop_id = :loop_id
        SQL);
        $stmt->execute(['loop_id' => $loopId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (int) $value;
    }
```

- [ ] **Step 4: Run tests + analyse**

Run: `./vendor/bin/pest tests/Unit/Storage/LoopStoreLatestActivityTest.php && composer analyse`
Expected: PASS; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add src/Storage/LoopStore.php tests/Unit/Storage/LoopStoreLatestActivityTest.php
git status --short
git commit -m "$(cat <<'EOF'
feat(loops): add LoopStore::latestActivityId activity cursor

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `LoopHandler::events` endpoint + route

**Files:**
- Modify: `src/Api/Handler/LoopHandler.php` (add 3 imports, a `POLL_INTERVAL` const, `events()` + `readStreamState()` methods, one route, one docblock line)
- Test: `tests/Unit/Api/Handler/LoopHandlerTest.php` (append two tests)

**Interfaces:**
- Consumes: `SseStream` (Task 1), `LoopStreamTracker`/`LoopStreamState`/`LoopStreamEvent` (Task 2), `LoopStore::latestActivityId` (Task 3), existing `$this->store` (`LoopStore`), `Router`, `ApiErrorCode`.
- Produces: `LoopHandler::events(ServerRequestInterface, string $id): Response`; route `GET /api/v1/loops/{id}/events`.

- [ ] **Step 1: Write the failing tests** (append to `tests/Unit/Api/Handler/LoopHandlerTest.php`)

```php
test('GET /loops/{id}/events 404s for an unknown loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $response = $fixture['handler']->events(
            new ServerRequest('GET', '/api/v1/loops/nope/events'),
            'nope',
        );
        expect($response->getStatusCode())->toBe(404);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops/{id}/events returns an SSE stream for a terminal loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $loopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'done already',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 2,
        );
        // Terminal at open → connected + done, no timer registered.
        $fixture['loopStore']->updateLoopStatus($loopId, 'completed');

        $response = $fixture['handler']->events(
            new ServerRequest('GET', "/api/v1/loops/{$loopId}/events"),
            $loopId,
        );
        expect($response->getStatusCode())->toBe(200);
        expect($response->getHeaderLine('Content-Type'))->toBe('text/event-stream');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php --filter="events"`
Expected: FAIL — `events()` not defined.

- [ ] **Step 3: Add imports** to `src/Api/Handler/LoopHandler.php` (with the existing `use` block near the top):

```php
use CoquiBot\Coqui\Api\LoopStreamTracker;
use CoquiBot\Coqui\Api\SseStream;
use CoquiBot\Coqui\Contract\LoopStreamState;
```

- [ ] **Step 4: Add the endpoint methods** to the `LoopHandler` class body. Add the constant near the top of the class, and the two methods next to `live()`:

```php
    private const float POLL_INTERVAL = 1.0;
```

```php
    /**
     * GET /api/v1/loops/{id}/events
     *
     * SSE stream of thin nudges (connected, stage_changed, activity, done) for a
     * running loop. Clients refetch GET /loops/{id}/live on each nudge. Mirrors
     * the task-events long-poll: a ReactPHP timer diffs cheap loop state per tick.
     */
    public function events(ServerRequestInterface $request, string $id): Response
    {
        if ($this->store->getLoop($id) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $sse = new SseStream();
        $sse->connected(['loop_id' => $id]);

        $prev = null;
        $emit = function (LoopStreamState $now) use ($sse, &$prev): bool {
            $event = LoopStreamTracker::diff($prev, $now);
            $prev = $now;
            if ($event === null) {
                return false;
            }
            if ($event->type === 'done') {
                $sse->done($event->data);

                return true;
            }
            $sse->event($event->type, $event->data, $now->latestActivityId);

            return false;
        };

        // Initial emit: current position, or done if already terminal.
        $closed = $emit($this->readStreamState($id));

        if (!$closed) {
            $timer = \React\EventLoop\Loop::addPeriodicTimer(self::POLL_INTERVAL, function () use (&$timer, $sse, $id, $emit): void {
                try {
                    if ($emit($this->readStreamState($id))) {
                        if ($timer instanceof \React\EventLoop\TimerInterface) {
                            \React\EventLoop\Loop::cancelTimer($timer);
                        }
                    }
                } catch (\Throwable) {
                    try {
                        $sse->end();
                        if ($timer instanceof \React\EventLoop\TimerInterface) {
                            \React\EventLoop\Loop::cancelTimer($timer);
                        }
                    } catch (\Throwable) {
                        // Already closed.
                    }
                }
            });

            $sse->onClose(function () use (&$timer): void {
                /** @phpstan-ignore instanceof.alwaysTrue */
                if ($timer instanceof \React\EventLoop\TimerInterface) {
                    \React\EventLoop\Loop::cancelTimer($timer);
                }
            });
        }

        return $sse->response();
    }

    /**
     * Read the minimal loop state a stream tick observes. A vanished loop row
     * (deleted mid-stream) is treated as terminal 'cancelled'.
     */
    private function readStreamState(string $id): LoopStreamState
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return new LoopStreamState('cancelled', 0, 0, null);
        }

        return new LoopStreamState(
            status: (string) ($loop['status'] ?? 'cancelled'),
            currentIteration: (int) ($loop['current_iteration'] ?? 0),
            currentStage: (int) ($loop['current_stage'] ?? 0),
            latestActivityId: $this->store->latestActivityId($id),
        );
    }
```

- [ ] **Step 5: Register the route** in `register()`, immediately after the `/loops/{id}/live` line:

```php
        $router->get($v1 . '/loops/{id}/events', [$this, 'events']);
```

- [ ] **Step 6: Add the docblock route line** in the class-level route list, after the `/live` entry:

```php
 * GET    /api/v1/loops/{id}/events         — SSE live nudge stream
```

- [ ] **Step 7: Run the handler tests + analyse**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php && composer analyse`
Expected: PASS; `[OK] No errors`. If PHPStan flags the `&$timer` reference-before-assignment, confirm it mirrors the existing `TaskHandler::events` pattern (same `@phpstan-ignore` on the `onClose` check) and adjust only if a new error appears.

- [ ] **Step 8: Commit**

```bash
git add src/Api/Handler/LoopHandler.php tests/Unit/Api/Handler/LoopHandlerTest.php
git status --short
git commit -m "$(cat <<'EOF'
feat(loops): expose GET /loops/{id}/events SSE nudge stream

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Docs + source map

**Files:**
- Modify: `docs/API.md` (add the endpoint under Loops, near `GET /loops/{id}/live`)
- Modify: `docs/LOOPS.md` (note the live stream)
- Modify: `config/source.json` (new files + LoopHandler/LoopStore entries)

- [ ] **Step 1: Document the endpoint in `docs/API.md`**

Add a section next to `GET /api/v1/loops/{id}/live`:

```markdown
#### `GET /api/v1/loops/{id}/events`

Server-Sent Events stream of lightweight nudges for a running loop, so a client can watch progress without fixed-interval polling. The stream carries **signals only** — on each nudge the client re-fetches `GET /loops/{id}/live` for the full snapshot (that endpoint stays the single source of truth).

**Response `200`** — `Content-Type: text/event-stream`. Unknown loop → `404`.

| Event | Fires when | `data` |
|---|---|---|
| `connected` | on open | `{"loop_id": "<id>"}` |
| `stage_changed` | iteration/stage advanced, or lifecycle status changed (incl. pause/resume) | `{"iteration": N, "stage_index": N, "status": "<status>"}` |
| `activity` | new task events, position unchanged (coalesced to one per tick) | `{"cursor": <max_event_id>}` |
| `done` | loop reached `completed`/`failed`/`cancelled` — stream then closes | `{"status": "<status>"}` |

Each frame carries an SSE `id:` (the activity cursor) so reconnecting clients resume via `Last-Event-ID`; a reconnect re-syncs by refetching `/live`. `role`/`model`/budget are intentionally not in the payload — read them from `/live`.
```

- [ ] **Step 2: Note it in `docs/LOOPS.md`**

Add a short line where the loop observability/API surface is described:

```markdown
- `GET /api/v1/loops/{id}/events` streams SSE nudges (`connected`, `stage_changed`, `activity`, `done`) so clients can watch a running loop live and refetch `/loops/{id}/live` on each signal.
```

- [ ] **Step 3: Update `config/source.json`**

Add file entries (layer `agent` for src/Api, `config`/`contract` as appropriate — match the existing convention for `LoopLiveViewBuilder`/contracts):

- `src/Api/SseStream.php` — "Helper for Server-Sent Events over a ReactPHP ThroughStream: pure `format()` frame builder, `connected`/`event`/`done` writers, and the `text/event-stream` `Response`."
- `src/Api/LoopStreamTracker.php` — "Pure change-detection for the loop events stream: `diff(?prev, now)` returns the single most-significant nudge (done > stage_changed > activity) or null."
- `src/Contract/LoopStreamState.php` — "Value object: the minimal loop snapshot a stream tick observes (status, currentIteration, currentStage, latestActivityId)."
- `src/Contract/LoopStreamEvent.php` — "Value object: one thin loop-stream nudge (type, data)."

Update the `LoopHandler` entry: add `events(ServerRequestInterface, string): Response — GET /api/v1/loops/{id}/events (SSE nudge stream)` to its methods and mention the events endpoint in its description.

Update the `LoopStore` entry: add `latestActivityId(string): ?int — max task_events.id across a loop's stage tasks (activity cursor), null when none`.

- [ ] **Step 4: Verify + commit**

```bash
composer test && composer analyse
php -r "json_decode(file_get_contents('config/source.json')); echo json_last_error() === JSON_ERROR_NONE ? 'VALID JSON' : ('INVALID: '.json_last_error_msg());"
git add docs/API.md docs/LOOPS.md config/source.json
git status --short
git commit -m "$(cat <<'EOF'
docs(loops): document the loop events SSE stream

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

- **Spec coverage:** endpoint + typed vocabulary ✓ (Task 4 + Task 5 docs); `SseStream` helper ✓ (Task 1); pure tracker ✓ (Task 2); activity cursor query ✓ (Task 3); error handling (404, terminal-at-open, disconnect) ✓ (Task 4); testing strategy (pure units fully covered, timer glue thin) ✓ (Tasks 1–4). Out-of-scope items (WS, notifications, global `/events`, migrating existing SSE endpoints) are not built.
- **Placeholder scan:** none — every step has complete code/commands.
- **Type consistency:** `LoopStreamState`/`LoopStreamEvent`/`LoopStreamTracker::diff` signatures are identical across Tasks 2 and 4; `latestActivityId(string): ?int` matches its use in `readStreamState`; `$this->store` is the confirmed `LoopStore` property; `updateStage(id, status, taskId)` and `createIteration`/`createStage` signatures match `LoopStore`.
- **Additive-only:** no schema change, no dependency, existing `TaskHandler`/`MessageHandler` SSE untouched.

**Handoff:** developed on `feat/loop-events-stream-impl`; the reviewer verifies and opens the PR. Do not push or open a PR without confirmation.
