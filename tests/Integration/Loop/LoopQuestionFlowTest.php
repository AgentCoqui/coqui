<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopQuestionAnswerReopener;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\LoopQuestionBlockNotifier;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

test('block notifier flips the loop to blocked with the question as escalation', function () {
    $storage = new SessionStorage(':memory:');
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = bootstrapRunningLoop($loopStore);
    $notifier = new LoopQuestionBlockNotifier($loopStore);

    $notifier->block($loopId, null, sampleRequest());

    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('blocked');

    $meta = json_decode($loop['metadata'], true);
    expect($meta['escalation']['question']['id'])->toBe('q1');
    expect($meta['escalation']['reason'])->toContain('Which fruit?');

    // The current iteration is flipped to needs_rework so a retry can reopen it.
    $state = $loopStore->getCurrentState($loopId);
    expect($state['iteration']['status'])->toBe('needs_rework');
});

test('answer reopener unblocks the loop and stages the answer for injection', function () {
    $storage = new SessionStorage(':memory:');
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = bootstrapRunningLoop($loopStore);
    (new LoopQuestionBlockNotifier($loopStore))->block($loopId, null, sampleRequest());

    $reopener = new LoopQuestionAnswerReopener($loopStore);
    $reopener->reopen($loopId, null, sampleRequest(), new QuestionResponse(['pear']));

    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');

    $meta = json_decode($loop['metadata'], true);
    expect($meta['pending_answer']['answer']['selected'])->toBe(['pear']);
    expect($meta['pending_answer']['question']['prompt'])->toBe('Which fruit?');
    // Escalation is cleared and the rework breaker reset, mirroring a #3 retry.
    expect($meta['escalation'])->toBeNull();
    expect($meta['rework_attempts'])->toBe(0);

    // The iteration is reopened (running) and its stage reset to pending.
    $state = $loopStore->getCurrentState($loopId);
    expect($state['iteration']['status'])->toBe('running');
    expect($state['stages'][0]['status'])->toBe('pending');
});

test('answer reopener leaves a running (non-blocked) loop untouched', function () {
    $storage = new SessionStorage(':memory:');
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = bootstrapRunningLoop($loopStore);

    // Capture the live iteration/stage state of the RUNNING loop before answering.
    $before = $loopStore->getCurrentState($loopId);

    // A default-mode question also carries loop_id; answering it in the pending
    // window (racing the atomic answer guard) must NOT reset the live iteration.
    $reopener = new LoopQuestionAnswerReopener($loopStore);
    $reopener->reopen($loopId, null, sampleRequest(), new QuestionResponse(['pear']));

    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');

    // No pending_answer was staged (metadata is never touched at all).
    $meta = $loop['metadata'] === null ? [] : json_decode($loop['metadata'], true);
    expect($meta['pending_answer'] ?? null)->toBeNull();

    // Iteration and stage state are unchanged (no reset happened).
    $after = $loopStore->getCurrentState($loopId);
    expect($after['iteration']['id'])->toBe($before['iteration']['id']);
    expect($after['iteration']['status'])->toBe($before['iteration']['status']);
    expect($after['stages'][0]['status'])->toBe($before['stages'][0]['status']);
});
