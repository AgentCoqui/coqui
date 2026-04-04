<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\EvaluationHandler;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createEvaluationHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-evaluation-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new EvaluationStore($storage->getPdo());
    $sessionIdA = $storage->createSession('orchestrator', 'test-model-a');
    $sessionIdB = $storage->createSession('orchestrator', 'test-model-b');

    $evaluationIdA = $store->create(
        sessionId: $sessionIdA,
        overallGrade: 'B',
        scoreCompletion: 0.8,
        scoreHallucination: 0.9,
        scoreEfficiency: 0.7,
        overallScore: 0.82,
        report: 'Strong performance',
        metadata: ['child_run_count' => 2],
    );
    $evaluationIdB = $store->create(
        sessionId: $sessionIdB,
        overallGrade: 'D',
        scoreCompletion: 0.3,
        scoreHallucination: 0.4,
        scoreEfficiency: 0.2,
        overallScore: 0.32,
        report: 'Poor performance',
    );

    return [
        'dbPath' => $dbPath,
        'store' => $store,
        'handler' => new EvaluationHandler($store),
        'sessionIdA' => $sessionIdA,
        'sessionIdB' => $sessionIdB,
        'evaluationIdA' => $evaluationIdA,
        'evaluationIdB' => $evaluationIdB,
    ];
}

function cleanupEvaluationHandlerFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

test('evaluation handler lists evaluations with filters', function () {
    $fixture = createEvaluationHandlerFixture();

    try {
        $request = (new ServerRequest('GET', '/api/v1/evaluations'))->withQueryParams([
            'grade' => 'B',
            'session_id' => $fixture['sessionIdA'],
            'limit' => '10',
        ]);

        $response = $fixture['handler']->list($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['count'])->toBe(1);
        expect($body['filters']['grade'])->toBe('B');
        expect($body['filters']['session_id'])->toBe($fixture['sessionIdA']);
        expect($body['evaluations'][0]['id'])->toBe($fixture['evaluationIdA']);
        expect($body['evaluations'][0])->not->toHaveKey('report');
    } finally {
        cleanupEvaluationHandlerFixture($fixture);
    }
});

test('evaluation handler returns full evaluation detail', function () {
    $fixture = createEvaluationHandlerFixture();

    try {
        $response = $fixture['handler']->get(
            new ServerRequest('GET', '/api/v1/evaluations/' . $fixture['evaluationIdA']),
            $fixture['evaluationIdA'],
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['id'])->toBe($fixture['evaluationIdA']);
        expect($body['report'])->toBe('Strong performance');
        expect($body['metadata']['child_run_count'])->toBe(2);
    } finally {
        cleanupEvaluationHandlerFixture($fixture);
    }
});

test('evaluation handler returns not found for missing evaluation', function () {
    $fixture = createEvaluationHandlerFixture();

    try {
        $response = $fixture['handler']->get(
            new ServerRequest('GET', '/api/v1/evaluations/missing'),
            'missing',
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(404);
        expect($body['error'])->toBe('Evaluation not found');
        expect($body['code'])->toBe('not_found');
    } finally {
        cleanupEvaluationHandlerFixture($fixture);
    }
});

test('evaluation handler returns aggregate stats', function () {
    $fixture = createEvaluationHandlerFixture();

    try {
        $response = $fixture['handler']->stats(new ServerRequest('GET', '/api/v1/evaluations/stats'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['total'])->toBe(2);
        expect($body['grade_distribution']['B'])->toBe(1);
        expect($body['grade_distribution']['D'])->toBe(1);
    } finally {
        cleanupEvaluationHandlerFixture($fixture);
    }
});