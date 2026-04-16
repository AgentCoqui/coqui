<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Memory\MemoryEntry;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-memory-toolkit-' . bin2hex(random_bytes(8)) . '.db';
    $this->store = new MemoryStore($this->dbPath);
    $this->profileToolkit = new MemoryToolkit($this->store, activeProfileId: 'trinity');
    $this->orchestratorToolkit = new MemoryToolkit($this->store, activeProfileId: 'trinity', allowCrossProfileMutation: true);
});

afterEach(function () {
    cleanupSqliteTestDb($this->dbPath);
});

test('memory_delete blocks deleting another profiles memory', function () {
    $id = $this->store->save(new MemoryEntry(content: 'Nagog memory', area: 'facts', profileId: 'nagog'));

    $tool = toolFromToolkit($this->profileToolkit, 'memory_delete');
    $result = $tool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('belongs to profile "nagog"');
    expect($this->store->getById($id))->not->toBeNull();
});

test('memory_update blocks updating another profiles memory', function () {
    $id = $this->store->save(new MemoryEntry(content: 'Nagog memory', area: 'facts', profileId: 'nagog'));

    $tool = toolFromToolkit($this->profileToolkit, 'memory_update');
    $result = $tool->execute(['id' => $id, 'content' => 'Updated by trinity']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($this->store->getById($id)?->content)->toBe('Nagog memory');
});

test('memory_restore blocks restoring another profiles archived memory', function () {
    $id = $this->store->save(new MemoryEntry(content: 'Nagog archived', area: 'facts', profileId: 'nagog'));
    $this->store->getPdo()->prepare('UPDATE memories SET archived_at = :archived_at WHERE id = :id')->execute([
        ':archived_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s'),
        ':id' => $id,
    ]);

    $tool = toolFromToolkit($this->profileToolkit, 'memory_restore');
    $result = $tool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('memory_forget only removes active profile and shared memories', function () {
    $this->store->save(new MemoryEntry(content: 'Trinity continuity note', area: 'facts', profileId: 'trinity'));
    $this->store->save(new MemoryEntry(content: 'Nagog continuity note', area: 'facts', profileId: 'nagog'));
    $this->store->save(new MemoryEntry(content: 'Shared continuity note', area: 'facts'));

    $tool = toolFromToolkit($this->profileToolkit, 'memory_forget');
    $result = $tool->execute(['query' => 'continuity']);
    $remaining = $this->store->search('continuity');
    $contents = array_map(fn(MemoryEntry $e) => $e->content, $remaining);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Forgot 2 memories');
    expect($contents)->toContain('Nagog continuity note');
    expect($contents)->not->toContain('Trinity continuity note');
    expect($contents)->not->toContain('Shared continuity note');
});

test('orchestrator can delete another profiles memory by id', function () {
    $id = $this->store->save(new MemoryEntry(content: 'Nagog memory', area: 'facts', profileId: 'nagog'));

    $tool = toolFromToolkit($this->orchestratorToolkit, 'memory_delete');
    $result = $tool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->store->getById($id))->toBeNull();
});

test('memory_inspect_profile is only exposed to orchestrator-capable toolkits', function () {
    $profileToolNames = array_map(fn($tool) => $tool->name(), $this->profileToolkit->tools());
    $orchestratorToolNames = array_map(fn($tool) => $tool->name(), $this->orchestratorToolkit->tools());

    expect($profileToolNames)->not->toContain('memory_inspect_profile');
    expect($orchestratorToolNames)->toContain('memory_inspect_profile');
});

test('memory_inspect_profile returns only target profile memories', function () {
    $this->store->save(new MemoryEntry(content: 'Nagog continuity note', area: 'facts', profileId: 'nagog'));
    $this->store->save(new MemoryEntry(content: 'Trinity continuity note', area: 'facts', profileId: 'trinity'));
    $this->store->save(new MemoryEntry(content: 'Shared continuity note', area: 'facts'));

    $tool = toolFromToolkit($this->orchestratorToolkit, 'memory_inspect_profile');
    $result = $tool->execute(['profile' => 'nagog', 'query' => 'continuity']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Nagog continuity note');
    expect($result->content)->not->toContain('Trinity continuity note');
    expect($result->content)->not->toContain('Shared continuity note');
});