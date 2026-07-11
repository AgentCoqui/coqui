<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopStreamTracker;
use CoquiBot\Coqui\Contract\LoopStreamState;

function makeLoopStreamState(string $status = 'running', int $iter = 0, int $stage = 0, ?int $activity = null): LoopStreamState
{
    return new LoopStreamState($status, $iter, $stage, $activity);
}

test('initial running state emits stage_changed', function (): void {
    $event = LoopStreamTracker::diff(null, makeLoopStreamState('running', 0, 0, null));
    expect($event)->not->toBeNull();
    expect($event->type)->toBe('stage_changed');
    expect($event->data)->toBe(['iteration' => 0, 'stage_index' => 0, 'status' => 'running']);
});

test('initial terminal state emits done', function (): void {
    $event = LoopStreamTracker::diff(null, makeLoopStreamState('completed', 1, 2, 9));
    expect($event->type)->toBe('done');
    expect($event->data)->toBe(['status' => 'completed']);
});

test('stage advance emits stage_changed', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 0, 5), makeLoopStreamState('running', 0, 1, 5));
    expect($event->type)->toBe('stage_changed');
    expect($event->data['stage_index'])->toBe(1);
});

test('iteration advance emits stage_changed', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 1, 5), makeLoopStreamState('running', 1, 0, 5));
    expect($event->type)->toBe('stage_changed');
    expect($event->data['iteration'])->toBe(1);
});

test('pause emits stage_changed and keeps the stream open', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 0, 5), makeLoopStreamState('paused', 0, 0, 5));
    expect($event->type)->toBe('stage_changed');
    expect($event->data['status'])->toBe('paused');
});

test('new activity with unchanged position emits activity with cursor', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 0, 0, 5), makeLoopStreamState('running', 0, 0, 12));
    expect($event->type)->toBe('activity');
    expect($event->data)->toBe(['cursor' => 12]);
});

test('no change emits null', function (): void {
    expect(LoopStreamTracker::diff(makeLoopStreamState('running', 1, 1, 7), makeLoopStreamState('running', 1, 1, 7)))->toBeNull();
});

test('running to terminal emits done', function (): void {
    $event = LoopStreamTracker::diff(makeLoopStreamState('running', 2, 1, 20), makeLoopStreamState('failed', 2, 1, 20));
    expect($event->type)->toBe('done');
    expect($event->data)->toBe(['status' => 'failed']);
});

test('already-terminal previous emits null (done not repeated)', function (): void {
    expect(LoopStreamTracker::diff(makeLoopStreamState('completed', 2, 1, 20), makeLoopStreamState('completed', 2, 1, 20)))->toBeNull();
});
