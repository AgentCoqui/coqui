<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
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

test('toWire projects a multi_select to typed options + an array answer, pending → open', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $request = new QuestionRequest(
        id: 'qms',
        prompt: 'Which toppings?',
        format: QuestionFormat::MultiSelect,
        options: [new QuestionOption('cheese', 'Cheddar'), new QuestionOption('mushroom')],
        allowOther: false,
        suggested: new QuestionResponse(),
    );
    $storage->createQuestion($sessionId, $request, 'interactive');

    // Unanswered (pending) ⇒ status open, answer null.
    $open = QuestionPersistence::toWire($storage->getQuestion('qms'));
    expect($open['format'])->toBe('multi_select');
    expect($open['status'])->toBe('open');
    expect($open['answer'])->toBeNull();
    // Typed {value, label?}: value = option label; label = option description (dropped when null).
    expect($open['options'][0])->toBe(['value' => 'cheese', 'label' => 'Cheddar']);
    expect($open['options'][1])->toBe(['value' => 'mushroom']);

    // Answered ⇒ array answer of selected values.
    $storage->recordQuestionAnswer('qms', new QuestionResponse(selected: ['cheese', 'mushroom']));
    $answered = QuestionPersistence::toWire($storage->getQuestion('qms'));
    expect($answered['status'])->toBe('answered');
    expect($answered['answer'])->toBe(['cheese', 'mushroom']);
});

test('toWire collapses a single_select answer to a scalar and maps free_text → text', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');

    // single_select ⇒ scalar answer.
    $storage->createQuestion($sessionId, sampleRequest('qss'), 'interactive');
    $storage->recordQuestionAnswer('qss', new QuestionResponse(selected: ['pear']));
    $ss = QuestionPersistence::toWire($storage->getQuestion('qss'));
    expect($ss['format'])->toBe('single_select');
    expect($ss['answer'])->toBe('pear');

    // free_text ⇒ format text, no options (null), scalar text answer.
    $text = new QuestionRequest(
        id: 'qtext',
        prompt: 'Describe the issue.',
        format: QuestionFormat::FreeText,
        options: [],
        allowOther: false,
        suggested: new QuestionResponse(text: 'placeholder'),
    );
    $storage->createQuestion($sessionId, $text, 'interactive');
    $storage->recordQuestionAnswer('qtext', new QuestionResponse(text: 'It crashes on boot.'));
    $ft = QuestionPersistence::toWire($storage->getQuestion('qtext'));
    expect($ft['format'])->toBe('text');
    expect($ft['options'])->toBeNull();
    expect($ft['answer'])->toBe('It crashes on boot.');
});
