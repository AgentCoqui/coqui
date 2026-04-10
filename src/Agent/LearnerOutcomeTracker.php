<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Contract\LearnerOutcomeMetadata;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Support\JsonHelper;
use CoquiBot\Coqui\Support\StringHelper;

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
        if (($task['role'] ?? null) !== SystemRole::Learner->value) {
            return false;
        }

        $metadata = JsonHelper::decodeJsonObject($task['metadata'] ?? null);
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
            resultExcerpt: StringHelper::truncateNullable($resultText, 1000, '...'),
            error: StringHelper::truncateNullable($errorText, 1000, '...'),
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
        $names = array_values(array_unique(array_filter(
            $matches[1] ?? [],
            static fn(string $value): bool => $value !== '',
        )));

        return $names;
    }

}