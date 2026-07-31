<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Agent\SessionWorkspaceResolver;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;
use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Contract\TerminationCondition;
use CoquiBot\Coqui\Contract\TerminationType;
use CoquiBot\Coqui\Export\AuditRecordProducer;
use CoquiBot\Coqui\Export\ExportCollectionMap;
use CoquiBot\Coqui\Export\JobEventProducer;
use CoquiBot\Coqui\Export\JobProducer;
use CoquiBot\Coqui\Export\LoopDefinitionProducer;
use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

// CAP 0.5.0 Core conformance scoreboard (conformance/checklist.md, CORE-1..CORE-59).
// Each row starts as a todo and is replaced by a real assertion in the phase that
// implements it. Todos do not fail the suite; they surface remaining work.

it('CORE-1: persona allowed_roles includes orchestrator', function () {
    $wire = PersonaSnapshotStore::toWire([
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
    $v = new ConformanceValidator();
    expect($v->isValid('persona.json', $wire))->toBeTrue($v->errorText('persona.json', $wire));
    expect($wire['allowed_roles'])->toContain('orchestrator');
})->group('conformance');

it('CORE-15: session.model is nullable; a stored null passes through as null (inherit)', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core15-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $id = $storage->createSession('orchestrator', null, 'caelum');
        $wire = SessionHandler::toWire($storage->getSession($id));

        $v = new ConformanceValidator();
        expect($v->isValid('session.json', $wire))->toBeTrue($v->errorText('session.json', $wire));
        expect(array_key_exists('model', $wire))->toBeTrue();
        expect($wire['model'])->toBeNull();

        // Precedence (D2): the effective-model rule honours session.model. A stored
        // null inherits the role chain; a non-null session model wins outright.
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
                ],
            ],
        ]);
        $resolver = new RoleResolver($config);

        // Null session model (as stored above) inherits the role chain.
        expect($resolver->resolveForSession($wire['model'], 'orchestrator', null))
            ->toBe($resolver->resolve('orchestrator', null));
        // A non-null session model overrides the role/persona chain.
        expect($resolver->resolveForSession('anthropic/claude-opus-4', 'orchestrator', null))
            ->toBe('anthropic/claude-opus-4');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-19: session carries an opaque workspace echoed verbatim; null = no rooted workspace', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core19-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $rooted = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $storage->pdo()
            ->prepare('UPDATE sessions SET workspace = :ws WHERE id = :id')
            ->execute(['ws' => '/srv/agents/ws-42', 'id' => $rooted]);
        $rootedWire = SessionHandler::toWire($storage->getSession($rooted));

        $unrooted = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $unrootedWire = SessionHandler::toWire($storage->getSession($unrooted));

        $v = new ConformanceValidator();
        expect($v->isValid('session.json', $rootedWire))->toBeTrue($v->errorText('session.json', $rootedWire));
        expect($rootedWire['workspace'])->toBe('/srv/agents/ws-42');
        expect($v->isValid('session.json', $unrootedWire))->toBeTrue($v->errorText('session.json', $unrootedWire));
        expect($unrootedWire['workspace'])->toBeNull();

        // Inheritance (D3): the runtime seam re-roots per session. A rooted session
        // resolves to its workspace; an unrooted session and a null/unknown session
        // id all fall back to the supplied global default.
        $resolver = new SessionWorkspaceResolver($storage, '/global/default/ws');
        expect($resolver->resolve($rooted))->toBe('/srv/agents/ws-42');
        expect($resolver->resolve($unrooted))->toBe('/global/default/ws');
        expect($resolver->resolve(null))->toBe('/global/default/ws');
        expect($resolver->resolve('does-not-exist'))->toBe('/global/default/ws');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-21: loop stages thread prior-stage output and inherit the session workspace', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core21-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $pdo = $storage->getPdo();
        $loopStore = new LoopStore($pdo);
        $projectStore = new ProjectStore($pdo);
        $artifactStore = artifactStoreForTest($pdo);
        $executor = new LoopExecutor(
            loopStore: $loopStore,
            projectStore: $projectStore,
        );
        $manager = new LoopManager(
            storage: $storage,
            loopStore: $loopStore,
            executor: $executor,
            artifactStore: $artifactStore,
        );

        // A work-scope session rooted at an opaque workspace.
        $workScopeSessionId = $storage->createSession(
            'orchestrator',
            'anthropic/claude-sonnet-4',
            workspace: '/srv/agents/ws-loop-21',
        );

        $definition = [
            'name' => 'two-stage',
            'description' => 'Two stage loop',
            'roles' => [
                ['role' => 'coder', 'prompt' => 'Implement the requested change.'],
                ['role' => 'reviewer', 'prompt' => 'Review the implementation.'],
            ],
            'termination_condition' => [
                'type' => 'iteration_bound',
                'value' => 1,
            ],
        ];

        $loopId = $executor->startLoop($definition, 'Ship the feature', $workScopeSessionId);

        // (a) Workspace inheritance: stage 1's fresh execution session is rooted at
        // the work-scope session's workspace (D3).
        $manager->tick();
        $stageOne = $loopStore->getCurrentState($loopId)['stages'][0];
        $stageOneTask = $storage->getTask((string) $stageOne['task_id']);
        $stageOneSession = $storage->getSession((string) $stageOneTask['session_id']);
        expect($stageOneSession['workspace'])->toBe('/srv/agents/ws-loop-21');

        // Complete stage 1: reconcile threads its output into a loop_output artifact
        // scoped to the work-scope session.
        $storage->addMessage((string) $stageOneTask['session_id'], 'assistant', 'Stage one implementation output');
        $storage->updateTaskStatus((string) $stageOne['task_id'], 'completed', ['result' => 'Stage one implementation output']);
        $manager->reconcile();

        expect($artifactStore->list($workScopeSessionId, 'loop_output'))->toHaveCount(1);

        // (b) Prior-output threading: stage 2's dispatch prompt carries the prior
        // stage's output under the "Previous Stages This Cycle" context section.
        $manager->tick();
        $stageTwo = $loopStore->getCurrentState($loopId)['stages'][1];
        $stageTwoTask = $storage->getTask((string) $stageTwo['task_id']);
        expect($stageTwoTask['prompt'])->toContain('Previous Stages This Cycle');
        expect($stageTwoTask['prompt'])->toContain('Stage one implementation output');

        // Stage 2's session inherits the same rooted workspace.
        $stageTwoSession = $storage->getSession((string) $stageTwoTask['session_id']);
        expect($stageTwoSession['workspace'])->toBe('/srv/agents/ws-loop-21');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-23: a stage whose role/definition is undefined at dispatch resolves blocked + Critical', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core23-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $pdo = $storage->getPdo();
        $loopStore = new LoopStore($pdo);
        $projectStore = new ProjectStore($pdo);
        $executor = new LoopExecutor(
            loopStore: $loopStore,
            projectStore: $projectStore,
        );

        $definition = [
            'name' => 'two-stage',
            'description' => 'Two stage loop',
            'roles' => [
                ['role' => 'coder', 'prompt' => 'Implement the requested change.'],
                ['role' => 'reviewer', 'prompt' => 'Review the implementation.'],
            ],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => 3],
        ];

        $loopId = $executor->startLoop($definition, 'Ship the feature', maxIterationsOverride: 3);
        $stages = $loopStore->getCurrentState($loopId)['stages'];

        // Stage 0 completes; the next pending stage is index 1.
        $loopStore->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: 'stage 0 done');

        // The stored configuration loses the role at index 1, so pending stage 1 has
        // no role/definition to dispatch — a hard configuration failure at dispatch.
        $oneRoleConfig = $definition;
        $oneRoleConfig['roles'] = [$oneRoleConfig['roles'][0]];
        $stmt = $pdo->prepare('UPDATE loops SET configuration = :config WHERE id = :id');
        $stmt->execute([':config' => json_encode($oneRoleConfig), ':id' => $loopId]);

        // Dispatch resolves `blocked` with a Critical finding instead of a silent null stall.
        $result = $executor->prepareNextStage($loopId);
        expect($result)->toBeNull();

        $loop = $loopStore->getLoop($loopId);
        expect($loop['status'])->toBe('blocked');

        $meta = json_decode($loop['metadata'], true);
        expect(array_column($meta['escalation']['findings'], 'severity'))->toContain('critical');

        // The current iteration is left retryable for the operator.
        expect($loopStore->getCurrentState($loopId)['iteration']['status'])->toBe('needs_rework');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-20: loop definitions carry no on_question; the invalid vector is rejected', function () {
    // (a) The schema rejects a loop definition that carries an on_question field
    // (additionalProperties:false) — the legacy blocking policy is gone.
    $vector = json_decode(
        (string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/loopdef.on-question.json'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );
    $v = new ConformanceValidator();
    expect($v->isValid('loop-definition.json', $vector))->toBeFalse();

    // (a')  Isolate on_question as the SOLE rejection reason. The vendored invalid
    // vector fails for two independent reasons: it omits the schema-required
    // `version` and it carries the forbidden `on_question` (additionalProperties:false).
    // Patch the missing required field into an in-memory copy so `on_question` is the
    // only remaining violation — the vector must still be rejected. This gives part (a)
    // teeth on the exact behaviour this task proves: re-allowing on_question would fail.
    $patched = json_decode(
        (string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/loopdef.on-question.json'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );
    $patched->version = 1; // the only required field the invalid vector omits
    expect($v->isValid('loop-definition.json', $patched))->toBeFalse();

    // Sanity: with on_question removed the patched copy is VALID, proving on_question
    // was the sole remaining violation above.
    unset($patched->on_question);
    expect($v->isValid('loop-definition.json', $patched))->toBeTrue($v->errorText('loop-definition.json', $patched));

    // (b) The runtime never emits on_question: a built + serialized LoopDefinition
    // has no such key, even if stored input still carried one.
    $definition = new LoopDefinition(
        name: 'two-stage',
        description: 'Two stage loop',
        roles: [
            new LoopRoleDefinition('coder', 'Implement the requested change.'),
            new LoopRoleDefinition('reviewer', 'Review the implementation.'),
        ],
        terminationCondition: TerminationCondition::fromArray(['type' => 'iteration_bound', 'value' => 1]),
    );
    expect($definition->toArray())->not->toHaveKey('on_question');
})->group('conformance');

it('CORE-34: turn carries actor_persona_id and a closed-set status', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core34-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        // A turn produced by a named member persona.
        $turnId = $storage->createTurn($sessionId, 'Draft the release notes.', 'anthropic/claude-sonnet-4', null, 'caelum');
        $wire = TurnHandler::toWire($storage->getTurn($turnId));

        $v = new ConformanceValidator();
        expect($v->isValid('turn.json', $wire))->toBeTrue($v->errorText('turn.json', $wire));
        expect(array_key_exists('actor_persona_id', $wire))->toBeTrue();
        expect($wire['actor_persona_id'])->toBe('caelum');   // carried, non-null
        expect($wire['status'])->toBeIn(['running', 'completed', 'failed', 'cancelled']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-28: ChildRun is a typed first-class object; status is a closed set; no nesting', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core28-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
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

        $runs = $storage->getChildRuns($sessionId);
        $wire = SessionHandler::childRunToWire($runs[0]);

        $v = new ConformanceValidator();
        expect($v->isValid('child-run.json', $wire))->toBeTrue($v->errorText('child-run.json', $wire));
        expect($wire['parent_session_id'])->toBe($sessionId);   // required, present
        expect($wire['status'])->toBeIn(['pending', 'running', 'completed', 'failed', 'cancelled']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-42: content is a typed object addressed by an opaque ref; sha256 identity is required', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core42-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $content = new ContentStore($storage->pdo());
        $bytes = "hello, content-addressing\n";
        $wire = $content->store($bytes, 'text/markdown');

        $v = new ConformanceValidator();
        expect($v->isValid('content.json', $wire))->toBeTrue($v->errorText('content.json', $wire));
        // sha256 identity is required and is the lowercase-hex digest of the bytes.
        expect($wire['sha256'])->toMatch('/^[0-9a-f]{64}$/');
        expect($wire['sha256'])->toBe(hash('sha256', $bytes));
        expect($wire['size'])->toBe(strlen($bytes));
        // content_ref is the opaque handle; the spec never interprets it.
        expect($wire['content_ref'])->not->toBe('');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-26: skills carry a typed origin (closed kind); imported/script skills are untrusted-by-default', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core26-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $skills = new SkillLifecycleStore($storage->pdo());
        $wire = $skills->upsertSkill(
            name: 'summarize',
            description: 'Condense long documents into a brief.',
            status: 'available',
            origin: ['kind' => 'builtin'],
            execution: ['kind' => 'instruction', 'requires' => []],
        );

        $v = new ConformanceValidator();
        expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));
        // origin is a typed object with a closed-set kind (never a bare array).
        expect($wire['origin'])->toBeObject();
        expect($wire['origin']->kind)->toBeIn(['builtin', 'local', 'imported']);
        expect($wire['origin']->kind)->toBe('builtin');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-27: skills declare execution.kind (instruction vs script) + requires; discovery exposes it', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core27-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $skills = new SkillLifecycleStore($storage->pdo());
        $wire = $skills->upsertSkill(
            name: 'deploy_site',
            description: 'Build and push the static site.',
            status: 'available',
            origin: ['kind' => 'imported'],
            execution: ['kind' => 'script', 'requires' => ['shell']],
        );

        $v = new ConformanceValidator();
        expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));
        // execution is a typed object; kind is a closed set; requires is a list.
        expect($wire['execution'])->toBeObject();
        expect($wire['execution']->kind)->toBeIn(['instruction', 'script']);
        expect($wire['execution']->kind)->toBe('script');
        expect($wire['execution']->requires)->toBe(['shell']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-33: the ScheduledTask object is typed; status/action.kind are closed sets', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core33-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $store = new ScheduleStore($storage->getPdo());
        $id = $store->create(
            name: 'daily-review',
            scheduleExpression: '0 9 * * 1-5',
            prompt: 'Review recent changes.',
            personaId: 'caelum',
        );
        $wire = ScheduleStore::toWire($store->get($id));

        $v = new ConformanceValidator();
        expect($v->isValid('scheduled-task.json', $wire))->toBeTrue($v->errorText('scheduled-task.json', $wire));
        // action is a typed object; kind is a closed set (turn|loop); coqui is turn-kind.
        expect($wire['action'])->toBeObject();
        expect($wire['action']->kind)->toBe('turn');
        expect($wire['action']->kind)->toBeIn(['turn', 'loop']);
        // status is a closed set derived from the enabled flag.
        expect($wire['status'])->toBeIn(['enabled', 'disabled']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-16: circuit-breaker + dispatch state are persisted columns, not blob keys', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core16-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $store = new LoopStore($storage->getPdo());
        $id = $store->createLoop(
            definitionName: 'code-review-loop',
            goal: 'Converge on a clean review.',
            configuration: ['name' => 'code-review-loop', 'max_rework_attempts' => 3],
            sessionId: $sessionId,
            personaId: '01J000000000000000000PERSONA',
            maxIterations: 5,
        );

        // The breaker + dispatch diagnostics round-trip through real columns.
        $store->setReworkAttempts($id, 1);
        $store->setDispatchState($id, 'dispatched');

        $wire = LoopStore::toWire($store->getLoop($id));

        $v = new ConformanceValidator();
        expect($v->isValid('loop.json', $wire))->toBeTrue($v->errorText('loop.json', $wire));
        expect($wire['rework_attempts'])->toBe(1);
        expect($wire['dispatch_state'])->toBe('dispatched');
        expect($wire['dispatch_state'])->toBeIn(['pending', 'dispatched']);
        expect($wire['origin'])->toBeIn(['conversation', 'headless']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-25: the Artifact object is typed; session_id is required', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core25-' . bin2hex(random_bytes(8)) . '.db';
    $workspace = sys_get_temp_dir() . '/core25-' . bin2hex(random_bytes(6));
    mkdir($workspace, 0775, true);
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $store = new ArtifactStore($storage->getPdo(), new ArtifactFileService($workspace));
        $id = $store->create($sessionId, 'Design Doc', "# Title\nbody\n", 'document', createdBy: 'coder');

        $wire = ArtifactStore::toWire($store->get($id, $sessionId));

        $v = new ConformanceValidator();
        expect($v->isValid('artifact.json', $wire))->toBeTrue($v->errorText('artifact.json', $wire));

        // session_id is a required, non-empty owning-session reference.
        expect($wire['session_id'])->toBe($sessionId);
        expect($wire['session_id'])->not->toBe('');
        // Files-only content addressing surfaces as the opaque content_ref.
        expect($wire['content_ref'])->toStartWith('artifacts/document/');
        expect($wire['created_at'])->toMatch('/Z$/');
    } finally {
        cleanupSqliteTestDb($dbPath);
        exec('rm -rf ' . escapeshellarg($workspace));
    }
})->group('conformance');

it('CORE-24: the Question object is typed; status is a closed set', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core24-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $request = new QuestionRequest(
            id: '01J000000000000000000QCORE24',
            prompt: 'Which region?',
            format: QuestionFormat::SingleSelect,
            options: [new QuestionOption('us-east', 'US East'), new QuestionOption('eu-west')],
            allowOther: false,
            suggested: new QuestionResponse(selected: ['us-east']),
        );
        $storage->createQuestion($sessionId, $request, 'interactive');
        $storage->recordQuestionAnswer($request->id, new QuestionResponse(selected: ['eu-west']));

        $wire = QuestionPersistence::toWire($storage->getQuestion($request->id));

        $v = new ConformanceValidator();
        expect($v->isValid('question.json', $wire))->toBeTrue($v->errorText('question.json', $wire));

        // status is drawn from the schema's closed set (open|answered|cancelled).
        expect($wire['status'])->toBeIn(['open', 'answered', 'cancelled']);
        expect($wire['status'])->toBe('answered');
        // Typed answer shape + Z-suffixed timestamps.
        expect($wire['answer'])->toBe('eu-west');
        expect($wire['created_at'])->toMatch('/Z$/');
        expect($wire['answered_at'])->toMatch('/Z$/');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-8: a LoopDefinition produces a termination_condition.value shaped by its type', function () {
    $def = new LoopDefinition(
        name: 'code-review-loop',
        description: 'Draft, review, rework.',
        roles: [new LoopRoleDefinition(role: 'coder', prompt: 'Implement.')],
        terminationCondition: new TerminationCondition(TerminationType::IterationBound, maxIterations: 5),
    );
    $wire = LoopDefinitionProducer::toWire($def);

    $v = new ConformanceValidator();
    expect($v->isValid('loop-definition.json', $wire))->toBeTrue($v->errorText('loop-definition.json', $wire));

    // The value shape is discriminated by the type; file defs get version 1.
    expect($wire['termination_condition']['type'])->toBe('iteration_bound');
    expect($wire['termination_condition']['value'])->toBeInt();
    expect($wire['version'])->toBe(1);

    // A value mismatched to its type is rejected — the discriminated oneOf has teeth.
    $mismatched = $wire;
    $mismatched['termination_condition']['value'] = ['criteria' => 'x', 'max_review_rounds' => 2];
    expect($v->isValid('loop-definition.json', $mismatched))->toBeFalse();
})->group('conformance');

it('CORE-13: internal collections (jobs/job_events/audit_records) are typed for export', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core13-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $v = new ConformanceValidator();

        // jobs ← background_tasks
        $jobId = $storage->createTask($sessionId, 'Run the export.', 'orchestrator', title: 'Export');
        $storage->updateTaskStatus($jobId, 'running');
        $job = JobProducer::toWire($storage->getTask($jobId));
        expect($v->isValid('job.json', $job))->toBeTrue($v->errorText('job.json', $job));
        expect($job['created_at'])->toMatch('/Z$/');

        // job_events ← task_events (id is an INTEGER, not an opaque Id)
        $storage->appendTaskEvent($jobId, 'iteration_started', ['iteration' => 1]);
        $event = JobEventProducer::toWire($storage->getTaskEvents($jobId)[0] + ['job_id' => $jobId]);
        expect($v->isValid('job-event.json', $event))->toBeTrue($v->errorText('job-event.json', $event));
        expect($event['id'])->toBeInt();

        // audit_records ← audit_log (arguments is an object; turn_id is dropped)
        $auditId = $storage->logAudit($sessionId, 'shell_exec', ['command' => 'ls'], 'approved');
        $auditRow = array_values(array_filter($storage->getAuditLog($sessionId), static fn(array $r): bool => $r['id'] === $auditId))[0];
        $audit = AuditRecordProducer::toWire($auditRow);
        expect($v->isValid('audit-record.json', $audit))->toBeTrue($v->errorText('audit-record.json', $audit));
        expect($audit['arguments'])->toBeObject();
        expect($audit)->not->toHaveKey('turn_id');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-14: the export envelope types every Core + internal collection', function () {
    $schema = json_decode((string) file_get_contents(__DIR__ . '/spec/schema/export.json'), true, 512, JSON_THROW_ON_ERROR);
    $envelopeCollections = array_values(array_diff(array_keys($schema['properties']), ['protocol_version', 'exported_at']));

    $mapped = ExportCollectionMap::names();
    sort($envelopeCollections);
    sort($mapped);

    // The typing map is exactly the envelope's collection set (no drift).
    expect($mapped)->toBe($envelopeCollections);
    expect($mapped)->toContain('jobs', 'job_events', 'audit_records');

    // A minimal envelope validates; per-collection producibility is covered by
    // tests/conformance/Export/ExportEnvelopeTest.php. The preserve+remap roundtrip
    // import is a Phase 6 gate; memories' DB-backed producer is a Memory-reshape deferral.
    $v = new ConformanceValidator();
    $envelope = ['protocol_version' => '0.5.0', 'exported_at' => '2026-07-28T00:00:03Z'];
    expect($v->isValid('export.json', $envelope))->toBeTrue($v->errorText('export.json', $envelope));
})->group('conformance');

it('CORE-3: every non-null producer timestamp is RFC-3339 UTC (Z)', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core3-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $jobId = $storage->createTask($sessionId, 'Work.', title: 'T');
        $storage->updateTaskStatus($jobId, 'running');

        // The raw column is a +00:00 offset; the producer must rewrite it to Z.
        $raw = (string) $storage->pdo()->query('SELECT created_at FROM background_tasks WHERE id = ' . $storage->pdo()->quote($jobId))->fetchColumn();
        expect($raw)->toContain('+00:00');

        $job = JobProducer::toWire($storage->getTask($jobId));
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/';
        expect($job['created_at'])->toMatch($pattern);
        expect($job['started_at'])->toMatch($pattern);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-59: nullable producer timestamps are null or Z, never a non-Z offset', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core59-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        // A pending job: created_at set; started_at/completed_at/cancelled_at null.
        $jobId = $storage->createTask($sessionId, 'Work.', title: 'T');
        $job = JobProducer::toWire($storage->getTask($jobId));

        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/';
        expect($job['created_at'])->toMatch($pattern);
        foreach (['started_at', 'completed_at', 'cancelled_at'] as $field) {
            expect($job[$field] === null || preg_match($pattern, (string) $job[$field]) === 1)->toBeTrue("{$field} must be null or Z");
            if ($job[$field] !== null) {
                expect($job[$field])->not->toMatch('/[+-]\d{2}:?\d{2}$/');
            }
        }
        expect($job['started_at'])->toBeNull();
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

$rows = [
    // Spec 0.3 Core MUSTs (CORE-2..CORE-35).
    'CORE-2: enums are closed; out-of-set values rejected',
    'CORE-4: error payloads carry a code from the closed catalog',
    'CORE-5: SSE frames carry a resumable id; reconnect replays after it',
    'CORE-6: the loop live snapshot is fully typed',
    'CORE-7: verdict is typed; approval requires both flags + no Critical/Important',
    'CORE-9: PATCH bodies are typed + reject unknown fields',
    'CORE-10: mutable Core objects carry version; stale writes 409',
    'CORE-11: instances expose a typed model catalog (id, context_window, tokenizer_hint)',
    'CORE-12: budget tiering + pinned security normative; shed order is SHOULD + inspectable',
    'CORE-17: deleting a session cascade-stops any non-terminal loop using it',
    'CORE-18: list operations paginate + declare a default sort',
    'CORE-22: artifact_required is persona-gated; a def requiring it on a no-artifacts instance is rejected 422 at loop creation',
    'CORE-29: spawn is a gated Core op (full-access, top-level only); child runs stream + export',
    'CORE-30: extension is a declared gradient; host toolkits are declared in InstanceInfo; personas are a closed set',
    'CORE-31: the mcp persona pins the integration contract (namespacing/gating/budget/trust/transports); transports are a closed set',
    'CORE-32: vision (image understanding) is an access-gated built-in; generation is extension-only',
    'CORE-35: InstanceInfo MAY carry per-persona versions (semver); docs content is impl-defined',

    // 0.4 binding-interop MUSTs (CORE-36..CORE-59).
    'CORE-36: responses/events are wire-tolerant: consumers MUST NOT reject unknown fields/enums',
    'CORE-37: create bodies are authoring-shaped; server-owned fields (id/version/timestamps) are rejected 422',
    'CORE-38: role/loop-definition PUT distinguishes create (If-None-Match:*) from update (If-Match:v); persisted rows require version',
    'CORE-39: InstanceInfo.personas is an open string set; discovery MUST NOT reject an unknown persona',
    'CORE-40: every operation\'s documented error codes come from the closed catalog via reusable responses; coverage is complete',
    'CORE-41: SSE error events carry a code from the closed catalog',
    'CORE-43: messages carry typed attachments[] of {content_ref, mime_type}',
    'CORE-44: content ops (putContent/getContent) are bound (multipart/binary upload + Range download)',
    'CORE-45: export types a content collection; import round-trips it (preserve+remap)',
    'CORE-46: discovery InstanceInfo types auth/limits/api/builtin_toolkits; auth scheme is a closed set',
    'CORE-47: x-persona operations map cleanly across both bindings (HTTP + in_process)',
    'CORE-48: ask_user answer is a Core path (submitTurnAnswer); SSE question frames carry question_id',
    'CORE-49: question format is rich (multi-select) with a typed option shape',
    'CORE-50: scheduled_task.action is a discriminated union keyed by kind; a loop action requires a definition',
    'CORE-51: SSE frames are typed per channel; unknown event shapes are rejected',
    'CORE-52: SSE frame id is a string cursor; a numeric id is rejected',
    'CORE-53: creators accept an Idempotency-Key request header for dedup',
    'CORE-54: sessions are authorable via PATCH (clear model->null, set workspace); empty patch is rejected',
    'CORE-55: budget observability is typed (GET /sessions/{id}/budget breakdown)',
    'CORE-56: import supports mode=preserve|remap; remap atomically rewrites every FK',
    'CORE-57: in-process binding is normatively specified; thrown errors are typed with a catalog code',
    'CORE-58: single-vs-list response cardinality agrees across in_process, operations.yaml, and openapi',
];

foreach ($rows as $row) {
    test($row)->todo();
}
