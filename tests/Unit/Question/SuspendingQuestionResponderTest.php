<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Question\SuspendingQuestionResponder;
use CoquiBot\Coqui\Question\QuestionUnansweredException;
use CoquiBot\Coqui\Storage\SessionStorage;

test('ask emits a question turn-event and returns the answer once recorded', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $turnProcessId = $storage->createTurnProcess($sessionId, 'go');
    $persistence = new QuestionPersistence($storage);

    // Sleeper records the answer on the 2nd poll, simulating the REST endpoint.
    $calls = 0;
    $sleeper = function () use (&$calls, $storage): void {
        if (++$calls === 2) {
            $storage->recordQuestionAnswer('q1', new QuestionResponse(['pear']));
        }
    };

    $responder = new SuspendingQuestionResponder(
        $persistence, $storage, $sessionId, $turnProcessId,
        pollIntervalMicros: 1, timeoutSeconds: 5, sleeper: $sleeper,
    );

    $answer = $responder->ask(sampleRequest());

    expect($answer->selected)->toBe(['pear']);
    $events = $storage->getTurnEvents($turnProcessId);
    $types = array_map(fn($e) => $e['event_type'], $events);
    expect($types)->toContain('question');
});

test('ask throws when it times out with no answer', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $turnProcessId = $storage->createTurnProcess($sessionId, 'go');

    $responder = new SuspendingQuestionResponder(
        new QuestionPersistence($storage), $storage, $sessionId, $turnProcessId,
        pollIntervalMicros: 1, timeoutSeconds: 0, sleeper: fn() => null,
    );

    expect(fn() => $responder->ask(sampleRequest()))->toThrow(QuestionUnansweredException::class);
});
