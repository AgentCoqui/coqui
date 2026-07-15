<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopBlockNotifier;
use CoquiBot\Coqui\Contract\OnQuestionPolicy;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Question\PolicyQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;

test('OnQuestionPolicy defaults to block', function () {
    expect(OnQuestionPolicy::fromString(null))->toBe(OnQuestionPolicy::Block);
    expect(OnQuestionPolicy::fromString('nonsense'))->toBe(OnQuestionPolicy::Block);
    expect(OnQuestionPolicy::fromString('default'))->toBe(OnQuestionPolicy::DefaultAnswer);
});

test('default policy returns the suggestion and marks it answered', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $responder = new PolicyQuestionResponder(
        OnQuestionPolicy::DefaultAnswer, new QuestionPersistence($storage), $sessionId,
    );
    $request = sampleRequest();

    $answer = $responder->ask($request);

    expect($answer->selected)->toBe(['apple']);
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});

test('block policy persists the question, escalates the loop, and returns null', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');

    $blocked = [];
    $notifier = new class($blocked) implements LoopBlockNotifier {
        public function __construct(public array &$blocked) {}
        public function block(string $loopId, ?string $stageId, QuestionRequest $q): void
        {
            $this->blocked[] = [$loopId, $stageId, $q->id];
        }
    };

    $responder = new PolicyQuestionResponder(
        OnQuestionPolicy::Block, new QuestionPersistence($storage), $sessionId,
        loopBlock: $notifier, loopId: 'loop1', stageId: 'stage1',
    );

    $result = $responder->ask(sampleRequest());

    expect($result)->toBeNull();
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
    expect($blocked)->toBe([['loop1', 'stage1', 'q1']]);
});

test('block policy without a loop notifier still persists and returns null', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $responder = new PolicyQuestionResponder(
        OnQuestionPolicy::Block, new QuestionPersistence($storage), $sessionId,
    );

    expect($responder->ask(sampleRequest()))->toBeNull();
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
});
