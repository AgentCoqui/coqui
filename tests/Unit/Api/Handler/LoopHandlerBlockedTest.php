<?php

declare(strict_types=1);

use React\Http\Message\ServerRequest;

// Reuses the createLoopHandlerFixture()/cleanupLoopHandlerFixture() harness
// defined in LoopHandlerTest.php (same directory, loaded by Pest).

test('a blocked loop can be retried with a note via the REST retry path', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/loops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'definition' => 'harness',
                    'goal' => 'Recover a blocked loop iteration',
                    'parameters' => ['subject' => 'loop recovery'],
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $createResponse->getBody(), true);
        $loopId = $createdBody['loop']['id'];
        $iterationId = $createdBody['iteration']['id'];
        $stages = $fixture['loopStore']->listStages($iterationId);

        // Drive the loop into a `blocked` escalation the way the executor does.
        $fixture['loopStore']->updateStage($stages[0]['id'], 'completed', taskId: 'task-plan', resultSummary: 'Plan done');
        $fixture['loopStore']->updateStage($stages[1]['id'], 'completed', taskId: 'task-review', resultSummary: 'Reviewer blocked');
        $fixture['loopStore']->recordStageVerdict($stages[1]['id'], json_encode(['verdict' => 'blocked', 'summary' => 'Needs human input']) ?: '');
        $fixture['loopStore']->setReworkAttempts($loopId, 3);
        $fixture['loopStore']->updateLoopMetadata($loopId, [
            'escalation' => ['reason' => 'Reviewer escalated', 'attempts' => 3],
        ]);
        $fixture['loopStore']->updateIterationStatus($iterationId, 'needs_rework', 'blocked');
        $fixture['loopStore']->updateLoopStatus($loopId, 'blocked');

        // Store-level preconditions the handler relies on.
        expect($fixture['loopStore']->getLoop($loopId)['status'])->toBe('blocked');
        expect($fixture['loopStore']->getIteration($iterationId)['status'])->toBe('needs_rework');

        // HTTP-level: retry carrying a note against a blocked loop → 200.
        $response = $fixture['handler']->retryIteration(
            new ServerRequest(
                'POST',
                '/api/v1/loops/' . $loopId . '/iterations/' . $iterationId . '/retry',
                ['Content-Type' => 'application/json'],
                json_encode(['note' => 'Focus on the failing auth path.']) ?: '',
            ),
            $loopId,
            $iterationId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['loop']['status'])->toBe('running');
        expect($body['iteration']['id'])->toBe($iterationId);
        expect($body['iteration']['status'])->toBe('running');
        // HTTP-level: the note is stored as pending_guidance and rework_attempts is cleared.
        expect($body['loop']['metadata']['pending_guidance'])->toBe('Focus on the failing auth path.');
        // rework_attempts is a real column now (CORE-16), surfaced top-level on the loop.
        expect((int) $body['loop']['rework_attempts'])->toBe(0);

        // Store-level confirmation of the persisted state.
        $stored = $fixture['loopStore']->getLoop($loopId);
        $storedMeta = json_decode((string) $stored['metadata'], true);
        expect($storedMeta['pending_guidance'])->toBe('Focus on the failing auth path.');
        expect((int) $stored['rework_attempts'])->toBe(0);
        expect($stored['status'])->toBe('running');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('a blocked loop retried without a note clears pending guidance', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/loops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'definition' => 'harness',
                    'goal' => 'Recover a blocked loop iteration',
                    'parameters' => ['subject' => 'loop recovery'],
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $createResponse->getBody(), true);
        $loopId = $createdBody['loop']['id'];
        $iterationId = $createdBody['iteration']['id'];

        $fixture['loopStore']->updateIterationStatus($iterationId, 'needs_rework', 'blocked');
        $fixture['loopStore']->updateLoopStatus($loopId, 'blocked');

        $response = $fixture['handler']->retryIteration(
            new ServerRequest('POST', '/api/v1/loops/' . $loopId . '/iterations/' . $iterationId . '/retry'),
            $loopId,
            $iterationId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['loop']['status'])->toBe('running');
        expect($body['loop']['metadata']['pending_guidance'])->toBeNull();
        expect((int) $body['loop']['rework_attempts'])->toBe(0);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop get serialization exposes escalation and stage verdict', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/loops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'definition' => 'harness',
                    'goal' => 'Inspect escalation + verdict serialization',
                    'parameters' => ['subject' => 'loop recovery'],
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $createResponse->getBody(), true);
        $loopId = $createdBody['loop']['id'];
        $iterationId = $createdBody['iteration']['id'];
        $stages = $fixture['loopStore']->listStages($iterationId);

        $fixture['loopStore']->recordStageVerdict(
            $stages[1]['id'],
            json_encode(['verdict' => 'needs_changes', 'summary' => 'Add tests']) ?: '',
        );
        $fixture['loopStore']->updateLoopMetadata($loopId, [
            'escalation' => ['reason' => 'Reviewer escalated', 'attempts' => 2],
        ]);
        $fixture['loopStore']->updateIterationStatus($iterationId, 'needs_rework', 'blocked');
        $fixture['loopStore']->updateLoopStatus($loopId, 'blocked');

        $response = $fixture['handler']->get(
            new ServerRequest('GET', '/api/v1/loops/' . $loopId),
            $loopId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['loop']['escalation'])->toBe(['reason' => 'Reviewer escalated', 'attempts' => 2]);

        $byIndex = [];
        foreach ($body['stages'] as $stage) {
            $byIndex[(int) $stage['stage_index']] = $stage;
        }
        expect($byIndex[0]['verdict'])->toBeNull();
        expect($byIndex[1]['verdict'])->toBe(['verdict' => 'needs_changes', 'summary' => 'Add tests']);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop get serialization returns null escalation when none is recorded', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $loopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'No escalation here',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 2,
        );

        $response = $fixture['handler']->get(
            new ServerRequest('GET', '/api/v1/loops/' . $loopId),
            $loopId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['loop'])->toHaveKey('escalation');
        expect($body['loop']['escalation'])->toBeNull();
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});
