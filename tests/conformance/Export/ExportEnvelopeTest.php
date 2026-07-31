<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;
use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Contract\RoleProperties;
use CoquiBot\Coqui\Contract\TerminationCondition;
use CoquiBot\Coqui\Contract\TerminationType;
use CoquiBot\Coqui\Export\AuditRecordProducer;
use CoquiBot\Coqui\Export\ExportCollectionMap;
use CoquiBot\Coqui\Export\JobEventProducer;
use CoquiBot\Coqui\Export\JobProducer;
use CoquiBot\Coqui\Export\LoopDefinitionProducer;
use CoquiBot\Coqui\Export\LoopIterationProducer;
use CoquiBot\Coqui\Export\LoopStageProducer;
use CoquiBot\Coqui\Export\MessageProducer;
use CoquiBot\Coqui\Export\RoleProducer;
use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

/**
 * CORE-14: the export envelope types every Core + internal collection. Phase 2
 * proves per-collection typing/producibility and the envelope structure; the
 * preserve+remap roundtrip IMPORT (FK rewrite) is a Phase 6 gate and is NOT built
 * here. `memories` is typed in the map but its DB-backed producer is deferred to
 * the Memory Core reshape phase (coqui `memories` still carries area/tags/importance
 * columns, not the CAP name/description/type shape).
 */

it('CORE-14: the typed export collection map covers exactly export.json\'s collections', function () {
    $schemaPath = __DIR__ . '/../spec/schema/export.json';
    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);

    // Every property under export.json except the two envelope scalars is a collection.
    $envelopeCollections = array_values(array_diff(
        array_keys($schema['properties']),
        ['protocol_version', 'exported_at'],
    ));

    $mapped = ExportCollectionMap::names();

    sort($envelopeCollections);
    sort($mapped);

    // The typing map neither omits nor invents a collection relative to the envelope.
    expect($mapped)->toBe($envelopeCollections);
    // The internal (diagnostics-only) collections are all typed.
    expect($mapped)->toContain('jobs', 'job_events', 'audit_records');
})->group('conformance');

it('CORE-14: every produced collection serializes schema-valid and the envelope validates', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-export-' . bin2hex(random_bytes(8)) . '.db';
    $workspace = sys_get_temp_dir() . '/coqui-export-ws-' . bin2hex(random_bytes(6));
    mkdir($workspace, 0775, true);
    $storage = new SessionStorage($dbPath);
    $v = new ConformanceValidator();

    try {
        // ── personas ────────────────────────────────────────────────────────
        $personaWire = PersonaSnapshotStore::toWire([
            'id' => '01J000000000000000000PERSONA',
            'name' => 'Caelum',
            'avatar' => json_encode(['tint' => '#2b3a52']),
            'model' => 'anthropic/claude-sonnet-4',
            'allowed_roles' => json_encode(['orchestrator']),
            'soul' => 'You are Caelum, a warm, precise research companion.',
            'backstory' => null,
            'context' => null,
            'preferences' => null,
            'version' => 1,
            'created_at' => '2026-07-28T00:00:00Z',
            'updated_at' => '2026-07-28T00:00:00Z',
        ]);

        // ── sessions / session_members / turns / messages ───────────────────
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $sessionWire = SessionHandler::toWire($storage->getSession($sessionId));
        $memberWire = ExportCollectionMap::sessionMemberToWire($sessionId, 'caelum');

        $turnId = $storage->createTurn($sessionId, 'Summarize the suite.', 'anthropic/claude-sonnet-4', null, 'caelum');
        $turnWire = TurnHandler::toWire($storage->getTurn($turnId));

        $messageId = $storage->addMessage($sessionId, 'assistant', 'The suite validates Core objects.', null, null, $turnId, 'Caelum', 'orchestrator');
        $messageRows = $storage->getMessages($sessionId);
        $messageRow = array_values(array_filter($messageRows, static fn(array $r): bool => $r['id'] === $messageId))[0]
            + ['session_id' => $sessionId, 'turn_id' => $turnId];
        $messageWire = MessageProducer::toWire($messageRow);

        // ── content ─────────────────────────────────────────────────────────
        $content = new ContentStore($storage->pdo());
        $contentWire = $content->store("hello, content\n", 'text/markdown');

        // ── roles ───────────────────────────────────────────────────────────
        $roleWire = RoleProducer::toWire(new RoleProperties(
            name: 'coder',
            displayName: 'Coder',
            description: 'Implements changes.',
            path: '/config/roles/coder.md',
            version: 1,
            accessLevel: 'full',
            toolkits: 'shell,filesystem',
            maxIterations: 25,
        ));

        // ── loop_definitions ────────────────────────────────────────────────
        $loopDefWire = LoopDefinitionProducer::toWire(new LoopDefinition(
            name: 'code-review-loop',
            description: 'Draft, review, rework.',
            roles: [
                new LoopRoleDefinition(role: 'coder', prompt: 'Implement.'),
                new LoopRoleDefinition(role: 'reviewer', prompt: 'Review.', gate: true),
            ],
            terminationCondition: new TerminationCondition(TerminationType::IterationBound, maxIterations: 5),
        ));

        // ── loops / loop_iterations / loop_stages ───────────────────────────
        $loopStore = new LoopStore($storage->getPdo());
        $loopId = $loopStore->createLoop(
            definitionName: 'code-review-loop',
            goal: 'Converge on a clean review.',
            configuration: ['name' => 'code-review-loop'],
            sessionId: $sessionId,
            personaId: '01J000000000000000000PERSONA',
            maxIterations: 5,
        );
        $loopWire = LoopStore::toWire($loopStore->getLoop($loopId));

        $iterationId = $loopStore->createIteration($loopId, 1);
        $iterationWire = LoopIterationProducer::toWire($loopStore->getIteration($iterationId));

        $stageId = $loopStore->createStage($iterationId, 0, 'coder');
        $stageWire = LoopStageProducer::toWire($loopStore->getStage($stageId));

        // ── jobs / job_events ───────────────────────────────────────────────
        $jobId = $storage->createTask($sessionId, 'Run the export.', 'orchestrator', title: 'Export');
        $jobWire = JobProducer::toWire($storage->getTask($jobId));

        $storage->appendTaskEvent($jobId, 'iteration_started', ['iteration' => 1]);
        $eventRow = $storage->getTaskEvents($jobId)[0] + ['job_id' => $jobId];
        $jobEventWire = JobEventProducer::toWire($eventRow);

        // ── audit_records ───────────────────────────────────────────────────
        $auditId = $storage->logAudit($sessionId, 'shell_exec', ['command' => 'ls'], 'approved');
        $auditRow = array_values(array_filter($storage->getAuditLog($sessionId), static fn(array $r): bool => $r['id'] === $auditId))[0];
        $auditWire = AuditRecordProducer::toWire($auditRow);

        // ── child_runs ──────────────────────────────────────────────────────
        $storage->logChildRun(
            parentSessionId: $sessionId,
            role: 'coder',
            model: 'anthropic/claude-sonnet-4',
            prompt: 'Implement the fix.',
            status: 'completed',
            result: 'Fixed.',
            promptTokens: 80,
            completionTokens: 20,
            totalTokens: 100,
        );
        $childRunWire = SessionHandler::childRunToWire($storage->getChildRuns($sessionId)[0]);

        // ── skills ──────────────────────────────────────────────────────────
        $skills = new SkillLifecycleStore($storage->pdo());
        $skillWire = $skills->upsertSkill(
            name: 'summarize',
            description: 'Condense documents.',
            status: 'available',
            origin: ['kind' => 'builtin'],
            execution: ['kind' => 'instruction', 'requires' => []],
        );

        // ── artifacts ───────────────────────────────────────────────────────
        $artifacts = new ArtifactStore($storage->getPdo(), new ArtifactFileService($workspace));
        $artifactId = $artifacts->create($sessionId, 'Design Doc', "# Title\nbody\n", 'document', createdBy: 'coder');
        $artifactWire = ArtifactStore::toWire($artifacts->get($artifactId, $sessionId));

        // ── questions ───────────────────────────────────────────────────────
        $request = new QuestionRequest(
            id: '01J000000000000000000QEXPORT',
            prompt: 'Proceed?',
            format: QuestionFormat::SingleSelect,
            options: [new QuestionOption('yes', 'Yes'), new QuestionOption('no', 'No')],
            allowOther: false,
            suggested: new QuestionResponse(selected: ['yes']),
        );
        $storage->createQuestion($sessionId, $request, 'interactive');
        $storage->recordQuestionAnswer($request->id, new QuestionResponse(selected: ['yes']));
        $questionWire = QuestionPersistence::toWire($storage->getQuestion($request->id));

        // ── scheduled_tasks ─────────────────────────────────────────────────
        $scheduleStore = new ScheduleStore($storage->getPdo());
        $scheduleId = $scheduleStore->create(
            name: 'daily-review',
            scheduleExpression: '0 9 * * 1-5',
            prompt: 'Review recent changes.',
            personaId: 'caelum',
        );
        $scheduleWire = ScheduleStore::toWire($scheduleStore->get($scheduleId));

        // ── assemble the envelope ───────────────────────────────────────────
        // memories is intentionally omitted: its DB-backed producer is deferred to
        // the Memory Core reshape phase (typed in the map, not produced here).
        $collections = [
            'personas' => [$personaWire],
            'sessions' => [$sessionWire],
            'session_members' => [$memberWire],
            'turns' => [$turnWire],
            'messages' => [$messageWire],
            'content' => [$contentWire],
            'roles' => [$roleWire],
            'loop_definitions' => [$loopDefWire],
            'loops' => [$loopWire],
            'loop_iterations' => [$iterationWire],
            'loop_stages' => [$stageWire],
            'jobs' => [$jobWire],
            'job_events' => [$jobEventWire],
            'audit_records' => [$auditWire],
            'child_runs' => [$childRunWire],
            'skills' => [$skillWire],
            'artifacts' => [$artifactWire],
            'questions' => [$questionWire],
            'scheduled_tasks' => [$scheduleWire],
        ];

        // Per-collection producer assertion: each item validates against its schema.
        $schemas = ExportCollectionMap::schemas();
        foreach ($collections as $name => $items) {
            // session_members has no standalone schema — the envelope inline-types it.
            if (!isset($schemas[$name])) {
                continue;
            }
            foreach ($items as $item) {
                expect($v->isValid($schemas[$name], $item))
                    ->toBeTrue(sprintf('%s → %s: %s', $name, $schemas[$name], $v->errorText($schemas[$name], $item)));
            }
        }

        // Envelope-structure assertion: the whole export validates against export.json.
        $envelope = [
            'protocol_version' => '0.5.0',
            'exported_at' => '2026-07-28T00:00:03Z',
        ] + $collections;

        expect($v->isValid('export.json', $envelope))->toBeTrue($v->errorText('export.json', $envelope));

        // 19 of the 20 typed collections are produced here; memories is the sole
        // documented Phase-6/Memory-reshape deferral.
        expect(count($collections))->toBe(count(ExportCollectionMap::names()) - 1);
        expect($collections)->not->toHaveKey('memories');
    } finally {
        cleanupSqliteTestDb($dbPath);
        exec('rm -rf ' . escapeshellarg($workspace));
    }
})->group('conformance');
