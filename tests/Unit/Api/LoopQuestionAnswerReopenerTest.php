<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopQuestionAnswerReopener;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\LoopQuestionBlockNotifier;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Unit coverage for LoopQuestionAnswerReopener.
 *
 * The reset sequence (status -> running, escalation cleared, rework breaker
 * reset, pending_answer staged) is also exercised end-to-end by
 * tests/Integration/Loop/LoopQuestionFlowTest.php. This file additionally
 * pins the Task-4 E3 addition: the `dispatch` metadata block the manager reads
 * to schedule stage 0 on the next tick — which no other test asserts.
 */

test('reopen runs the reset sequence and writes the E3 dispatch block', function () {
    $storage = new SessionStorage(':memory:');
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = bootstrapRunningLoop($loopStore);

    // Block the loop on a question so there is a genuine escalation to reopen.
    (new LoopQuestionBlockNotifier($loopStore))->block($loopId, null, sampleRequest());
    expect($loopStore->getLoop($loopId)['status'])->toBe('blocked');

    // Capture the iteration id the dispatch block must reference.
    $blockedState = $loopStore->getCurrentState($loopId);
    $iterationId = (string) $blockedState['iteration']['id'];

    $reopener = new LoopQuestionAnswerReopener($loopStore);
    $reopener->reopen($loopId, null, sampleRequest(), new QuestionResponse(['pear']));

    $loop = $loopStore->getLoop($loopId);
    $meta = json_decode($loop['metadata'], true);

    // --- reset sequence ---
    expect($loop['status'])->toBe('running');
    expect($meta['escalation'])->toBeNull();
    expect((int) $loop['rework_attempts'])->toBe(0);
    expect($meta['pending_answer']['answer']['selected'])->toBe(['pear']);

    // --- E3 dispatch block (the branch this test exists to guard) ---
    expect($meta)->toHaveKey('dispatch');
    $dispatch = $meta['dispatch'];
    expect($dispatch['status'])->toBe('pending');
    expect($dispatch['stage_index'])->toBe(0);
    expect($dispatch['iteration_id'])->toBe($iterationId);
    expect($dispatch['message'])->toBeString()->not->toBe('');
    expect($dispatch)->toHaveKey('updated_at');
    expect($dispatch['updated_at'])->toBeString()->not->toBe('');
});

test('reopen on a running (non-blocked) loop writes no dispatch block', function () {
    $storage = new SessionStorage(':memory:');
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = bootstrapRunningLoop($loopStore);

    // The loop is running, not blocked: the guard short-circuits before any
    // metadata write, so no dispatch block (and no pending_answer) appears.
    $reopener = new LoopQuestionAnswerReopener($loopStore);
    $reopener->reopen($loopId, null, sampleRequest(), new QuestionResponse(['pear']));

    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');

    $meta = $loop['metadata'] === null ? [] : json_decode($loop['metadata'], true);
    expect($meta['dispatch'] ?? null)->toBeNull();
    expect($meta['pending_answer'] ?? null)->toBeNull();
});
