<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Contract\LearnerOutcomeMetadata;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;

/**
 * Persists learner follow-up outcomes back onto their source evaluations.
 */
final readonly class LearnerOutcomeTracker
{
    public function __construct(
        private EvaluationStore $evaluationStore,
        private ?SkillLifecycleStore $skillLifecycleStore = null,
    ) {}

    /**
     * @param array<string, mixed> $task
     */
    public function recordFromTask(
        array $task,
        string $status,
        ?string $result = null,
        ?string $error = null,
    ): bool {
        if (($task['role'] ?? null) !== 'learner') {
            return false;
        }

        $metadata = $this->decodeJsonObject($task['metadata'] ?? null);
        $evaluationId = is_array($metadata) && isset($metadata['evaluation_id']) && is_string($metadata['evaluation_id'])
            ? $metadata['evaluation_id']
            : null;

        if ($evaluationId === null || $evaluationId === '') {
            return false;
        }

        $resultText = $result;
        if (($resultText === null || $resultText === '') && isset($task['result']) && is_string($task['result'])) {
            $resultText = $task['result'];
        }

        $errorText = $error;
        if (($errorText === null || $errorText === '') && isset($task['error']) && is_string($task['error'])) {
            $errorText = $task['error'];
        }

        $outcome = new LearnerOutcomeMetadata(
            evaluationId: $evaluationId,
            taskId: (string) ($task['id'] ?? ''),
            taskStatus: $status,
            skillsCreated: $status === 'completed' ? $this->extractSkillNames((string) ($resultText ?? ''), 'created') : [],
            skillsUpdated: $status === 'completed' ? $this->extractSkillNames((string) ($resultText ?? ''), 'updated') : [],
            resultExcerpt: $this->truncate($resultText),
            error: $this->truncate($errorText),
            capturedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );

        $updated = $this->evaluationStore->updateLearnerOutcomeMetadata($evaluationId, $outcome->toArray());

        if ($updated && $this->skillLifecycleStore !== null && $status === 'completed') {
            foreach ($outcome->skillsCreated as $skillName) {
                $this->skillLifecycleStore->recordSkillProvenance(
                    skillName: $skillName,
                    action: 'created',
                    evaluationId: $evaluationId,
                    learnerTaskId: $outcome->taskId,
                    metadata: $outcome->toArray(),
                );
            }

            foreach ($outcome->skillsUpdated as $skillName) {
                $this->skillLifecycleStore->recordSkillProvenance(
                    skillName: $skillName,
                    action: 'updated',
                    evaluationId: $evaluationId,
                    learnerTaskId: $outcome->taskId,
                    metadata: $outcome->toArray(),
                );
            }
        }

        return $updated;
    }

    /**
     * @return list<string>
     */
    private function extractSkillNames(string $result, string $verb): array
    {
        $pattern = sprintf('/Skill ["\']([^"\']+)["\'] %s successfully\./i', preg_quote($verb, '/'));
        preg_match_all($pattern, $result, $matches);

        /** @var list<string> $names */
        $names = array_values(array_unique(array_filter($matches[1] ?? [], static fn(mixed $value): bool => is_string($value) && $value !== '')));

        return $names;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function truncate(?string $value, int $limit = 1000): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit) . '...'
            : $value;
    }
}