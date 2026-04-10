<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\EvaluationReadModel;
use CoquiBot\Coqui\Contract\EvaluationStatsReadModel;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

function createEvaluationStoreFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-evaluation-store-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'store' => new EvaluationStore($storage->getPdo()),
        'sessionId' => $storage->createSession('orchestrator', 'test-model'),
    ];
}

function cleanupEvaluationStoreFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

test('linkLearnerFollowUpTask persists explicit provenance on evaluations', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $evaluationId = $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'C',
            scoreCompletion: 0.5,
            scoreHallucination: 0.5,
            scoreEfficiency: 0.5,
            overallScore: 0.5,
            report: 'Needs work',
        );

        $taskSessionId = $fixture['storage']->createSession('learner', 'quality-automation');
        $taskId = $fixture['storage']->createTask(
            sessionId: $taskSessionId,
            prompt: 'Analyze evaluation',
            role: 'learner',
            title: 'Quality Learning Follow-up: ' . $evaluationId,
        );

        expect($fixture['store']->linkLearnerFollowUpTask($evaluationId, $taskId))->toBeTrue();

        $evaluation = $fixture['store']->get($evaluationId);

        expect($evaluation)->not->toBeNull();
        expect($evaluation['learner_follow_up_task_id'])->toBe($taskId);
        expect($evaluation['learner_follow_up_linked_at'])->not->toBeNull();
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('listLearnerFollowUps returns linked evaluation and task state', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $evaluationId = $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'D',
            scoreCompletion: 0.3,
            scoreHallucination: 0.4,
            scoreEfficiency: 0.2,
            overallScore: 0.32,
            report: 'Poor quality',
        );

        $taskSessionId = $fixture['storage']->createSession('learner', 'quality-automation');
        $taskId = $fixture['storage']->createTask(
            sessionId: $taskSessionId,
            prompt: 'Analyze evaluation',
            role: 'learner',
            title: 'Quality Learning Follow-up: ' . $evaluationId,
        );

        $fixture['store']->linkLearnerFollowUpTask($evaluationId, $taskId);
        $fixture['storage']->updateTaskStatus($taskId, 'running');

        $followUps = $fixture['store']->listLearnerFollowUps();

        expect($followUps)->toHaveCount(1);
        expect($followUps[0]['evaluation_id'])->toBe($evaluationId);
        expect($followUps[0]['learner_follow_up_task_id'])->toBe($taskId);
        expect($followUps[0]['learner_follow_up_status'])->toBe('running');
        expect($followUps[0]['session_title'])->toBeNull();
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('getLearnerFollowUpStats groups linked evaluations by task status', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $pendingEvaluationId = $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'D',
            scoreCompletion: 0.3,
            scoreHallucination: 0.4,
            scoreEfficiency: 0.2,
            overallScore: 0.32,
            report: 'Poor quality',
        );
        $completedEvaluationId = $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'F',
            scoreCompletion: 0.1,
            scoreHallucination: 0.1,
            scoreEfficiency: 0.1,
            overallScore: 0.1,
            report: 'Very poor quality',
        );

        $taskSessionId = $fixture['storage']->createSession('learner', 'quality-automation');
        $pendingTaskId = $fixture['storage']->createTask(
            sessionId: $taskSessionId,
            prompt: 'pending task',
            role: 'learner',
            title: 'Quality Learning Follow-up: ' . $pendingEvaluationId,
        );
        $completedTaskId = $fixture['storage']->createTask(
            sessionId: $taskSessionId,
            prompt: 'completed task',
            role: 'learner',
            title: 'Quality Learning Follow-up: ' . $completedEvaluationId,
        );

        $fixture['storage']->updateTaskStatus($completedTaskId, 'completed');
        $fixture['store']->linkLearnerFollowUpTask($pendingEvaluationId, $pendingTaskId);
        $fixture['store']->linkLearnerFollowUpTask($completedEvaluationId, $completedTaskId);

        $stats = $fixture['store']->getLearnerFollowUpStats();

        expect($stats['linked'])->toBe(2);
        expect($stats['by_status']['pending'])->toBe(1);
        expect($stats['by_status']['completed'])->toBe(1);
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('create persists structured evaluation metadata', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $evaluationId = $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'B',
            scoreCompletion: 0.8,
            scoreHallucination: 0.9,
            scoreEfficiency: 0.7,
            overallScore: 0.82,
            report: 'Strong performance',
            metadata: [
                'session_title' => 'Regression sweep',
                'child_run_ids' => ['child-1', 'child-2'],
                'child_run_count' => 2,
            ],
        );

        $evaluation = $fixture['store']->get($evaluationId);

        expect($evaluation)->not->toBeNull();
        expect(json_decode((string) $evaluation['metadata'], true)['child_run_count'])->toBe(2);
        expect(json_decode((string) $evaluation['metadata'], true)['session_title'])->toBe('Regression sweep');
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('getReadModel hydrates typed evaluation data and decodes metadata', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $evaluationId = $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'B',
            scoreCompletion: 0.8,
            scoreHallucination: 0.9,
            scoreEfficiency: 0.7,
            overallScore: 0.82,
            report: 'Strong performance',
            model: 'test-model',
            metadata: [
                'session_title' => 'Regression sweep',
                'child_run_ids' => ['child-1', 'child-2'],
            ],
        );

        $evaluation = $fixture['store']->getReadModel($evaluationId);

        expect($evaluation)->toBeInstanceOf(EvaluationReadModel::class);
        expect($evaluation?->id)->toBe($evaluationId);
        expect($evaluation?->sessionId)->toBe($fixture['sessionId']);
        expect($evaluation?->metadata)->toBe([
            'session_title' => 'Regression sweep',
            'child_run_ids' => ['child-1', 'child-2'],
        ]);
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('listReadModels filters typed evaluations by grade and session', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $otherSessionId = $fixture['storage']->createSession('orchestrator', 'second-model');

        $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'B',
            scoreCompletion: 0.8,
            scoreHallucination: 0.9,
            scoreEfficiency: 0.7,
            overallScore: 0.82,
            report: 'Strong performance',
        );
        $fixture['store']->create(
            sessionId: $otherSessionId,
            overallGrade: 'D',
            scoreCompletion: 0.3,
            scoreHallucination: 0.4,
            scoreEfficiency: 0.2,
            overallScore: 0.32,
            report: 'Poor performance',
        );

        $evaluations = $fixture['store']->listReadModels('B', $fixture['sessionId'], 10);

        expect($evaluations)->toHaveCount(1);
        expect($evaluations[0])->toBeInstanceOf(EvaluationReadModel::class);
        expect($evaluations[0]->overallGrade)->toBe('B');
        expect($evaluations[0]->sessionId)->toBe($fixture['sessionId']);
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('getStatsReadModel returns typed aggregate metrics', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'A',
            scoreCompletion: 0.9,
            scoreHallucination: 0.9,
            scoreEfficiency: 0.9,
            overallScore: 0.9,
            report: 'Excellent',
        );
        $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'C',
            scoreCompletion: 0.5,
            scoreHallucination: 0.5,
            scoreEfficiency: 0.5,
            overallScore: 0.5,
            report: 'Average',
        );

        $stats = $fixture['store']->getStatsReadModel();

        expect($stats)->toBeInstanceOf(EvaluationStatsReadModel::class);
        expect($stats->total)->toBe(2);
        expect($stats->gradeDistribution['A'])->toBe(1);
        expect($stats->gradeDistribution['C'])->toBe(1);
        expect($stats->avgOverall)->toBe(0.7);
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('updateLearnerOutcomeMetadata persists structured learner results', function () {
    $fixture = createEvaluationStoreFixture();

    try {
        $evaluationId = $fixture['store']->create(
            sessionId: $fixture['sessionId'],
            overallGrade: 'D',
            scoreCompletion: 0.3,
            scoreHallucination: 0.4,
            scoreEfficiency: 0.2,
            overallScore: 0.32,
            report: 'Poor performance',
        );

        expect($fixture['store']->updateLearnerOutcomeMetadata($evaluationId, [
            'task_status' => 'completed',
            'skills_created' => ['loop-review'],
            'skills_updated' => ['tool-batching'],
        ]))->toBeTrue();

        $evaluation = $fixture['store']->getReadModel($evaluationId);

        expect($evaluation?->learnerOutcomeMetadata)->toBe([
            'task_status' => 'completed',
            'skills_created' => ['loop-review'],
            'skills_updated' => ['tool-batching'],
        ]);
    } finally {
        cleanupEvaluationStoreFixture($fixture);
    }
});

test('legacy evaluation schema migrates before dependent indexes are created', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-evaluation-legacy-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $pdo = $storage->getPdo();

    try {
        $pdo->exec('DROP TABLE IF EXISTS evaluations');
        $pdo->exec(<<<'SQL'
            CREATE TABLE evaluations (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                evaluator_task_id TEXT,
                overall_grade TEXT NOT NULL,
                score_completion REAL NOT NULL,
                score_hallucination REAL NOT NULL,
                score_efficiency REAL NOT NULL,
                overall_score REAL NOT NULL,
                report TEXT NOT NULL,
                model TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $store = new EvaluationStore($pdo);

        $columns = array_column(
            $pdo->query('PRAGMA table_info(evaluations)')?->fetchAll(PDO::FETCH_ASSOC) ?? [],
            'name',
        );
        $indexes = array_column(
            $pdo->query('PRAGMA index_list(evaluations)')?->fetchAll(PDO::FETCH_ASSOC) ?? [],
            'name',
        );

        expect($store)->toBeInstanceOf(EvaluationStore::class);
        expect($columns)->toContain('learner_follow_up_task_id');
        expect($columns)->toContain('learner_follow_up_linked_at');
        expect($columns)->toContain('learner_outcome_metadata');
        expect($columns)->toContain('metadata');
        expect($indexes)->toContain('idx_evaluations_learner_follow_up_task');
    } finally {
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }
});

