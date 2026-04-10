<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\JsonHelper;

/**
 * Read-only visibility surface for the autonomous quality loop.
 */
final readonly class QualityAutomationStatusService
{
    public function __construct(
        private ConfigInterface $config,
        private SessionStorage $storage,
        private EvaluationStore $evaluationStore,
        private ?ScheduleStore $scheduleStore = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(int $followUpLimit = 10): array
    {
        $stats = $this->evaluationStore->getLearnerFollowUpStats();

        return [
            'enabled' => $this->boolConfig('agents.defaults.quality.enabled', true),
            'bootstrap_schedules' => $this->boolConfig('agents.defaults.quality.bootstrapSchedules', true),
            'auto_trigger_learner' => $this->boolConfig('agents.defaults.quality.autoTriggerLearner', true),
            'poor_evaluation_threshold' => $this->floatConfig(
                'agents.defaults.quality.poorEvaluationThreshold',
                QualityAutomationCoordinator::DEFAULT_POOR_EVALUATION_THRESHOLD,
            ),
            'learner_dedup_lookback_hours' => $this->intConfig(
                'agents.defaults.quality.learnerDedupLookbackHours',
                QualityAutomationCoordinator::DEFAULT_LOOKBACK_HOURS,
            ),
            'timezone' => $this->stringConfig(
                'agents.defaults.quality.timezone',
                QualityAutomationCoordinator::DEFAULT_TIMEZONE,
            ),
            'schedules' => $this->scheduleSummary(),
            'follow_ups' => [
                'counts' => [
                    'linked' => $stats['linked'],
                    ...$stats['by_status'],
                ],
                'active' => $this->formatFollowUps(
                    $this->evaluationStore->listLearnerFollowUps(['pending', 'running', 'cancelling'], $followUpLimit),
                ),
                'recent' => $this->formatFollowUps(
                    $this->evaluationStore->listLearnerFollowUps(limit: $followUpLimit),
                ),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduleSummary(): array
    {
        if ($this->scheduleStore === null) {
            return [];
        }

        $definitions = [
            'evaluation' => QualityAutomationCoordinator::EVALUATION_SCHEDULE_NAME,
            'learning' => QualityAutomationCoordinator::LEARNING_SCHEDULE_NAME,
        ];

        $result = [];

        foreach ($definitions as $kind => $name) {
            $schedule = $this->scheduleStore->getByName($name);
            $lastTask = null;

            if ($schedule !== null) {
                $task = $this->storage->getTaskByScheduleId((string) $schedule['id']);
                if ($task !== null) {
                    $lastTask = [
                        'id' => (string) $task['id'],
                        'status' => (string) $task['status'],
                        'created_at' => $task['created_at'],
                        'completed_at' => $task['completed_at'],
                    ];
                }
            }

            $result[] = [
                'kind' => $kind,
                'name' => $name,
                'exists' => $schedule !== null,
                'id' => $schedule['id'] ?? null,
                'enabled' => $schedule !== null ? (bool) ((int) ($schedule['enabled'] ?? 0)) : false,
                'source' => $schedule['source'] ?? null,
                'next_run_at' => $schedule['next_run_at'] ?? null,
                'last_run_at' => $schedule['last_run_at'] ?? null,
                'last_status' => $schedule['last_status'] ?? null,
                'run_count' => $schedule !== null ? (int) ($schedule['run_count'] ?? 0) : 0,
                'failure_count' => $schedule !== null ? (int) ($schedule['failure_count'] ?? 0) : 0,
                'last_task' => $lastTask,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function formatFollowUps(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'evaluation_id' => (string) $row['evaluation_id'],
            'session_id' => (string) $row['session_id'],
            'session_title' => $row['session_title'] ?? '(untitled)',
            'overall_grade' => (string) $row['overall_grade'],
            'overall_score' => round((float) ($row['overall_score'] ?? 0.0), 3),
            'evaluated_at' => $row['evaluation_created_at'],
            'linked_at' => $row['learner_follow_up_linked_at'],
            'task_id' => $row['learner_follow_up_task_id'] ?? null,
            'task_status' => (string) ($row['learner_follow_up_status'] ?? 'missing'),
            'task_title' => $row['task_title'] ?? null,
            'trigger_metadata' => JsonHelper::decodeJsonObject($row['task_metadata'] ?? null),
            'learner_outcome' => JsonHelper::decodeJsonObject($row['learner_outcome_metadata'] ?? null),
            'task_created_at' => $row['task_created_at'] ?? null,
            'task_started_at' => $row['task_started_at'] ?? null,
            'task_completed_at' => $row['task_completed_at'] ?? null,
            'task_cancelled_at' => $row['task_cancelled_at'] ?? null,
            'task_result' => $row['task_result'] ?? null,
            'task_error' => $row['task_error'] ?? null,
        ], $rows);
    }

    private function boolConfig(string $key, bool $default): bool
    {
        $value = $this->config->get($key);

        return is_bool($value) ? $value : $default;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get($key);

        if ((is_int($value) || is_float($value)) && $value >= 0 && $value <= 1) {
            return (float) $value;
        }

        return $default;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get($key);

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        return $default;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
