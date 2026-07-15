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
