<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\QualityAutomationCoordinator;
use CoquiBot\Coqui\Agent\LearnerOutcomeTracker;
use CoquiBot\Coqui\Agent\QualityAutomationStatusService;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

function createQualityStatusFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-quality-status-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'evaluationStore' => new EvaluationStore($storage->getPdo()),
        'scheduleStore' => new ScheduleStore($storage->getPdo()),
    ];
}

function cleanupQualityStatusFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

test('summary reports quality schedules and linked follow-up tasks', function () {
    $fixture = createQualityStatusFixture();

    try {
        $config = OpenClawConfig::fromArray([]);
        $coordinator = new QualityAutomationCoordinator(
            config: $config,
            storage: $fixture['storage'],
            scheduleStore: $fixture['scheduleStore'],
            evaluationStore: $fixture['evaluationStore'],
        );
        $coordinator->ensureDefaultSchedules();

        $sessionId = $fixture['storage']->createSession('orchestrator', 'test-model');
        $evaluationId = $fixture['evaluationStore']->create(
            sessionId: $sessionId,
            overallGrade: 'D',
            scoreCompletion: 0.4,
            scoreHallucination: 0.4,
            scoreEfficiency: 0.4,
            overallScore: 0.4,
            report: 'Poor evaluation',
        );

        $followUp = $coordinator->queueLearnerFollowUp($evaluationId, $sessionId, 'D', 0.4);
        $fixture['storage']->updateTaskStatus($followUp['taskId'], 'completed', [
            'result' => "Skill \"quality-loop-review\" created successfully.\nSkill \"tool-batching\" updated successfully.",
        ]);
        $task = $fixture['storage']->getTask($followUp['taskId']);
        expect($task)->not->toBeNull();
        (new LearnerOutcomeTracker($fixture['evaluationStore']))->recordFromTask($task, 'completed');

        $status = new QualityAutomationStatusService(
            config: $config,
            storage: $fixture['storage'],
            evaluationStore: $fixture['evaluationStore'],
            scheduleStore: $fixture['scheduleStore'],
        );

        $summary = $status->summary();

        expect($summary['enabled'])->toBeTrue();
        expect($summary['schedules'])->toHaveCount(2);
        expect($summary['schedules'][0]['name'])->toBe(QualityAutomationCoordinator::EVALUATION_SCHEDULE_NAME);
        expect($summary['schedules'][1]['name'])->toBe(QualityAutomationCoordinator::LEARNING_SCHEDULE_NAME);
        expect($summary['follow_ups']['counts']['linked'])->toBe(1);
        expect($summary['follow_ups']['counts']['completed'])->toBe(1);
        expect($summary['follow_ups']['active'])->toHaveCount(0);
        expect($summary['follow_ups']['recent'][0]['evaluation_id'])->toBe($evaluationId);
        expect($summary['follow_ups']['recent'][0]['task_id'])->toBe($followUp['taskId']);
        expect($summary['follow_ups']['recent'][0]['trigger_metadata']['evaluation_id'])->toBe($evaluationId);
        expect($summary['follow_ups']['recent'][0]['learner_outcome']['skills_created'])->toBe(['quality-loop-review']);
        expect($summary['follow_ups']['recent'][0]['learner_outcome']['skills_updated'])->toBe(['tool-batching']);
    } finally {
        cleanupQualityStatusFixture($fixture);
    }
});
