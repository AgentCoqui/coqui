<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\SessionStorage;

function questionStorage(): SessionStorage
{
    return new SessionStorage(':memory:');
}

function sampleRequest(string $id = 'q1'): QuestionRequest
{
    return new QuestionRequest(
        id: $id,
        prompt: 'Which fruit?',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('apple'), new QuestionOption('pear')],
        allowOther: false,
        suggested: new QuestionResponse(['apple']),
    );
}

test('createQuestion then getQuestion returns a pending row', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');

    $storage->createQuestion($sessionId, sampleRequest(), 'interactive');

    $row = $storage->getQuestion('q1');
    expect($row)->not->toBeNull();
    expect($row['status'])->toBe('pending');
    expect($row['session_id'])->toBe($sessionId);
    expect($row['responder_kind'])->toBe('interactive');
});

test('getPendingQuestions lists only pending questions for the session', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $storage->createQuestion($sessionId, sampleRequest('q1'), 'suspending');
    $storage->createQuestion($sessionId, sampleRequest('q2'), 'suspending');

    $storage->recordQuestionAnswer('q1', new QuestionResponse(['apple']));

    $pending = $storage->getPendingQuestions($sessionId);
    expect(count($pending))->toBe(1);
    expect($pending[0]['id'])->toBe('q2');
});

test('recordQuestionAnswer marks answered and stores the answer', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $storage->createQuestion($sessionId, sampleRequest(), 'policy');

    expect($storage->recordQuestionAnswer('q1', new QuestionResponse(['pear'])))->toBeTrue();

    $row = $storage->getQuestion('q1');
    expect($row['status'])->toBe('answered');
    expect(json_decode($row['answer'], true)['selected'])->toBe(['pear']);
});

test('recordQuestionAnswer returns false when not pending', function () {
    $storage = questionStorage();
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $storage->createQuestion($sessionId, sampleRequest(), 'policy');
    $storage->recordQuestionAnswer('q1', new QuestionResponse(['pear']));

    expect($storage->recordQuestionAnswer('q1', new QuestionResponse(['apple'])))->toBeFalse();
});
