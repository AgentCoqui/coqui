<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopLiveEvent;
use CoquiBot\Coqui\Contract\LoopLiveSnapshot;
use CoquiBot\Coqui\Contract\LoopLiveStage;

test('LoopLiveEvent serializes to snake_case array', function (): void {
    $event = new LoopLiveEvent('2026-07-11T10:00:00Z', 'stage-1', 'plan', 'tool_call', 'Called grep');

    expect($event->toArray())->toBe([
        'timestamp' => '2026-07-11T10:00:00Z',
        'stage_id' => 'stage-1',
        'role' => 'plan',
        'type' => 'tool_call',
        'summary' => 'Called grep',
    ]);
});

test('LoopLiveStage nests tokens and lists tools', function (): void {
    $stage = new LoopLiveStage(
        iterationNumber: 1,
        stageIndex: 0,
        role: 'plan',
        model: 'claude-sonnet-5',
        status: 'completed',
        promptTokens: 100,
        completionTokens: 50,
        totalTokens: 150,
        durationMs: 1200,
        toolsUsed: ['grep', 'read'],
        resultSummary: 'done',
        startedAt: '2026-07-11T10:00:00Z',
        completedAt: '2026-07-11T10:00:05Z',
        taskId: 'task-1',
    );

    expect($stage->toArray())->toMatchArray([
        'iteration_number' => 1,
        'stage_index' => 0,
        'role' => 'plan',
        'model' => 'claude-sonnet-5',
        'status' => 'completed',
        'tokens' => ['prompt' => 100, 'completion' => 50, 'total' => 150],
        'duration_ms' => 1200,
        'tools_used' => ['grep', 'read'],
        'result_summary' => 'done',
        'task_id' => 'task-1',
    ]);
});

test('LoopLiveSnapshot maps nested stages and events', function (): void {
    $snapshot = new LoopLiveSnapshot(
        loop: ['id' => 'loop-1'],
        position: ['current_iteration' => 1],
        currentStage: null,
        budget: ['tokens' => ['total' => 150]],
        stages: [new LoopLiveStage(1, 0, 'plan', null, 'completed', 0, 0, 0, 0, [], null, null, null, null)],
        recentEvents: [new LoopLiveEvent('2026-07-11T10:00:00Z', null, null, 'failed', 'boom')],
    );

    $array = $snapshot->toArray();
    expect($array['loop'])->toBe(['id' => 'loop-1']);
    expect($array['current_stage'])->toBeNull();
    expect($array['stages'])->toHaveCount(1);
    expect($array['stages'][0]['role'])->toBe('plan');
    expect($array['recent_events'][0]['type'])->toBe('failed');
});
