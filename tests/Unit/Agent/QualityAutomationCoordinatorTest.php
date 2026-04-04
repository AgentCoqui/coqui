<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\QualityAutomationCoordinator;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

function createQualityAutomationFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-quality-automation-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'scheduleStore' => new ScheduleStore($storage->getPdo()),
        'evaluationStore' => new EvaluationStore($storage->getPdo()),
    ];
}

function cleanupQualityAutomationFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

test('ensureDefaultSchedules creates evaluator and learner schedules once', function () {
    $fixture = createQualityAutomationFixture();

    try {
        $config = OpenClawConfig::fromArray([]);
        $coordinator = new QualityAutomationCoordinator($config, $fixture['storage'], $fixture['scheduleStore']);

        $created = $coordinator->ensureDefaultSchedules();

        expect($created)->toBe([
            'system:quality:evaluator',
            'system:quality:learner',
        ]);

        expect($fixture['scheduleStore']->getByName('system:quality:evaluator'))->not->toBeNull();
        expect($fixture['scheduleStore']->getByName('system:quality:learner'))->not->toBeNull();
        expect($coordinator->ensureDefaultSchedules())->toBe([]);
    } finally {
        cleanupQualityAutomationFixture($fixture);
    }
});

test('ensureDefaultSchedules respects bootstrap disable flag', function () {
    $fixture = createQualityAutomationFixture();

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'quality' => [
                        'bootstrapSchedules' => false,
                    ],
                ],
            ],
        ]);

        $coordinator = new QualityAutomationCoordinator($config, $fixture['storage'], $fixture['scheduleStore']);

        expect($coordinator->ensureDefaultSchedules())->toBe([]);
        expect($fixture['scheduleStore']->getByName('system:quality:evaluator'))->toBeNull();
        expect($fixture['scheduleStore']->getByName('system:quality:learner'))->toBeNull();
    } finally {
        cleanupQualityAutomationFixture($fixture);
    }
});

test('queueLearnerFollowUp creates one learner task for poor evaluation and dedupes repeats', function () {
    $fixture = createQualityAutomationFixture();

    try {
        $config = OpenClawConfig::fromArray([]);
        $sessionId = $fixture['storage']->createSession('orchestrator', 'test-model');
        $evaluationId = $fixture['evaluationStore']->create(
            sessionId: $sessionId,
            overallGrade: 'D',
            scoreCompletion: 0.4,
            scoreHallucination: 0.5,
            scoreEfficiency: 0.3,
            overallScore: 0.42,
            report: 'Poor evaluation',
            metadata: [
                'session_title' => 'Regression sweep',
                'evidence_sources' => ['transcript', 'child_runs'],
                'child_run_ids' => ['child-1', 'child-2'],
                'child_run_count' => 2,
            ],
        );

        $coordinator = new QualityAutomationCoordinator($config, $fixture['storage'], null, $fixture['evaluationStore']);

        $first = $coordinator->queueLearnerFollowUp($evaluationId, $sessionId, 'D', 0.42);
        $second = $coordinator->queueLearnerFollowUp($evaluationId, $sessionId, 'D', 0.42);

        expect($first)->not->toBeNull();
        expect($first['status'])->toBe('created');
        expect($second)->not->toBeNull();
        expect($second['status'])->toBe('existing');
        expect($second['taskId'])->toBe($first['taskId']);

        $task = $fixture['storage']->getTask($first['taskId']);
        $evaluation = $fixture['evaluationStore']->get($evaluationId);

        expect($task)->not->toBeNull();
        expect($task['role'])->toBe('learner');
        expect($task['title'])->toBe('Quality Learning Follow-up: ' . $evaluationId);
        expect($task['prompt'])->toContain('Analyze evaluation ' . $evaluationId);
        expect($task['prompt'])->toContain('Session title: Regression sweep');
        $taskMetadata = json_decode((string) $task['metadata'], true);
        expect($taskMetadata['session_title'])->toBe('Regression sweep');
        expect($taskMetadata['evidence_sources'])->toBe(['transcript', 'child_runs']);
        expect($taskMetadata['child_run_ids'])->toBe(['child-1', 'child-2']);
        expect($taskMetadata['child_run_count'])->toBe(2);
        expect($evaluation)->not->toBeNull();
        expect($evaluation['learner_follow_up_task_id'])->toBe($first['taskId']);
    } finally {
        cleanupQualityAutomationFixture($fixture);
    }
});

test('queueLearnerFollowUp skips strong evaluations', function () {
    $fixture = createQualityAutomationFixture();

    try {
        $config = OpenClawConfig::fromArray([]);
        $coordinator = new QualityAutomationCoordinator($config, $fixture['storage']);

        expect($coordinator->queueLearnerFollowUp('eval-strong', 'session-xyz', 'A', 0.98))->toBeNull();
    } finally {
        cleanupQualityAutomationFixture($fixture);
    }
});
