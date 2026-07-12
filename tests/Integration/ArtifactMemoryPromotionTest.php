<?php

declare(strict_types=1);

use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;

/**
 * Memory-on-promotion end-to-end: an agent that produces a canonical artifact
 * records a durable memory pointer naming its path, and supersedes a prior
 * pointer for the same subject via memory_forget. Exercises the real tools the
 * `prompts/tools/artifacts.md` guidance names — no new schema, no new flag.
 */
beforeEach(function (): void {
    $this->dbPath = sys_get_temp_dir() . '/coqui-promo-' . bin2hex(random_bytes(8)) . '.db';
    $this->memPath = sys_get_temp_dir() . '/coqui-promo-mem-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->artifacts = new ArtifactToolkit(
        artifactStoreForTest($this->storage->getPdo()),
        $this->sessionId,
        createdBy: 'caelum',
    );
    $this->memory = new MemoryToolkit(new MemoryStore($this->memPath), null, 'caelum');
});

afterEach(function (): void {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    cleanupSqliteTestDb($this->memPath);
});

test('a canonical artifact can be promoted to a memory pointer that names its path', function (): void {
    $created = assertStructuredToolResult(
        toolFromToolkit($this->artifacts, 'artifact_create')->execute([
            'title' => 'Pricing model',
            'content' => "# Pricing\n\nCanonical pricing model.",
            'type' => 'plan',
        ]),
    );

    $path = $created['path'];
    expect($path)->toBeString()->not->toBe('');

    // Gate met (canonical + user asked to keep) → save one high-signal pointer.
    $save = toolFromToolkit($this->memory, 'memory_save')->execute([
        'content' => "{$path} is the canonical pricing model.",
        'area' => 'context',
    ]);
    expect($save->status->value)->toBe('success');

    // The pointer is recallable and carries the artifact path, not its body.
    $search = toolFromToolkit($this->memory, 'memory_search')->execute(['query' => 'canonical pricing model']);
    expect($search->content)->toContain($path);
    expect($search->content)->not->toContain('Canonical pricing model.'); // body stays in the file
});

test('memory_forget supersedes a prior pointer before a new canonical pointer is saved', function (): void {
    // An earlier draft pointer exists for the subject.
    toolFromToolkit($this->memory, 'memory_save')->execute([
        'content' => 'artifacts/plan/pricing-draft.md is the pricing model.',
        'area' => 'context',
    ]);

    // A new canonical version supersedes it: forget the subject, then save the new pointer.
    $forget = toolFromToolkit($this->memory, 'memory_forget')->execute(['query' => 'pricing model']);
    expect($forget->status->value)->toBe('success');

    toolFromToolkit($this->memory, 'memory_save')->execute([
        'content' => 'artifacts/plan/pricing-final.md is the canonical pricing model (supersedes the earlier draft).',
        'area' => 'context',
    ]);

    $search = toolFromToolkit($this->memory, 'memory_search')->execute(['query' => 'pricing model']);
    expect($search->content)->toContain('pricing-final.md');
    expect($search->content)->not->toContain('pricing-draft.md');
});
