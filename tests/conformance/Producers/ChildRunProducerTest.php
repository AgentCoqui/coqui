<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-childrun-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-28: produces a schema-valid completed ChildRun carrying a token triad', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');

    // A synchronous child run that completed with a rooted model + split usage.
    $this->storage->logChildRun(
        parentSessionId: $sessionId,
        role: 'coder',
        model: 'anthropic/claude-sonnet-4',
        prompt: 'Write a failing test first.',
        status: 'completed',
        result: 'Done.',
        promptTokens: 120,
        completionTokens: 45,
        totalTokens: 165,
    );

    $runs = $this->storage->getChildRuns($sessionId);
    expect($runs)->toHaveCount(1);

    $wire = SessionHandler::childRunToWire($runs[0]);

    $v = new ConformanceValidator();
    expect($v->isValid('child-run.json', $wire))->toBeTrue($v->errorText('child-run.json', $wire));
    expect($wire['parent_session_id'])->toBe($sessionId);
    expect($wire['status'])->toBeIn(['pending', 'running', 'completed', 'failed', 'cancelled']);
    expect($wire['status'])->toBe('completed');
    expect($wire['role'])->toBe('coder');
    expect($wire['model'])->toBe('anthropic/claude-sonnet-4');
    expect($wire['prompt_tokens'])->toBe(120);
    expect($wire['completion_tokens'])->toBe(45);
    expect($wire['total_tokens'])->toBe(165);
    // parent_turn_id is oneOf[Id,null]: present-and-null is schema-valid.
    expect(array_key_exists('parent_turn_id', $wire))->toBeTrue();
    expect($wire['parent_turn_id'])->toBeNull();
    expect($wire['completed_at'])->not->toBeNull();
})->group('conformance');

it('CORE-28: a child run with a null model emits model:null (inherit), never omitted', function () {
    $sessionId = $this->storage->createSession('orchestrator', null, 'caelum');

    // model NULLABLE — unlike turn.json's non-null model, child-run.json is
    // oneOf[ModelId,null], so null must be emitted (⇒ inherit), never coerced.
    $this->storage->logChildRun(
        parentSessionId: $sessionId,
        role: 'analyst',
        model: null,
        prompt: 'Review the change.',
        status: 'failed',
    );

    $runs = $this->storage->getChildRuns($sessionId);
    $wire = SessionHandler::childRunToWire($runs[0]);

    $v = new ConformanceValidator();
    expect($v->isValid('child-run.json', $wire))->toBeTrue($v->errorText('child-run.json', $wire));
    expect(array_key_exists('model', $wire))->toBeTrue();
    expect($wire['model'])->toBeNull();
    expect($wire['status'])->toBe('failed');
    // result was not provided ⇒ nullable result is null.
    expect($wire['result'])->toBeNull();
})->group('conformance');
