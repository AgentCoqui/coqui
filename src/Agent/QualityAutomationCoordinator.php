<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CoquiBot\Coqui\Contract\LearnerFollowUpMetadata;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Coordinates the autonomous quality loop: evaluation schedules,
 * learning schedules, and learner follow-up tasks for poor evaluations.
 */
final readonly class QualityAutomationCoordinator
{
    public const string EVALUATION_SCHEDULE_NAME = 'system:quality:evaluator';
    public const string LEARNING_SCHEDULE_NAME = 'system:quality:learner';
    public const string QUALITY_CREATED_BY = 'quality-automation';
    public const string DEFAULT_TIMEZONE = 'UTC';
    public const string DEFAULT_EVALUATION_SCHEDULE = '0 2 * * *';
    public const string DEFAULT_LEARNING_SCHEDULE = '30 2 * * *';
    public const float DEFAULT_POOR_EVALUATION_THRESHOLD = 0.7;
    public const int DEFAULT_LOOKBACK_HOURS = 168;
    public const int DEFAULT_MAX_ITERATIONS = 48;

    public function __construct(
        private ConfigInterface $config,
        private SessionStorage $storage,
        private ?ScheduleStore $scheduleStore = null,
        private ?EvaluationStore $evaluationStore = null,
    ) {}

    /**
     * @return list<string>
     */
    public function ensureDefaultSchedules(): array
    {
        if (!$this->isQualityEnabled() || !$this->shouldBootstrapSchedules() || $this->scheduleStore === null) {
            return [];
        }

        $created = [];
        $timezone = $this->qualityTimezone();

        $definitions = [
            [
                'name' => self::EVALUATION_SCHEDULE_NAME,
                'expression' => $this->evaluationScheduleExpression(),
                'prompt' => $this->buildEvaluatorSchedulePrompt(),
                'role' => SystemRole::Evaluator->value,
                'description' => 'Automatically evaluates completed sessions and records structured reports.',
                'kind' => 'evaluation',
            ],
            [
                'name' => self::LEARNING_SCHEDULE_NAME,
                'expression' => $this->learningScheduleExpression(),
                'prompt' => $this->buildLearnerSchedulePrompt(),
                'role' => SystemRole::Learner->value,
                'description' => 'Automatically analyzes poor evaluations and synthesizes corrective skills.',
                'kind' => 'learning',
            ],
        ];

        foreach ($definitions as $definition) {
            if ($this->scheduleStore->getByName($definition['name']) !== null) {
                continue;
            }

            $this->scheduleStore->create(
                name: $definition['name'],
                scheduleExpression: $definition['expression'],
                prompt: $definition['prompt'],
                role: $definition['role'],
                maxIterations: self::DEFAULT_MAX_ITERATIONS,
                description: $definition['description'],
                createdBy: self::QUALITY_CREATED_BY,
                timezone: $timezone,
                metadata: json_encode([
                    'managed_by' => self::QUALITY_CREATED_BY,
                    'kind' => $definition['kind'],
                    'version' => 1,
                ], JSON_UNESCAPED_SLASHES) ?: null,
            );

            $created[] = $definition['name'];
        }

        return $created;
    }

    /**
     * @return array{taskId: string, status: 'created'|'existing'}|null
     */
    public function queueLearnerFollowUp(
        string $evaluationId,
        string $evaluatedSessionId,
        string $overallGrade,
        float $overallScore,
    ): ?array {
        if (!$this->isQualityEnabled() || !$this->shouldAutoTriggerLearner()) {
            return null;
        }

        if (!$this->isPoorEvaluation($overallGrade, $overallScore)) {
            return null;
        }

        $existingTaskId = $this->linkedLearnerFollowUpTaskId($evaluationId);
        if ($existingTaskId !== null) {
            return [
                'taskId' => $existingTaskId,
                'status' => 'existing',
            ];
        }

        $title = sprintf('Quality Learning Follow-up: %s', $evaluationId);
        $triggerContext = $this->resolveLearnerTriggerContext($evaluationId);

        $learnerSessionId = $this->storage->createSession(SystemRole::Learner->value, self::QUALITY_CREATED_BY, visibility: 'hidden');
        $taskId = $this->storage->createTask(
            sessionId: $learnerSessionId,
            prompt: $this->buildLearnerFollowUpPrompt(
                evaluationId: $evaluationId,
                evaluatedSessionId: $evaluatedSessionId,
                overallGrade: $overallGrade,
                overallScore: $overallScore,
                sessionTitle: $triggerContext['session_title'],
                childRunCount: $triggerContext['child_run_count'],
                evidenceSources: $triggerContext['evidence_sources'],
            ),
            role: SystemRole::Learner->value,
            title: $title,
            maxIterations: self::DEFAULT_MAX_ITERATIONS,
            metadata: (new LearnerFollowUpMetadata(
                evaluationId: $evaluationId,
                evaluatedSessionId: $evaluatedSessionId,
                overallGrade: $overallGrade,
                overallScore: $overallScore,
                sessionTitle: $triggerContext['session_title'],
                evidenceSources: $triggerContext['evidence_sources'],
                childRunIds: $triggerContext['child_run_ids'],
                childRunCount: $triggerContext['child_run_count'],
            ))->toArray(),
        );

        $this->evaluationStore?->linkLearnerFollowUpTask($evaluationId, $taskId);

        return [
            'taskId' => $taskId,
            'status' => 'created',
        ];
    }

    private function linkedLearnerFollowUpTaskId(string $evaluationId): ?string
    {
        $evaluation = $this->evaluationStore?->get($evaluationId);
        $taskId = is_array($evaluation) ? ($evaluation['learner_follow_up_task_id'] ?? null) : null;

        if (!is_string($taskId) || $taskId === '') {
            return null;
        }

        return $this->storage->getTask($taskId) !== null ? $taskId : null;
    }

    private function isQualityEnabled(): bool
    {
        $value = $this->config->get('agents.defaults.quality.enabled');

        return is_bool($value) ? $value : true;
    }

    private function shouldBootstrapSchedules(): bool
    {
        $value = $this->config->get('agents.defaults.quality.bootstrapSchedules');

        return is_bool($value) ? $value : true;
    }

    private function shouldAutoTriggerLearner(): bool
    {
        $value = $this->config->get('agents.defaults.quality.autoTriggerLearner');

        return is_bool($value) ? $value : true;
    }

    private function isPoorEvaluation(string $overallGrade, float $overallScore): bool
    {
        if (in_array($overallGrade, ['C', 'D', 'F'], true)) {
            return true;
        }

        return $overallScore < $this->poorEvaluationThreshold();
    }

    private function poorEvaluationThreshold(): float
    {
        $value = $this->config->get('agents.defaults.quality.poorEvaluationThreshold');

        if ((is_int($value) || is_float($value)) && $value >= 0 && $value <= 1) {
            return (float) $value;
        }

        return self::DEFAULT_POOR_EVALUATION_THRESHOLD;
    }

    private function evaluationScheduleExpression(): string
    {
        $value = $this->config->get('agents.defaults.quality.evaluationSchedule');

        return is_string($value) && $value !== ''
            ? $value
            : self::DEFAULT_EVALUATION_SCHEDULE;
    }

    private function learningScheduleExpression(): string
    {
        $value = $this->config->get('agents.defaults.quality.learningSchedule');

        return is_string($value) && $value !== ''
            ? $value
            : self::DEFAULT_LEARNING_SCHEDULE;
    }

    private function qualityTimezone(): string
    {
        $value = $this->config->get('agents.defaults.quality.timezone');

        if (is_string($value) && $value !== '') {
            try {
                new \DateTimeZone($value);

                return $value;
            } catch (\Throwable) {
                // Fall through to default timezone
            }
        }

        return self::DEFAULT_TIMEZONE;
    }

    private function buildEvaluatorSchedulePrompt(): string
    {
        return <<<'PROMPT'
Evaluate completed sessions that do not yet have evaluation reports. Use the evaluator workflow to inspect transcripts and child runs, save structured reports, and stop immediately when there is nothing left to grade.
PROMPT;
    }

    private function buildLearnerSchedulePrompt(): string
    {
        return <<<'PROMPT'
Analyze recent poor evaluations, cluster recurring failure patterns, and create or update skills with corrective SOPs. Prefer updating existing skills over creating duplicates, and stop immediately when there is nothing new to learn.
PROMPT;
    }

    /**
     * @param list<string> $evidenceSources
     */
    private function buildLearnerFollowUpPrompt(
        string $evaluationId,
        string $evaluatedSessionId,
        string $overallGrade,
        float $overallScore,
        ?string $sessionTitle,
        int $childRunCount,
        array $evidenceSources,
    ): string {
        $sessionTitleLine = $sessionTitle !== null && $sessionTitle !== ''
            ? sprintf("- Session title: %s\n", $sessionTitle)
            : '';
        $childRunLine = $childRunCount > 0
            ? sprintf("- Child runs captured: %d\n", $childRunCount)
            : '';
        $evidenceLine = $evidenceSources !== []
            ? sprintf("- Evidence sources: %s\n", implode(', ', $evidenceSources))
            : '';

        return sprintf(
            <<<'PROMPT'
Analyze evaluation %s for session %s.

Start by reading that specific evaluation with learning_read_evaluation. Then determine whether the failure pattern is already covered by an existing skill. Update an existing skill when possible; create a new skill only when the failure pattern is genuinely new.

Context:
- Grade: %s
- Overall score: %.3f
%s%s%s

You may consult other recent poor evaluations only when they clearly represent the same root cause and would help consolidate a single corrective SOP.
PROMPT,
            $evaluationId,
            $evaluatedSessionId,
            $overallGrade,
            $overallScore,
            $sessionTitleLine,
            $childRunLine,
            $evidenceLine,
        );
    }

    /**
     * @return array{session_title: ?string, evidence_sources: list<string>, child_run_ids: list<string>, child_run_count: int}
     */
    private function resolveLearnerTriggerContext(string $evaluationId): array
    {
        $evaluation = $this->evaluationStore?->getReadModel($evaluationId);
        $metadata = $evaluation?->metadata;

        $evidenceSources = [];
        $childRunIds = [];
        $childRunCount = 0;

        if (is_array($metadata)) {
            $rawEvidenceSources = $metadata['evidence_sources'] ?? [];
            if (is_array($rawEvidenceSources)) {
                $evidenceSources = array_values(array_filter($rawEvidenceSources, static fn(mixed $value): bool => is_string($value) && $value !== ''));
            }

            $rawChildRunIds = $metadata['child_run_ids'] ?? [];
            if (is_array($rawChildRunIds)) {
                $childRunIds = array_values(array_filter($rawChildRunIds, static fn(mixed $value): bool => is_string($value) && $value !== ''));
            }

            $childRunCount = isset($metadata['child_run_count']) ? (int) $metadata['child_run_count'] : count($childRunIds);
        }

        return [
            'session_title' => is_array($metadata) && isset($metadata['session_title']) && is_string($metadata['session_title']) && $metadata['session_title'] !== ''
                ? $metadata['session_title']
                : $evaluation?->sessionTitle,
            'evidence_sources' => $evidenceSources,
            'child_run_ids' => $childRunIds,
            'child_run_count' => $childRunCount,
        ];
    }
}