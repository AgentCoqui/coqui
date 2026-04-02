<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ToolUsageTracker;

function createUsageTrackerDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    // Create the turns table matching SessionStorage schema
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS turns (
            id TEXT PRIMARY KEY,
            session_id TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            content TEXT NOT NULL DEFAULT '',
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            total_tokens INTEGER NOT NULL DEFAULT 0,
            iterations INTEGER NOT NULL DEFAULT 0,
            duration_ms INTEGER NOT NULL DEFAULT 0,
            model TEXT NOT NULL DEFAULT '',
            tools_used TEXT DEFAULT NULL,
            child_agent_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT(datetime('now'))
        )
        SQL);

    return $db;
}

function insertTurn(PDO $db, string $sessionId, string $toolsUsed): void
{
    static $counter = 0;
    $counter++;
    $stmt = $db->prepare('INSERT INTO turns (id, session_id, role, tools_used) VALUES (?, ?, ?, ?)');
    $stmt->execute(["turn-{$counter}", $sessionId, 'assistant', $toolsUsed]);
}

test('returns empty frequency map when no turns exist', function () {
    $db = createUsageTrackerDb();
    $tracker = new ToolUsageTracker($db);

    expect($tracker->getFrequencyMap())->toBe([]);
    expect($tracker->getTopTools())->toBe([]);
});

test('counts tool usage from turns', function () {
    $db = createUsageTrackerDb();

    insertTurn($db, 'session-1', json_encode(['read_file', 'write_file']));
    insertTurn($db, 'session-1', json_encode(['read_file', 'exec']));
    insertTurn($db, 'session-2', json_encode(['read_file']));

    $tracker = new ToolUsageTracker($db);
    $map = $tracker->getFrequencyMap();

    expect($map['read_file'])->toBe(3);
    expect($map['write_file'])->toBe(1);
    expect($map['exec'])->toBe(1);
});

test('getTopTools returns limit number of tools sorted by frequency', function () {
    $db = createUsageTrackerDb();

    insertTurn($db, 's1', json_encode(['tool_a', 'tool_b', 'tool_c']));
    insertTurn($db, 's1', json_encode(['tool_a', 'tool_b']));
    insertTurn($db, 's1', json_encode(['tool_a']));

    $tracker = new ToolUsageTracker($db);

    $top = $tracker->getTopTools(2);
    expect($top)->toHaveCount(2);
    expect($top[0])->toBe('tool_a');
    expect($top[1])->toBe('tool_b');
});

test('getToolkitFrequency aggregates by toolkit', function () {
    $db = createUsageTrackerDb();

    insertTurn($db, 's1', json_encode(['read_file', 'write_file', 'exec']));
    insertTurn($db, 's1', json_encode(['read_file', 'search_files']));

    $tracker = new ToolUsageTracker($db);
    $freq = $tracker->getToolkitFrequency([
        'FileSystemToolkit' => ['read_file', 'write_file', 'search_files'],
        'ShellToolkit' => ['exec'],
    ]);

    expect($freq['FileSystemToolkit'])->toBe(4); // read_file(2) + write_file(1) + search_files(1)
    expect($freq['ShellToolkit'])->toBe(1);
});

test('getToolUsageCount returns count for a single tool', function () {
    $db = createUsageTrackerDb();

    insertTurn($db, 's1', json_encode(['read_file']));
    insertTurn($db, 's1', json_encode(['read_file']));

    $tracker = new ToolUsageTracker($db);

    expect($tracker->getToolUsageCount('read_file'))->toBe(2);
    expect($tracker->getToolUsageCount('nonexistent'))->toBe(0);
});

test('skips turns with null or empty tools_used', function () {
    $db = createUsageTrackerDb();

    insertTurn($db, 's1', json_encode(['read_file']));
    insertTurn($db, 's1', '[]');
    // Insert a null tools_used
    $db->exec("INSERT INTO turns (id, session_id, role, tools_used) VALUES ('null-turn', 's1', 'user', NULL)");

    $tracker = new ToolUsageTracker($db);
    $map = $tracker->getFrequencyMap();

    expect($map)->toHaveCount(1);
    expect($map['read_file'])->toBe(1);
});

test('refresh forces cache update', function () {
    $db = createUsageTrackerDb();
    $tracker = new ToolUsageTracker($db);

    insertTurn($db, 's1', json_encode(['read_file']));

    $map1 = $tracker->getFrequencyMap();
    expect($map1['read_file'])->toBe(1);

    // Add more data
    insertTurn($db, 's1', json_encode(['read_file']));

    // Should still return cached value
    $map2 = $tracker->getFrequencyMap();
    expect($map2['read_file'])->toBe(1);

    // After refresh, should pick up new data
    $tracker->refresh();
    $map3 = $tracker->getFrequencyMap();
    expect($map3['read_file'])->toBe(2);
});

test('getToolkitFrequency returns empty map for empty input', function () {
    $db = createUsageTrackerDb();
    $tracker = new ToolUsageTracker($db);

    expect($tracker->getToolkitFrequency([]))->toBe([]);
});

test('frequency map is sorted descending by count', function () {
    $db = createUsageTrackerDb();

    insertTurn($db, 's1', json_encode(['tool_rare']));
    insertTurn($db, 's1', json_encode(['tool_common', 'tool_common', 'tool_mid']));
    insertTurn($db, 's1', json_encode(['tool_common']));

    $tracker = new ToolUsageTracker($db);
    $keys = array_keys($tracker->getFrequencyMap());

    expect($keys[0])->toBe('tool_common');
});
