<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LearnerOutcomeTracker;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;

test('learner outcome tracker records created and updated skills from task results', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-learner-outcome-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new EvaluationStore($storage->getPdo());
    $lifecycleStore = new SkillLifecycleStore($storage->getPdo());
    $sessionId = $storage->createSession('orchestrator', 'test-model');

    try {
        $evaluationId = $store->create(
            sessionId: $sessionId,
            overallGrade: 'D',
            scoreCompletion: 0.3,
            scoreHallucination: 0.4,
            scoreEfficiency: 0.2,
            overallScore: 0.32,
            report: 'Poor performance',
        );

        $taskSessionId = $storage->createSession('learner', 'quality-automation');
        $taskId = $storage->createTask(
            sessionId: $taskSessionId,
            prompt: 'Analyze evaluation',
            role: 'learner',
            title: 'Quality Learning Follow-up: ' . $evaluationId,
            metadata: [
                'evaluation_id' => $evaluationId,
            ],
        );

        $task = $storage->getTask($taskId);
        expect($task)->not->toBeNull();

        $tracker = new LearnerOutcomeTracker($store, $lifecycleStore);

        expect($tracker->recordFromTask(
            $task,
            'completed',
            "Skill \"quality-loop-review\" created successfully.\nSkill \"tool-batching\" updated successfully.",
        ))->toBeTrue();

        $evaluation = $store->getReadModel($evaluationId);

        expect($evaluation?->learnerOutcomeMetadata['task_status'])->toBe('completed');
        expect($evaluation?->learnerOutcomeMetadata['skills_created'])->toBe(['quality-loop-review']);
        expect($evaluation?->learnerOutcomeMetadata['skills_updated'])->toBe(['tool-batching']);

        $provenance = $lifecycleStore->listSkillProvenance(evaluationId: $evaluationId);
        expect($provenance)->toHaveCount(2);
        expect(array_column($provenance, 'action'))->toContain('created');
        expect(array_column($provenance, 'action'))->toContain('updated');
    } finally {
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }
});