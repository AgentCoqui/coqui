<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\BackgroundTaskSummary;

test('fromRows separates agents from tools', function () {
    $rows = [
        [
            'id' => 'aaa',
            'status' => 'running',
            'title' => 'Refactor auth',
            'role' => 'coder',
            'tool_name' => null,
            'started_at' => '2026-03-29T10:00:00+00:00',
            'created_at' => '2026-03-29T09:59:00+00:00',
        ],
        [
            'id' => 'bbb',
            'status' => 'running',
            'title' => 'Scrape docs',
            'role' => 'orchestrator',
            'tool_name' => 'web_scrape',
            'started_at' => '2026-03-29T10:01:00+00:00',
            'created_at' => '2026-03-29T10:00:30+00:00',
        ],
    ];

    $summary = BackgroundTaskSummary::fromRows($rows);

    expect($summary->agentCount())->toBe(1);
    expect($summary->toolCount())->toBe(1);
    expect($summary->totalCount())->toBe(2);
    expect($summary->isEmpty())->toBeFalse();
    expect($summary->agents[0]['role'])->toBe('coder');
    expect($summary->agents[0]['title'])->toBe('Refactor auth');
    expect($summary->tools[0]['tool_name'])->toBe('web_scrape');
});

test('fromRows handles empty rows', function () {
    $summary = BackgroundTaskSummary::fromRows([]);

    expect($summary->isEmpty())->toBeTrue();
    expect($summary->totalCount())->toBe(0);
    expect($summary->agentCount())->toBe(0);
    expect($summary->toolCount())->toBe(0);
    expect($summary->agents)->toBe([]);
    expect($summary->tools)->toBe([]);
});

test('fromRows treats empty tool_name string as agent task', function () {
    $rows = [
        [
            'id' => 'ccc',
            'status' => 'pending',
            'title' => 'Research task',
            'role' => 'explorer',
            'tool_name' => '',
            'started_at' => null,
            'created_at' => '2026-03-29T10:00:00+00:00',
        ],
    ];

    $summary = BackgroundTaskSummary::fromRows($rows);

    expect($summary->agentCount())->toBe(1);
    expect($summary->toolCount())->toBe(0);
});

test('fromRows defaults role to orchestrator when missing', function () {
    $rows = [
        [
            'id' => 'ddd',
            'status' => 'running',
            'title' => null,
            'tool_name' => null,
            'started_at' => '2026-03-29T10:00:00+00:00',
            'created_at' => '2026-03-29T09:59:00+00:00',
        ],
    ];

    $summary = BackgroundTaskSummary::fromRows($rows);

    expect($summary->agents[0]['role'])->toBe('orchestrator');
    expect($summary->agents[0]['title'])->toBeNull();
});

test('formatDuration returns pending for pending tasks', function () {
    $task = [
        'status' => 'pending',
        'started_at' => null,
        'created_at' => '2026-03-29T10:00:00+00:00',
    ];

    expect(BackgroundTaskSummary::formatDuration($task))->toBe('pending');
});

test('formatDuration returns seconds for short durations', function () {
    $started = (new DateTimeImmutable())->modify('-30 seconds')->format('c');

    $task = [
        'status' => 'running',
        'started_at' => $started,
        'created_at' => $started,
    ];

    $result = BackgroundTaskSummary::formatDuration($task);

    // Should be around 30s (allow 1s tolerance for test execution)
    expect($result)->toMatch('/^\d{1,2}s$/');
});

test('formatDuration returns minutes and seconds for medium durations', function () {
    $started = (new DateTimeImmutable())->modify('-150 seconds')->format('c');

    $task = [
        'status' => 'running',
        'started_at' => $started,
        'created_at' => $started,
    ];

    $result = BackgroundTaskSummary::formatDuration($task);

    expect($result)->toMatch('/^2m \d{1,2}s$/');
});

test('formatDuration returns hours and minutes for long durations', function () {
    $started = (new DateTimeImmutable())->modify('-3700 seconds')->format('c');

    $task = [
        'status' => 'running',
        'started_at' => $started,
        'created_at' => $started,
    ];

    $result = BackgroundTaskSummary::formatDuration($task);

    expect($result)->toMatch('/^1h 1m$/');
});

test('formatDuration falls back to created_at when started_at is null', function () {
    $createdAt = (new DateTimeImmutable())->modify('-45 seconds')->format('c');

    $task = [
        'status' => 'running',
        'started_at' => null,
        'created_at' => $createdAt,
    ];

    $result = BackgroundTaskSummary::formatDuration($task);

    expect($result)->toMatch('/^\d{1,2}s$/');
});

test('formatDuration returns unknown for invalid timestamp', function () {
    $task = [
        'status' => 'running',
        'started_at' => 'not-a-date',
        'created_at' => 'also-not-a-date',
    ];

    expect(BackgroundTaskSummary::formatDuration($task))->toBe('unknown');
});

test('toArray serializes all fields', function () {
    $summary = BackgroundTaskSummary::fromRows([
        [
            'id' => 'aaa',
            'status' => 'running',
            'title' => 'Refactor auth',
            'role' => 'coder',
            'tool_name' => null,
            'started_at' => '2026-03-29T10:00:00+00:00',
            'created_at' => '2026-03-29T09:59:00+00:00',
        ],
        [
            'id' => 'bbb',
            'status' => 'pending',
            'title' => 'Scrape docs',
            'role' => 'orchestrator',
            'tool_name' => 'web_scrape',
            'started_at' => null,
            'created_at' => '2026-03-29T10:00:30+00:00',
        ],
    ]);

    $array = $summary->toArray();

    expect($array)->toHaveKeys(['agents', 'tools', 'total_count']);
    expect($array['total_count'])->toBe(2);
    expect($array['agents'])->toHaveCount(1);
    expect($array['agents'][0])->toHaveKeys(['id', 'status', 'title', 'role']);
    expect($array['tools'])->toHaveCount(1);
    expect($array['tools'][0])->toHaveKeys(['id', 'status', 'title', 'tool_name']);
});

test('constructor defaults to empty arrays', function () {
    $summary = new BackgroundTaskSummary();

    expect($summary->isEmpty())->toBeTrue();
    expect($summary->totalCount())->toBe(0);
    expect($summary->agents)->toBe([]);
    expect($summary->tools)->toBe([]);
});

test('multiple agents and tools are separated correctly', function () {
    $rows = [
        ['id' => '1', 'status' => 'running', 'title' => 'Code', 'role' => 'coder', 'tool_name' => null, 'started_at' => '2026-03-29T10:00:00+00:00', 'created_at' => '2026-03-29T09:59:00+00:00'],
        ['id' => '2', 'status' => 'running', 'title' => 'Review', 'role' => 'reviewer', 'tool_name' => null, 'started_at' => '2026-03-29T10:01:00+00:00', 'created_at' => '2026-03-29T10:00:00+00:00'],
        ['id' => '3', 'status' => 'pending', 'title' => 'Scrape', 'role' => 'orchestrator', 'tool_name' => 'web_scrape', 'started_at' => null, 'created_at' => '2026-03-29T10:02:00+00:00'],
        ['id' => '4', 'status' => 'running', 'title' => 'Process', 'role' => 'orchestrator', 'tool_name' => 'file_process', 'started_at' => '2026-03-29T10:03:00+00:00', 'created_at' => '2026-03-29T10:02:30+00:00'],
    ];

    $summary = BackgroundTaskSummary::fromRows($rows);

    expect($summary->agentCount())->toBe(2);
    expect($summary->toolCount())->toBe(2);
    expect($summary->totalCount())->toBe(4);
    expect($summary->agents[0]['role'])->toBe('coder');
    expect($summary->agents[1]['role'])->toBe('reviewer');
    expect($summary->tools[0]['tool_name'])->toBe('web_scrape');
    expect($summary->tools[1]['tool_name'])->toBe('file_process');
});
