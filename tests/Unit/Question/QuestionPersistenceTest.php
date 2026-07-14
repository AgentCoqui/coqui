<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;

test('persistAsked writes an audit row and a pending question', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $p = new QuestionPersistence($storage);

    $p->persistAsked($sessionId, sampleRequest(), 'interactive', turnId: null);

    expect(count($p->pending($sessionId)))->toBe(1);
    $audit = $storage->getPdo()->query("SELECT action FROM audit_log WHERE action = 'question_asked'")->fetchAll();
    expect(count($audit))->toBe(1);
});

test('persistAnswered rejects an invalid answer', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $p = new QuestionPersistence($storage);
    $request = sampleRequest();
    $p->persistAsked($sessionId, $request, 'suspending', turnId: null);

    expect($p->persistAnswered('q1', $sessionId, $request, new QuestionResponse(['nope'])))->toBeFalse();
    expect($p->find('q1')->status)->toBe('pending');
});

test('persistAnswered stores a valid answer and audits it', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $p = new QuestionPersistence($storage);
    $request = sampleRequest();
    $p->persistAsked($sessionId, $request, 'suspending', turnId: null);

    expect($p->persistAnswered('q1', $sessionId, $request, new QuestionResponse(['pear'])))->toBeTrue();
    expect($p->find('q1')->answer->selected)->toBe(['pear']);
    $audit = $storage->getPdo()->query("SELECT action FROM audit_log WHERE action = 'question_answered'")->fetchAll();
    expect(count($audit))->toBe(1);
});
