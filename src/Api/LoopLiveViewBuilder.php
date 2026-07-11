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
                    foreach ($this->storage->getRecentTaskEvents($taskId, self::RECENT_EVENTS_LIMIT) as $event) {
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
        $events = $this->storage->getRecentTaskEvents($taskId, self::RECENT_EVENTS_LIMIT);
        if ($events === []) {
            return null;
        }
        $last = $events[0];

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
