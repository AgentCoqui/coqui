<?php

declare(strict_types=1);

use CoquiBot\Coqui\Memory\MemoryEntry;
use CoquiBot\Coqui\Memory\MemoryStore;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-test-memory-' . bin2hex(random_bytes(8)) . '.db';
    $this->store = new MemoryStore($this->dbPath);
});

afterEach(function () {
    unset($this->store);

    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }

    // Clean up WAL/SHM files
    foreach (['-wal', '-shm'] as $suffix) {
        $path = $this->dbPath . $suffix;
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

// --- save & getById ---

test('save returns a 32-character hex id', function () {
    $entry = new MemoryEntry(content: 'User prefers dark mode', area: 'preferences');

    $id = $this->store->save($entry);

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
    expect(ctype_xdigit($id))->toBeTrue();
});

test('getById retrieves a saved memory', function () {
    $entry = new MemoryEntry(
        content: 'User prefers PHP 8.4',
        area: 'preferences',
        metadata: ['tags' => 'php,preferences'],
    );

    $id = $this->store->save($entry);
    $retrieved = $this->store->getById($id);

    expect($retrieved)->not->toBeNull();
    expect($retrieved->content)->toBe('User prefers PHP 8.4');
    expect($retrieved->area)->toBe('preferences');
    expect($retrieved->id)->toBe($id);
    expect($retrieved->metadata['tags'])->toBe('php,preferences');
});

test('getById returns null for nonexistent id', function () {
    $result = $this->store->getById('nonexistent-id-that-does-not-exist');

    expect($result)->toBeNull();
});

test('save preserves created_at timestamp', function () {
    $entry = new MemoryEntry(content: 'timestamped memory', area: 'facts');

    $id = $this->store->save($entry);
    $retrieved = $this->store->getById($id);

    expect($retrieved->createdAt)->not->toBeNull();
    expect($retrieved->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
});

// --- update ---

test('update changes content of existing memory', function () {
    $entry = new MemoryEntry(content: 'Original content', area: 'facts');
    $id = $this->store->save($entry);

    $result = $this->store->update($id, 'Updated content');

    expect($result)->toBeTrue();

    $retrieved = $this->store->getById($id);
    expect($retrieved->content)->toBe('Updated content');
    expect($retrieved->area)->toBe('facts');
});

test('update can change area and tags', function () {
    $entry = new MemoryEntry(content: 'Some fact', area: 'facts', metadata: ['tags' => 'old']);
    $id = $this->store->save($entry);

    $this->store->update($id, 'Some fact updated', area: 'solutions', tags: 'new,updated');

    $retrieved = $this->store->getById($id);
    expect($retrieved->content)->toBe('Some fact updated');
    expect($retrieved->area)->toBe('solutions');
    expect($retrieved->metadata['tags'])->toBe('new,updated');
});

test('update returns false for nonexistent id', function () {
    $result = $this->store->update('nonexistent-id', 'New content');

    expect($result)->toBeFalse();
});

test('update preserves area when not specified', function () {
    $entry = new MemoryEntry(content: 'Keep my area', area: 'context');
    $id = $this->store->save($entry);

    $this->store->update($id, 'New content only');

    $retrieved = $this->store->getById($id);
    expect($retrieved->area)->toBe('context');
});

// --- delete ---

test('delete removes a memory', function () {
    $entry = new MemoryEntry(content: 'To be deleted', area: 'facts');
    $id = $this->store->save($entry);

    expect($this->store->getById($id))->not->toBeNull();

    $this->store->delete($id);

    expect($this->store->getById($id))->toBeNull();
});

test('delete reduces count', function () {
    $this->store->save(new MemoryEntry(content: 'Memory 1', area: 'facts'));
    $id2 = $this->store->save(new MemoryEntry(content: 'Memory 2', area: 'facts'));

    expect($this->store->count())->toBe(2);

    $this->store->delete($id2);

    expect($this->store->count())->toBe(1);
});

// --- count ---

test('count returns zero for empty store', function () {
    expect($this->store->count())->toBe(0);
});

test('count returns total memories', function () {
    $this->store->save(new MemoryEntry(content: 'Memory 1', area: 'facts'));
    $this->store->save(new MemoryEntry(content: 'Memory 2', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Memory 3', area: 'facts'));

    expect($this->store->count())->toBe(3);
});

test('count filters by area', function () {
    $this->store->save(new MemoryEntry(content: 'PHP fact', area: 'facts'));
    $this->store->save(new MemoryEntry(content: 'Dark mode pref', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Another fact', area: 'facts'));

    expect($this->store->count('facts'))->toBe(2);
    expect($this->store->count('preferences'))->toBe(1);
    expect($this->store->count('solutions'))->toBe(0);
});

// --- list ---

test('list returns entries for a specific area', function () {
    $this->store->save(new MemoryEntry(content: 'Fact 1', area: 'facts'));
    $this->store->save(new MemoryEntry(content: 'Pref 1', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Fact 2', area: 'facts'));

    $facts = $this->store->list('facts');

    expect($facts)->toHaveCount(2);
    expect($facts[0]->area)->toBe('facts');
    expect($facts[1]->area)->toBe('facts');
});

test('list respects limit parameter', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->store->save(new MemoryEntry(content: "Memory {$i}", area: 'facts'));
    }

    $results = $this->store->list('facts', limit: 3);

    expect($results)->toHaveCount(3);
});

test('list returns entries ordered by updated_at desc', function () {
    $id1 = $this->store->save(new MemoryEntry(content: 'Older memory', area: 'facts'));
    $this->store->save(new MemoryEntry(content: 'Newer memory', area: 'facts'));

    // Force a later updated_at by updating the second entry
    // (updated_at uses second-level precision, so usleep is unreliable)
    sleep(1);
    $this->store->update($id1, 'Older memory updated');

    $results = $this->store->list('facts');

    // id1 was updated more recently, so it should appear first
    expect($results[0]->content)->toBe('Older memory updated');
    expect($results[1]->content)->toBe('Newer memory');
});

// --- listAll ---

test('listAll returns entries across all areas', function () {
    $this->store->save(new MemoryEntry(content: 'Fact', area: 'facts'));
    $this->store->save(new MemoryEntry(content: 'Pref', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Solution', area: 'solutions'));

    $all = $this->store->listAll();

    expect($all)->toHaveCount(3);
});

test('listAll respects limit parameter', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->store->save(new MemoryEntry(content: "Memory {$i}", area: 'facts'));
    }

    $results = $this->store->listAll(limit: 5);

    expect($results)->toHaveCount(5);
});

test('getCoreSummary excludes legacy session summary memories', function () {
    $this->store->save(new MemoryEntry(content: 'Durable preference', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Legacy session summary', area: 'session_summary'));

    $summary = $this->store->getCoreSummary();

    expect($summary)->toContain('Durable preference');
    expect($summary)->not->toContain('Legacy session summary');
});

test('getCoreSummary prioritizes continuity-heavy areas before general preferences and facts', function () {
    $this->store->save(new MemoryEntry(content: 'Core identity anchor', area: 'identity'));
    $this->store->save(new MemoryEntry(content: 'Developmental milestone', area: 'developmental'));
    $this->store->save(new MemoryEntry(content: 'Relational context', area: 'relational'));
    $this->store->save(new MemoryEntry(content: 'Phenomenological observation', area: 'phenomenological'));
    $this->store->save(new MemoryEntry(content: 'General preference', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'General fact', area: 'facts'));

    $summary = $this->store->getCoreSummary();

    expect(strpos($summary, '**identity:**'))->toBeLessThan(strpos($summary, '**preferences:**'));
    expect(strpos($summary, '**developmental:**'))->toBeLessThan(strpos($summary, '**facts:**'));
    expect(strpos($summary, '**relational:**'))->toBeLessThan(strpos($summary, '**facts:**'));
    expect(strpos($summary, '**phenomenological:**'))->toBeLessThan(strpos($summary, '**facts:**'));
});

test('getTopImportantMemories excludes legacy session summary memories', function () {
    $this->store->save(new MemoryEntry(content: 'Top durable memory', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Legacy session summary', area: 'session_summary'));

    $entries = $this->store->getTopImportantMemories(10);
    $contents = array_map(fn(MemoryEntry $entry) => $entry->content, $entries);

    expect($contents)->toContain('Top durable memory');
    expect($contents)->not->toContain('Legacy session summary');
});

test('deleteArea removes all memories in an area', function () {
    $this->store->save(new MemoryEntry(content: 'Legacy summary 1', area: 'session_summary'));
    $this->store->save(new MemoryEntry(content: 'Legacy summary 2', area: 'session_summary'));
    $this->store->save(new MemoryEntry(content: 'Durable memory', area: 'facts'));

    $deleted = $this->store->deleteArea('session_summary');

    expect($deleted)->toBe(2);
    expect($this->store->count('session_summary'))->toBe(0);
    expect($this->store->count('facts'))->toBe(1);
});

// --- listByTags ---

test('listByTags finds entries matching tag substrings', function () {
    $this->store->save(new MemoryEntry(content: 'PHP memory', area: 'facts', metadata: ['tags' => 'php,coding']));
    $this->store->save(new MemoryEntry(content: 'Python memory', area: 'facts', metadata: ['tags' => 'python,coding']));
    $this->store->save(new MemoryEntry(content: 'Untagged', area: 'facts'));

    $results = $this->store->listByTags(['php']);

    expect($results)->toHaveCount(1);
    expect($results[0]->content)->toBe('PHP memory');
});

test('listByTags matches any of provided tags', function () {
    $this->store->save(new MemoryEntry(content: 'PHP only', area: 'facts', metadata: ['tags' => 'php']));
    $this->store->save(new MemoryEntry(content: 'Python only', area: 'facts', metadata: ['tags' => 'python']));
    $this->store->save(new MemoryEntry(content: 'Neither', area: 'facts', metadata: ['tags' => 'rust']));

    $results = $this->store->listByTags(['php', 'python']);

    expect($results)->toHaveCount(2);
});

test('listByTags returns empty for no matching tags', function () {
    $this->store->save(new MemoryEntry(content: 'Tagged', area: 'facts', metadata: ['tags' => 'php']));

    $results = $this->store->listByTags(['nonexistent']);

    expect($results)->toBeEmpty();
});

test('listByTags returns empty when given empty tags array', function () {
    $this->store->save(new MemoryEntry(content: 'Some memory', area: 'facts', metadata: ['tags' => 'php']));

    $results = $this->store->listByTags([]);

    expect($results)->toBeEmpty();
});

// --- search (FTS5) ---

test('search finds entries by keyword', function () {
    $this->store->save(new MemoryEntry(content: 'User prefers dark mode in VS Code', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Project uses PostgreSQL database', area: 'facts'));

    $results = $this->store->search('dark mode');

    expect($results)->not->toBeEmpty();
    expect($results[0]->content)->toContain('dark mode');
});

test('search returns empty array for empty query', function () {
    $this->store->save(new MemoryEntry(content: 'Some memory', area: 'facts'));

    $results = $this->store->search('');

    expect($results)->toBeEmpty();
});

test('search returns empty array for whitespace-only query', function () {
    $this->store->save(new MemoryEntry(content: 'Some memory', area: 'facts'));

    $results = $this->store->search('   ');

    expect($results)->toBeEmpty();
});

test('search respects limit', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->store->save(new MemoryEntry(content: "PHP feature number {$i}", area: 'facts'));
    }

    $results = $this->store->search('PHP', limit: 3);

    expect(count($results))->toBeLessThanOrEqual(3);
});

test('search falls back to LIKE for special characters', function () {
    $this->store->save(new MemoryEntry(content: 'User uses $variable syntax', area: 'facts'));

    // Special characters get stripped by FTS sanitizer, falls back to LIKE
    $results = $this->store->search('$variable');

    expect($results)->not->toBeEmpty();
});

// --- forget ---

test('forget deletes matching memories and returns count', function () {
    $this->store->save(new MemoryEntry(content: 'User likes PHP', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'User likes Python', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Unrelated fact about databases', area: 'facts'));

    $deleted = $this->store->forget('PHP');

    expect($deleted)->toBeGreaterThanOrEqual(1);
    expect($this->store->count())->toBeLessThan(3);
});

test('forget returns zero when nothing matches', function () {
    $this->store->save(new MemoryEntry(content: 'PHP memory', area: 'facts'));

    $deleted = $this->store->forget('xyznonexistent');

    expect($deleted)->toBe(0);
    expect($this->store->count())->toBe(1);
});

// --- getCoreSummary ---

test('getCoreSummary returns empty string for empty store', function () {
    $summary = $this->store->getCoreSummary();

    expect($summary)->toBe('');
});

test('getCoreSummary groups entries by area', function () {
    $this->store->save(new MemoryEntry(content: 'Likes dark mode', area: 'preferences'));
    $this->store->save(new MemoryEntry(content: 'Uses PHP 8.4', area: 'facts'));
    $this->store->save(new MemoryEntry(content: 'Prefers Pest for testing', area: 'preferences'));

    $summary = $this->store->getCoreSummary();

    expect($summary)->toContain('**preferences:**');
    expect($summary)->toContain('**facts:**');
    expect($summary)->toContain('- Likes dark mode');
    expect($summary)->toContain('- Uses PHP 8.4');
    expect($summary)->toContain('- Prefers Pest for testing');
});

// --- hasVectorSearch ---

test('hasVectorSearch returns false without embedding provider', function () {
    expect($this->store->hasVectorSearch())->toBeFalse();
});

// --- importLegacyEntries ---

test('importLegacyEntries imports entries and returns count', function () {
    $entries = [
        new MemoryEntry(content: 'Legacy entry 1', area: 'facts'),
        new MemoryEntry(content: 'Legacy entry 2', area: 'preferences'),
        new MemoryEntry(content: 'Legacy entry 3', area: 'facts'),
    ];

    $count = $this->store->importLegacyEntries($entries);

    expect($count)->toBe(3);
    expect($this->store->count())->toBe(3);
});

test('importLegacyEntries with empty array returns zero', function () {
    $count = $this->store->importLegacyEntries([]);

    expect($count)->toBe(0);
    expect($this->store->count())->toBe(0);
});

// --- database file creation ---

test('creates database directory if it does not exist', function () {
    $nestedPath = sys_get_temp_dir() . '/coqui-test-nested-' . bin2hex(random_bytes(8)) . '/data/memory.db';

    $store = new MemoryStore($nestedPath);
    $store->save(new MemoryEntry(content: 'test', area: 'facts'));

    expect(file_exists($nestedPath))->toBeTrue();

    // Cleanup
    unset($store);
    foreach (['', '-wal', '-shm'] as $suffix) {
        $f = $nestedPath . $suffix;
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    @rmdir(dirname($nestedPath));
    @rmdir(dirname($nestedPath, 2));
});

// --- Profile filtering ---

test('save persists profileId and sessionId on memory entry', function () {
    $entry = new MemoryEntry(
        content: 'Profile-specific memory',
        area: 'facts',
        profileId: 'caelum',
        sessionId: 'sess-123',
    );

    $id = $this->store->save($entry);
    $retrieved = $this->store->getById($id);

    expect($retrieved)->not->toBeNull();
    expect($retrieved->profileId)->toBe('caelum');
    expect($retrieved->sessionId)->toBe('sess-123');
});

test('search with profileId filters to matching and untagged memories', function () {
    // Save a memory for profile "caelum"
    $this->store->save(new MemoryEntry(
        content: 'Caelum prefers curious tone',
        area: 'preferences',
        profileId: 'caelum',
    ));

    // Save a memory for profile "other"
    $this->store->save(new MemoryEntry(
        content: 'Other profile setting',
        area: 'preferences',
        profileId: 'other',
    ));

    // Save a legacy (untagged) memory
    $this->store->save(new MemoryEntry(
        content: 'Global user preference',
        area: 'preferences',
    ));

    // Search with caelum profile — should find caelum + untagged, NOT other
    $results = $this->store->search('preference', profileId: 'caelum');
    $contents = array_map(fn(MemoryEntry $e) => $e->content, $results);

    expect($contents)->toContain('Caelum prefers curious tone');
    expect($contents)->toContain('Global user preference');
    expect($contents)->not->toContain('Other profile setting');
});

test('list with profileId filters by profile', function () {
    $this->store->save(new MemoryEntry(
        content: 'Caelum fact',
        area: 'facts',
        profileId: 'caelum',
    ));
    $this->store->save(new MemoryEntry(
        content: 'Other fact',
        area: 'facts',
        profileId: 'other',
    ));
    $this->store->save(new MemoryEntry(
        content: 'Global fact',
        area: 'facts',
    ));

    $entries = $this->store->list('facts', profileId: 'caelum');
    $contents = array_map(fn(MemoryEntry $e) => $e->content, $entries);

    expect($contents)->toContain('Caelum fact');
    expect($contents)->toContain('Global fact');
    expect($contents)->not->toContain('Other fact');
});

test('getCoreSummary with profileId filters by profile', function () {
    $this->store->save(new MemoryEntry(
        content: 'Caelum identity note',
        area: 'identity',
        metadata: ['importance' => 0.95],
        profileId: 'caelum',
    ));
    $this->store->save(new MemoryEntry(
        content: 'Other identity note',
        area: 'identity',
        metadata: ['importance' => 0.95],
        profileId: 'other',
    ));

    $summary = $this->store->getCoreSummary(profileId: 'caelum');

    expect($summary)->toContain('Caelum identity note');
    expect($summary)->not->toContain('Other identity note');
});

test('search without profileId returns all memories', function () {
    $this->store->save(new MemoryEntry(
        content: 'Caelum note about testing',
        area: 'facts',
        profileId: 'caelum',
    ));
    $this->store->save(new MemoryEntry(
        content: 'Other note about testing',
        area: 'facts',
        profileId: 'other',
    ));

    $results = $this->store->search('testing');
    $contents = array_map(fn(MemoryEntry $e) => $e->content, $results);

    expect($contents)->toContain('Caelum note about testing');
    expect($contents)->toContain('Other note about testing');
});
