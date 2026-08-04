<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Provider\Response as ProviderResponse;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\Handler\ChildRunHandler;
use CoquiBot\Coqui\Agent\SessionWorkspaceResolver;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Discovery\InstanceInfoBuilder;
use CoquiBot\Coqui\Api\Model\ModelProducer;
use CoquiBot\Coqui\Api\Loop\LoopLiveProducer;
use CoquiBot\Coqui\Api\Budget\BudgetBreakdownProducer;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Api\Middleware\IdempotencyMiddleware;
use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Api\Handler\FileUploadHandler;
use CoquiBot\Coqui\Api\Handler\LoopHandler;
use CoquiBot\Coqui\Api\Handler\MessageHandler;
use CoquiBot\Coqui\Api\Handler\QuestionHandler;
use CoquiBot\Coqui\Api\Handler\RoleHandler;
use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Sse\SseCursor;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleParser;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Storage\ObjectVersionStore;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Contract\ContextUsageSnapshot;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;
use CoquiBot\Coqui\Contract\PromptBudgetSnapshot;
use CoquiBot\Coqui\Contract\StageFinding;
use CoquiBot\Coqui\Contract\StageSeverity;
use CoquiBot\Coqui\Contract\Verdict;
use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Contract\TerminationCondition;
use CoquiBot\Coqui\Contract\TerminationType;
use CoquiBot\Coqui\Exception\RequestBodyException;
use CoquiBot\Coqui\Export\AuditRecordProducer;
use CoquiBot\Coqui\Export\ContentProducer;
use CoquiBot\Coqui\Export\ExportCollectionMap;
use CoquiBot\Coqui\Export\JobEventProducer;
use CoquiBot\Coqui\Export\JobProducer;
use CoquiBot\Coqui\Export\LoopDefinitionProducer;
use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Question\SuspendingQuestionResponder;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\IdempotencyStore;
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

/**
 * Build the real ConfigHandler over a temp workspace + temp SQLite db, seeded
 * with one file-authored persona "caelum". Returns [handler, workspace, dbPath].
 *
 * @return array{0: ConfigHandler, 1: string, 2: string}
 */
function makePersonaConfigHandler(): array
{
    $workspace = sys_get_temp_dir() . '/coqui-persona-ws-' . bin2hex(random_bytes(8));
    mkdir($workspace . '/personas/caelum', 0755, true);
    file_put_contents(
        $workspace . '/personas/caelum/soul.md',
        "---\nmodel: anthropic/claude-sonnet-4\n---\n\n# Caelum\n\nA warm, precise research companion.\n",
    );

    $dbPath = sys_get_temp_dir() . '/coqui-persona-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    $handler = new ConfigHandler(
        OpenClawConfig::fromArray([]),
        new ConfigValidator(),
        new PersonaDiscovery($workspace),
        null,
        null,
        null,
        null,
        null,
        new ObjectVersionStore($storage->getPdo()),
    );

    return [$handler, $workspace, $dbPath];
}

function personaCreateRequest(array $body): \React\Http\Message\ServerRequest
{
    return new \React\Http\Message\ServerRequest(
        'POST',
        '/api/v1/personas',
        ['Content-Type' => 'application/json'],
        json_encode($body) ?: '',
    );
}

function personaPatchRequest(string $name, array $body): \React\Http\Message\ServerRequest
{
    return new \React\Http\Message\ServerRequest(
        'PATCH',
        '/api/v1/personas/' . $name,
        ['Content-Type' => 'application/json'],
        json_encode($body) ?: '',
    );
}

/**
 * Build a GET request that mimics an SSE reconnect: the client echoes the
 * transport cursor via a `Last-Event-ID` header (an already-encoded string
 * cursor) and/or a `?since`/`?since_id` query parameter.
 */
function sseReconnectRequest(?string $lastEventId = null, ?string $since = null, ?string $sinceId = null): \React\Http\Message\ServerRequest
{
    $query = [];
    if ($since !== null) {
        $query['since'] = $since;
    }
    if ($sinceId !== null) {
        $query['since_id'] = $sinceId;
    }

    $path = '/api/v1/sessions/x/messages';
    if ($query !== []) {
        $path .= '?' . http_build_query($query);
    }

    $headers = $lastEventId !== null ? ['Last-Event-ID' => $lastEventId] : [];

    return (new \React\Http\Message\ServerRequest('GET', $path, $headers))
        ->withQueryParams($query);
}

it('CORE-9: persona PATCH rejects unknown fields and an empty body', function () {
    [$handler, $workspace, $dbPath] = makePersonaConfigHandler();
    try {
        $unknown = $handler->updatePersona(personaPatchRequest('caelum', ['telepathy' => true]));
        expect($unknown->getStatusCode())->toBe(422);
        expect(json_decode((string) $unknown->getBody(), true)['code'])->toBe('validation_error');

        $empty = $handler->updatePersona(personaPatchRequest('caelum', []));
        expect($empty->getStatusCode())->toBe(422);
    } finally {
        cleanupTestTree($workspace);
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-37: persona create rejects a server-owned field (422) and accepts the authoring shape', function () {
    [$handler, $workspace, $dbPath] = makePersonaConfigHandler();
    try {
        $bad = $handler->createPersona(personaCreateRequest([
            'id' => '01J000000000000000000PERSONA', // server-owned ⇒ reject
            'name' => 'nova', 'avatar' => new \stdClass(),
            'model' => 'anthropic/claude-sonnet-4', 'allowed_roles' => ['orchestrator'], 'soul' => 'x',
        ]));
        expect($bad->getStatusCode())->toBe(422);
        expect(json_decode((string) $bad->getBody(), true)['code'])->toBe('validation_error');

        $ok = $handler->createPersona(personaCreateRequest([
            'name' => 'nova', 'avatar' => new \stdClass(),
            'model' => 'anthropic/claude-sonnet-4', 'allowed_roles' => ['orchestrator'], 'soul' => 'x',
        ]));
        expect($ok->getStatusCode())->toBe(201);
        $body = json_decode((string) $ok->getBody(), true);
        $v = new ConformanceValidator();
        // The served persona is a schema-valid persona.json with version 1.
        expect($v->isValid('persona.json', $handler->servedPersonaWire('nova')))
            ->toBeTrue($v->errorText('persona.json', $handler->servedPersonaWire('nova')));
        expect($body['version'] ?? ($handler->servedPersonaWire('nova')['version']))->toBe(1);
    } finally {
        cleanupTestTree($workspace);
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

/**
 * Build the real RoleHandler over a temp workspace + temp SQLite db, wired to a
 * live ObjectVersionStore. Returns [handler, workspace, dbPath].
 *
 * @return array{0: RoleHandler, 1: string, 2: string}
 */
function makeRoleHandler(): array
{
    $workspace = sys_get_temp_dir() . '/coqui-role-ws-' . bin2hex(random_bytes(8));
    mkdir($workspace . '/roles', 0755, true);

    $dbPath = sys_get_temp_dir() . '/coqui-role-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    $config = OpenClawConfig::fromArray([
        'agents' => ['defaults' => ['model' => ['primary' => 'ollama/qwen3:latest']]],
    ]);
    $roleDiscovery = new RoleDiscovery($workspace);
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery);

    $handler = new RoleHandler($roleDiscovery, $roleResolver, null, new ObjectVersionStore($storage->getPdo()));

    return [$handler, $workspace, $dbPath];
}

/**
 * Build the real loop-definition PUT handler over a temp workspace + temp
 * SQLite db, wired to a live ObjectVersionStore. Returns [handler, workspace, dbPath].
 *
 * @return array{0: LoopHandler, 1: string, 2: string}
 */
function makeLoopDefHandler(): array
{
    $workspace = sys_get_temp_dir() . '/coqui-loopdef-ws-' . bin2hex(random_bytes(8));
    mkdir($workspace . '/loops', 0755, true);

    $dbPath = sys_get_temp_dir() . '/coqui-loopdef-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());
    $discovery = new LoopDiscovery($workspace);

    $handler = new LoopHandler($loopStore, $discovery, null, $storage, null, null, new ObjectVersionStore($storage->getPdo()));

    return [$handler, $workspace, $dbPath];
}

/**
 * Build a PUT request for a role, with optional precondition headers.
 *
 * @param array<string, mixed> $body
 */
function rolePutRequest(string $name, array $body, ?string $ifNoneMatch = null, ?int $ifMatch = null): \React\Http\Message\ServerRequest
{
    $headers = ['Content-Type' => 'application/json'];
    if ($ifNoneMatch !== null) {
        $headers['If-None-Match'] = $ifNoneMatch;
    }
    if ($ifMatch !== null) {
        $headers['If-Match'] = (string) $ifMatch;
    }

    return new \React\Http\Message\ServerRequest('PUT', '/api/v1/roles/' . $name, $headers, json_encode($body) ?: '');
}

/**
 * Build a PUT request for a loop definition, with optional precondition headers.
 *
 * @param array<string, mixed> $body
 */
function loopDefPutRequest(string $name, array $body, ?string $ifNoneMatch = null, ?int $ifMatch = null): \React\Http\Message\ServerRequest
{
    $headers = ['Content-Type' => 'application/json'];
    if ($ifNoneMatch !== null) {
        $headers['If-None-Match'] = $ifNoneMatch;
    }
    if ($ifMatch !== null) {
        $headers['If-Match'] = (string) $ifMatch;
    }

    return new \React\Http\Message\ServerRequest('PUT', '/api/v1/loops/definitions/' . $name, $headers, json_encode($body) ?: '');
}

/**
 * A minimal, valid loop-definition authoring body (loop-definition.put.json).
 *
 * @return array<string, mixed>
 */
function validLoopDefBody(string $name = 'ci'): array
{
    return [
        'name' => $name,
        'description' => 'CI loop',
        'roles' => [['role' => 'plan', 'prompt' => 'go']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 2],
    ];
}

/**
 * Build a GET list request carrying optional pagination query params.
 *
 * @param array<string, int|string> $query
 */
function listRequest(array $query = []): \React\Http\Message\ServerRequest
{
    $path = '/api/v1/roles';
    if ($query !== []) {
        $path .= '?' . http_build_query($query);
    }

    return new \React\Http\Message\ServerRequest('GET', $path);
}

it('CORE-18: list operations return a {data, next_cursor} page with a declared name sort', function () {
    [$roleHandler, $ws, $db] = makeRoleHandler();
    try {
        // Seed several roles out of alpha order, then list.
        $roleHandler->put(rolePutRequest('zeta', ['name' => 'zeta', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'zeta');
        $roleHandler->put(rolePutRequest('alpha', ['name' => 'alpha', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'alpha');

        $page = json_decode((string) $roleHandler->list(listRequest(['limit' => 100]))->getBody(), true);
        expect($page)->toHaveKeys(['data', 'next_cursor']);

        $names = array_column($page['data'], 'name');
        // The seeded roles appear in ascending name order (declared default sort).
        // Builtin roles may lead, so assert only the relative order of the two.
        $seeded = array_values(array_intersect($names, ['alpha', 'zeta']));
        expect($seeded)->toBe(['alpha', 'zeta']);

        // Teeth on pagination itself: a truncated page yields a resumable
        // non-null cursor that resumes strictly after the last emitted item.
        $firstPage = json_decode((string) $roleHandler->list(listRequest(['limit' => 1]))->getBody(), true);
        expect($firstPage['data'])->toHaveCount(1);
        expect($firstPage['next_cursor'])->not->toBeNull();

        $secondPage = json_decode((string) $roleHandler->list(listRequest(['limit' => 1, 'cursor' => $firstPage['next_cursor']]))->getBody(), true);
        expect($secondPage['data'])->toHaveCount(1);
        expect($secondPage['data'][0]['name'])->not->toBe($firstPage['data'][0]['name']);
        expect(strcmp((string) $secondPage['data'][0]['name'], (string) $firstPage['data'][0]['name']))->toBeGreaterThan(0);
    } finally {
        cleanupTestTree($ws);
        cleanupSqliteTestDb($db);
    }
})->group('conformance');

it('CORE-38: role/loop-definition PUT distinguishes create (If-None-Match:*) from update (If-Match:v); persisted rows require version', function () {
    // --- role ---
    [$roleHandler, $rWs, $rDb] = makeRoleHandler();
    try {
        $create = $roleHandler->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'reviewer');
        expect($create->getStatusCode())->toBe(201);
        $created = json_decode((string) $create->getBody(), true);
        expect($created['version'])->toBe(1);

        // The persisted role is a schema-valid role.json (carries version).
        $v = new ConformanceValidator();
        $roleWire = $roleHandler->servedRoleWire('reviewer');
        expect($v->isValid('role.json', $roleWire))->toBeTrue($v->errorText('role.json', $roleWire));

        // Re-create without a precondition-for-update ⇒ conflict.
        expect($roleHandler->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'reviewer')->getStatusCode())->toBe(409);

        // Update with the wrong version ⇒ version_conflict.
        $stale = $roleHandler->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'full'], ifMatch: 99), 'reviewer');
        expect($stale->getStatusCode())->toBe(409);
        expect(json_decode((string) $stale->getBody(), true)['code'])->toBe('version_conflict');
    } finally {
        cleanupTestTree($rWs);
        cleanupSqliteTestDb($rDb);
    }

    // --- loop-definition (same contract) ---
    [$loopHandler, $lWs, $lDb] = makeLoopDefHandler();
    try {
        $createDef = $loopHandler->putDefinition(loopDefPutRequest('ci', validLoopDefBody(), ifNoneMatch: '*'), 'ci');
        expect($createDef->getStatusCode())->toBe(201);
        // The served definition carries the server-assigned version.
        expect(json_decode((string) $createDef->getBody(), true)['version'])->toBe(1);

        $stale = $loopHandler->putDefinition(loopDefPutRequest('ci', validLoopDefBody(), ifMatch: 99), 'ci');
        expect($stale->getStatusCode())->toBe(409);
        expect(json_decode((string) $stale->getBody(), true)['code'])->toBe('version_conflict');
    } finally {
        cleanupTestTree($lWs);
        cleanupSqliteTestDb($lDb);
    }
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

it('CORE-17: deleting a session cascade-stops any non-terminal loop using it', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core17-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $loopStore = new LoopStore($storage->getPdo());
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');

        // A non-terminal (running) loop and a terminal (completed) loop, both bound
        // to the same session.
        $runningId = $loopStore->createLoop('harness', 'Keep ticking', [], sessionId: $sessionId);
        $completedId = $loopStore->createLoop('harness', 'Already done', [], sessionId: $sessionId);
        $loopStore->updateLoopStatus($completedId, 'completed');

        $storage->deleteSession($sessionId);

        // The non-terminal loop is cancelled — no orphan keeps ticking. The terminal
        // loop's end-state is preserved.
        expect($loopStore->getLoop($runningId)['status'])->toBe('cancelled');
        expect($loopStore->getLoop($completedId)['status'])->toBe('completed');
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

it('CORE-22: artifact_required is persona-gated; a def requiring it on a no-artifacts persona is rejected 422 at loop creation', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core22-' . bin2hex(random_bytes(8)) . '.db';
    $workspacePath = sys_get_temp_dir() . '/coqui-core22-ws-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/loops', 0755, true);

    // A persona with the artifacts capability explicitly disabled.
    $personaDir = $workspacePath . '/personas/capped';
    mkdir($personaDir, 0755, true);
    file_put_contents($personaDir . '/soul.md', "# capped\n\nArtifacts disabled.\n");
    file_put_contents(
        $personaDir . '/preferences.json',
        json_encode(['prompts' => ['features' => ['artifacts' => false]]]) ?: '',
    );

    // A loop definition whose role hard-requires a durable artifact.
    file_put_contents(
        $workspacePath . '/loops/needs-artifact.json',
        json_encode([
            'name' => 'needs-artifact',
            'description' => 'A stage that must produce a durable artifact',
            'roles' => [
                ['role' => 'coder', 'prompt' => 'Produce a durable artifact.', 'artifact_required' => true],
            ],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => 1],
        ]) ?: '',
    );

    $storage = new SessionStorage($dbPath);

    try {
        $pdo = $storage->getPdo();
        $loopStore = new LoopStore($pdo);
        $projectStore = new ProjectStore($pdo);
        $executor = new LoopExecutor(loopStore: $loopStore, projectStore: $projectStore);
        $handler = new LoopHandler(
            $loopStore,
            new LoopDiscovery($workspacePath),
            $executor,
            $storage,
            $projectStore,
            new PersonaDiscovery($workspacePath),
            new ObjectVersionStore($pdo),
        );

        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'capped');

        $response = $handler->create(new \React\Http\Message\ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'needs-artifact',
                'goal' => 'Produce a durable artifact',
                'session_id' => $sessionId,
            ]) ?: '',
        ));
        $body = json_decode((string) $response->getBody(), true);

        // Rejected at creation, not discovered mid-run.
        expect($response->getStatusCode())->toBe(422);
        expect($body['code'])->toBe('validation_error');
        // The code is drawn from the closed CAP error catalog.
        expect(ApiErrorCode::tryFrom($body['code']))->not->toBeNull();

        // The gate blocks before any loop row is persisted.
        expect($loopStore->listLoops())->toHaveCount(0);
    } finally {
        cleanupSqliteTestDb($dbPath);
        cleanupTestTree($workspacePath);
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
        $storage->createChildRun(
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

it('CORE-29 (part 1): spawnChildRun runs the child, records running→completed, returns 202; getChildRun fetches it', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core29-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => ['coder' => 'anthropic/claude-sonnet-4'],
                ],
            ],
        ]);

        // A deterministic provider: one Stop response with a split token triad, so
        // the child completes on its first iteration without touching the network.
        $provider = new class implements ProviderInterface {
            public function chat(array $messages, array $tools = [], array $options = []): ProviderResponse
            {
                return new ProviderResponse(
                    content: 'Child result.',
                    finishReason: ProviderFinishReason::Stop,
                    model: 'anthropic/claude-sonnet-4',
                    usage: new Usage(promptTokens: 30, completionTokens: 12, totalTokens: 42),
                );
            }

            public function stream(array $messages, array $tools = [], array $options = []): iterable
            {
                yield new ProviderResponse(
                    content: 'Child result.',
                    finishReason: ProviderFinishReason::Stop,
                    model: 'anthropic/claude-sonnet-4',
                    usage: new Usage(promptTokens: 30, completionTokens: 12, totalTokens: 42),
                );
            }

            public function structured(array $messages, string $schema, array $options = []): mixed
            {
                return [];
            }

            public function models(): array
            {
                return [];
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'anthropic/claude-sonnet-4';
            }

            public function withModel(string $model): static
            {
                return $this;
            }
        };

        $handler = new ChildRunHandler(
            $storage,
            new RoleResolver($config),
            new ProviderFactory($config),
            null,
            fn (string $model): ProviderInterface => $provider,
        );

        // Top-level, full-access orchestrator session ⇒ spawning is allowed.
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');

        $spawn = $handler->spawnChildRun(
            new \React\Http\Message\ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/child-runs',
                ['Content-Type' => 'application/json'],
                json_encode(['role' => 'coder', 'prompt' => 'Implement the fix.']) ?: '',
            ),
            $sessionId,
        );

        expect($spawn->getStatusCode())->toBe(202);

        $spawnWire = json_decode((string) $spawn->getBody(), true);

        $v = new ConformanceValidator();
        expect($v->isValid('child-run.json', $spawnWire))->toBeTrue($v->errorText('child-run.json', $spawnWire));
        expect($spawnWire['parent_session_id'])->toBe($sessionId);
        expect($spawnWire['role'])->toBe('coder');
        expect($spawnWire['status'])->toBeIn(['completed', 'failed']);   // terminal after sync execute
        expect($spawnWire['status'])->toBe('completed');
        expect($spawnWire['result'])->toBe('Child result.');
        expect($spawnWire['prompt_tokens'])->toBe(30);
        expect($spawnWire['completion_tokens'])->toBe(12);
        expect($spawnWire['total_tokens'])->toBe(42);
        expect($spawnWire['completed_at'])->not->toBeNull();

        // The row recorded a running→completed transition: it was inserted running
        // (created_at) and finalized completed (completed_at, token triad).
        $childRunId = $spawnWire['id'];
        $row = $storage->getChildRun($childRunId);
        expect($row)->not->toBeNull();
        expect($row['status'])->toBe('completed');
        expect($row['created_at'])->not->toBeNull();
        expect($row['completed_at'])->not->toBeNull();

        // getChildRun fetches the same resource.
        $getResponse = $handler->getChildRun(
            new \React\Http\Message\ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/child-runs/' . $childRunId),
            $sessionId,
            $childRunId,
        );
        expect($getResponse->getStatusCode())->toBe(200);
        $getWire = json_decode((string) $getResponse->getBody(), true);
        expect($v->isValid('child-run.json', $getWire))->toBeTrue($v->errorText('child-run.json', $getWire));
        expect($getWire['id'])->toBe($childRunId);
        expect($getWire['status'])->toBe('completed');

        // getChildRun 404s for an absent child run.
        $missing = $handler->getChildRun(
            new \React\Http\Message\ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/child-runs/does-not-exist'),
            $sessionId,
            'does-not-exist',
        );
        expect($missing->getStatusCode())->toBe(404);

        // A non-top-level / non-full-access session is forbidden from spawning.
        $childSessionId = $storage->createSession('reviewer', 'anthropic/claude-sonnet-4', 'caelum');
        $forbidden = $handler->spawnChildRun(
            new \React\Http\Message\ServerRequest(
                'POST',
                '/api/v1/sessions/' . $childSessionId . '/child-runs',
                ['Content-Type' => 'application/json'],
                json_encode(['role' => 'coder', 'prompt' => 'Nested spawn attempt.']) ?: '',
            ),
            $childSessionId,
        );
        expect($forbidden->getStatusCode())->toBe(403);
        // No child run was written for the forbidden session.
        expect($storage->getChildRuns($childSessionId))->toHaveCount(0);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

/**
 * A `childRunToWire`-shaped, schema-valid child-run.json fixture, produced by the
 * SOLE Task-8 wire producer over a seeded row (no live DB needed — childRunToWire
 * is pure). Reused by the CORE-29 frame + export assertions.
 *
 * @return array<string, mixed>
 */
function childRunFixture(): array
{
    return SessionHandler::childRunToWire([
        'id' => '01J00000000000000000CHILDRUN',
        'parent_session_id' => '01J000000000000000000SESSION',
        'parent_turn_id' => null,
        'role' => 'coder',
        'model' => 'anthropic/claude-sonnet-4',
        'prompt' => 'Implement the fix.',
        'result' => 'Child result.',
        'status' => 'completed',
        'prompt_tokens' => 30,
        'completion_tokens' => 12,
        'total_tokens' => 42,
        'created_at' => '2026-07-28T00:00:00Z',
        'completed_at' => '2026-07-28T00:00:05Z',
    ]);
}

it('CORE-29: child runs are a gated Core op that streams typed events and exports', function () {
    $v = new ConformanceValidator();

    // (a) The PURE frame builder produces schema-valid `started` and terminal
    // `done` frames of the sse-childrun-event.json discriminated union.
    $started = ChildRunHandler::buildChildRunEventFrame('started', ['child_run_id' => 'cr_1'], SseCursor::encode(1));
    expect($v->isValid('sse-childrun-event.json', $started))->toBeTrue($v->errorText('sse-childrun-event.json', $started));

    $done = ChildRunHandler::buildChildRunEventFrame('done', childRunFixture(), SseCursor::encode(3));
    expect($v->isValid('sse-childrun-event.json', $done))->toBeTrue($v->errorText('sse-childrun-event.json', $done));

    // (b) `done` is in the closed event set; an event name OUTSIDE the set has no
    // matching branch and is schema-REJECTED (the closed set has teeth).
    expect(in_array($done['event'], ['started', 'token', 'message', 'done'], true))->toBeTrue();
    $unknown = ChildRunHandler::buildChildRunEventFrame('reasoning', ['x' => 1], SseCursor::encode(4));
    expect($v->isValid('sse-childrun-event.json', $unknown))->toBeFalse();

    // (c) Export types the child_runs collection against child-run.json, and a
    // childRunToWire fixture validates against that same schema.
    expect(ExportCollectionMap::schemas()['child_runs'])->toBe('child-run.json');
    expect($v->isValid('child-run.json', childRunFixture()))->toBeTrue($v->errorText('child-run.json', childRunFixture()));

    // (d) The gated 202 spawn creator exists — proven in depth by CORE-29 (part 1)
    // above (running→completed, 202, and the 403 gate on non-top-level sessions).
    expect(method_exists(ChildRunHandler::class, 'spawnChildRun'))->toBeTrue();
})->group('conformance');

it('CORE-55: budget observability is typed (GET /sessions/{id}/budget breakdown)', function () {
    $workspacePath = sys_get_temp_dir() . '/coqui-core55-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/data', 0755, true);
    file_put_contents($workspacePath . '/.env', '');

    $dbPath = sys_get_temp_dir() . '/coqui-core55-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $projectRoot = dirname(__DIR__, 2);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);
    $credentialResolver = new \CoquiBot\Coqui\Config\CredentialResolver($workspacePath);

    $runner = new \CoquiBot\Coqui\Agent\AgentRunner(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: $projectRoot,
        workspacePath: $workspacePath,
        storage: $storage,
        observer: null,
        discovery: new \CoquiBot\Coqui\Config\ToolkitDiscovery(
            projectRoot: $projectRoot,
            workspacePath: $workspacePath,
            credentialResolver: $credentialResolver,
        ),
        blacklist: new \CoquiBot\Coqui\Config\CatastrophicBlacklist(),
        credentialResolver: $credentialResolver,
        providerFactory: new \CarmeloSantana\PHPAgents\Provider\ProviderFactory($config),
    );

    $handler = new \CoquiBot\Coqui\Api\Handler\BudgetHandler($runner, $storage);

    try {
        // A missing session is a typed 404 with the catalog code.
        $missing = $handler->session(
            new \React\Http\Message\ServerRequest('GET', '/api/v1/sessions/does-not-exist/budget'),
            'does-not-exist',
        );
        expect($missing->getStatusCode())->toBe(404);

        $sessionId = $storage->createSession('orchestrator', 'ollama/qwen3:latest');
        $response = $handler->session(
            new \React\Http\Message\ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/budget'),
            $sessionId,
        );
        expect($response->getStatusCode())->toBe(200);

        $wire = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $v = new ConformanceValidator();
        expect($v->isValid('budget-breakdown.json', $wire))->toBeTrue($v->errorText('budget-breakdown.json', $wire));

        // total_estimated_tokens is the sum of the INCLUDED sections' tokens.
        $includedSum = array_sum(array_map(
            static fn(array $s): int => $s['included'] ? $s['estimated_tokens'] : 0,
            $wire['sections'],
        ));
        expect($wire['total_estimated_tokens'])->toBe($includedSum);
        // Cost is legible against capacity: the window is always a positive int.
        expect($wire['model_context_window'])->toBeGreaterThanOrEqual(1);
        // Every included section reports no shed reason.
        foreach ($wire['sections'] as $section) {
            if ($section['included']) {
                expect($section['shed_reason'])->toBeNull();
            }
        }
    } finally {
        cleanupSqliteTestDb($dbPath);
        cleanupTestTree($workspacePath);
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

it('CORE-43: messages carry typed attachments of {content_ref, mime_type}', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core43-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $sid = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4');
        $mid = $storage->addMessage($sid, 'user', 'see attached', attachments: [
            ['content_ref' => '01J00000000000000000CONTENT1', 'mime_type' => 'text/plain'],
        ]);

        $wire = MessageHandler::toWire($storage->getMessageRow($mid));

        $v = new ConformanceValidator();
        expect($v->isValid('message.json', $wire))->toBeTrue($v->errorText('message.json', $wire));
        // The attachment is the typed {content_ref, mime_type} shape (nothing extra).
        expect($wire['attachments'][0])->toBe([
            'content_ref' => '01J00000000000000000CONTENT1',
            'mime_type' => 'text/plain',
        ]);
        // session_id is carried (required by message.json); turn_id is optional/nullable.
        expect($wire['session_id'])->toBe($sid);
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
            action: ['kind' => 'turn', 'prompt' => 'Review recent changes.'],
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

/**
 * A persisted `scheduled_tasks` row shaped against the real columns
 * (`ScheduleStore::createTables`): `action_kind` discriminates the union, with
 * `prompt` carrying the turn payload and `definition_name` the loop reference.
 *
 * @return array<string, mixed>
 */
function scheduleRow(
    string $actionKind = 'turn',
    ?string $prompt = null,
    ?string $definitionName = null,
    ?string $personaId = '01J00000000000000000000P01',
): array {
    return [
        'id' => '01J00000000000000000000S01',
        'name' => 'nightly-research',
        'cron' => '0 3 * * *',
        'persona_id' => $personaId,
        'action_kind' => $actionKind,
        'prompt' => $prompt,
        'definition_name' => $definitionName,
        'enabled' => 1,
        'last_run_at' => null,
        'next_run_at' => '2026-07-29T03:00:00Z',
        'created_at' => '2026-07-28T12:00:00Z',
        'updated_at' => '2026-07-28T12:00:00Z',
    ];
}

it('CORE-50: scheduled_task.action is a kind-discriminated union; a loop action requires a definition', function () {
    $v = new ConformanceValidator();
    // turn action round-trips through the store's producer and validates.
    $turnWire = ScheduleStore::toWire(scheduleRow(actionKind: 'turn', prompt: 'Summarize inbox', personaId: 'p_1'));
    expect($v->isValid('scheduled-task.json', $turnWire))->toBeTrue($v->errorText('scheduled-task.json', $turnWire));
    expect($turnWire['action'])->toMatchArray(['kind' => 'turn', 'prompt' => 'Summarize inbox']);
    // loop action with a definition validates.
    $loopWire = ScheduleStore::toWire(scheduleRow(actionKind: 'loop', definitionName: 'research', personaId: 'p_1'));
    expect($v->isValid('scheduled-task.json', $loopWire))->toBeTrue($v->errorText('scheduled-task.json', $loopWire));
    expect($loopWire['action'])->toMatchArray(['kind' => 'loop', 'definition_name' => 'research']);
    // the vendored loop-no-definition vector is rejected.
    $bad = json_decode((string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/scheduled-task.loop-no-definition.json'), true);
    expect($v->isValid('scheduled-task.json', $bad))->toBeFalse();
    // coqui's own store refuses to persist a loop action without a definition.
    $dbPath = sys_get_temp_dir() . '/coqui-core50-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $store = new ScheduleStore(new \PDO('sqlite:' . $dbPath));
        expect(fn () => $store->create(name: 'x', scheduleExpression: '0 * * * *', action: ['kind' => 'loop'], personaId: 'p_1'))
            ->toThrow(RequestBodyException::class);
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

/**
 * Build a JSON answer request for the turn-scoped submitTurnAnswer path.
 *
 * @param array<string, mixed> $body
 */
function core48AnswerRequest(array $body): \React\Http\Message\ServerRequest
{
    return new \React\Http\Message\ServerRequest(
        'POST',
        '/api/v1/sessions/x/turns/y/answer',
        ['Content-Type' => 'application/json'],
        json_encode($body) ?: '',
    );
}

it('CORE-48: turn stream emits question frames carrying question_id; submitTurnAnswer resolves them', function () {
    $v = new ConformanceValidator();

    // (a) The SSE question frame is built from a recorded `question` turn event
    // (a QuestionRequest::toArray payload) and carries the required question_id.
    $eventData = [
        'id' => 'q_123',
        'prompt' => 'Deploy where?',
        'format' => 'single_select',
        'options' => [['label' => 'staging'], ['label' => 'production']],
        'allow_other' => false,
        'suggested' => ['selected' => ['staging']],
    ];
    $frame = MessageHandler::buildQuestionFrame($eventData, SseCursor::encode(9));
    expect($v->isValid('sse-question.json', $frame))->toBeTrue($v->errorText('sse-question.json', $frame));
    expect($frame['event'])->toBe('question');
    expect($frame['data']['question_id'])->toBe('q_123');
    // Options reuse the Task-5 {value,label?} projection; suggested collapses to a scalar.
    expect($frame['data']['options'])->toBe([['value' => 'staging'], ['value' => 'production']]);
    expect($frame['data']['suggested'])->toBe('staging');

    // A frame missing question_id is schema-rejected (mirrors
    // invalid/sse-question.no-question-id.json); question_id is never emitted null.
    $bad = $frame;
    unset($bad['data']['question_id']);
    expect($v->isValid('sse-question.json', $bad))->toBeFalse();

    // (b) submitTurnAnswer over a temp db resolves the pending question for the
    // turn and records it — a Core path reachable without the `questions` profile.
    $dbPath = sys_get_temp_dir() . '/coqui-core48-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $turnId = $storage->createTurnProcess($sessionId, 'ask me');

        $request = new QuestionRequest(
            id: '01J00000000000000000QCORE48',
            prompt: 'Deploy where?',
            format: QuestionFormat::SingleSelect,
            options: [new QuestionOption('staging'), new QuestionOption('production')],
            allowOther: false,
            suggested: new QuestionResponse(selected: ['staging']),
        );
        $storage->createQuestion($sessionId, $request, 'suspending', $turnId);

        $handler = new QuestionHandler(new QuestionPersistence($storage), $storage);

        // No pending question for an unknown turn ⇒ 404 not_found.
        $miss = $handler->submitTurnAnswer(core48AnswerRequest(['selected' => ['staging']]), $sessionId, 'no-such-turn');
        expect($miss->getStatusCode())->toBe(404);
        expect(json_decode((string) $miss->getBody(), true)['code'])->toBe('not_found');

        // The pending question for this turn resolves and is recorded answered.
        $resp = $handler->submitTurnAnswer(core48AnswerRequest(['selected' => ['staging'], 'text' => null]), $sessionId, $turnId);
        expect($resp->getStatusCode())->toBe(200);
        expect($storage->getQuestion($request->id)['status'])->toBe('answered');

        // Re-answering the now-resolved turn ⇒ 409 conflict.
        $again = $handler->submitTurnAnswer(core48AnswerRequest(['selected' => ['production']]), $sessionId, $turnId);
        expect($again->getStatusCode())->toBe(409);
        expect(json_decode((string) $again->getBody(), true)['code'])->toBe('conflict');

        // An invalid answer to a fresh pending question ⇒ 422 validation_error, stays pending.
        $turn2 = $storage->createTurnProcess($sessionId, 'ask again');
        $request2 = new QuestionRequest(
            id: '01J00000000000000000QCORE482',
            prompt: 'Deploy where?',
            format: QuestionFormat::SingleSelect,
            options: [new QuestionOption('staging'), new QuestionOption('production')],
            allowOther: false,
            suggested: new QuestionResponse(selected: ['staging']),
        );
        $storage->createQuestion($sessionId, $request2, 'suspending', $turn2);
        $invalid = $handler->submitTurnAnswer(core48AnswerRequest(['selected' => ['not-an-option']]), $sessionId, $turn2);
        expect($invalid->getStatusCode())->toBe(422);
        expect(json_decode((string) $invalid->getBody(), true)['code'])->toBe('validation_error');
        expect($storage->getQuestion($request2->id)['status'])->toBe('pending');

        // (c) The PRODUCTION write path. The cases above hand-seed questions.turn_id
        // via createQuestion; the runtime instead reaches it through
        // SuspendingQuestionResponder, which must persist its `turn_processes` id
        // into questions.turn_id or submitTurnAnswer is a dead 404. Prove it end to
        // end: a real responder ask() resolved through the turn-scoped endpoint,
        // keyed purely on the turn id.
        $turn3 = $storage->createTurnProcess($sessionId, 'suspend me');
        $request3 = new QuestionRequest(
            id: '01J00000000000000000QCORE483',
            prompt: 'Deploy where?',
            format: QuestionFormat::SingleSelect,
            options: [new QuestionOption('staging'), new QuestionOption('production')],
            allowOther: false,
            suggested: new QuestionResponse(selected: ['staging']),
        );

        $answered = false;
        $responder = new SuspendingQuestionResponder(
            new QuestionPersistence($storage),
            $storage,
            $sessionId,
            $turn3,
            sleeper: function () use (&$answered, $handler, $sessionId, $turn3): void {
                if ($answered) {
                    return;
                }
                $answered = true;
                // Answer the way a client does: keyed only on the turn id, which
                // resolves ONLY if the responder wrote questions.turn_id. A 404
                // here (the bug) would leave ask() polling until it times out.
                $resolve = $handler->submitTurnAnswer(
                    core48AnswerRequest(['selected' => ['production'], 'text' => null]),
                    $sessionId,
                    $turn3,
                );
                expect($resolve->getStatusCode())->toBe(200);
            },
        );

        $result = $responder->ask($request3);
        expect($result->selected)->toBe(['production']);

        // The real path persisted the turn_processes id into questions.turn_id —
        // the exact column the false-green hand-seeded and the runtime left null.
        $row = $storage->getQuestion($request3->id);
        expect($row['turn_id'])->toBe($turn3);
        expect($row['status'])->toBe('answered');
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

it('CORE-4: the ApiErrorCode catalog is exactly the closed error.json code set', function () {
    $schema = json_decode(file_get_contents(__DIR__ . '/spec/schema/error.json'), true, flags: JSON_THROW_ON_ERROR);
    $catalog = $schema['properties']['code']['enum'];
    sort($catalog);
    $coqui = array_map(fn (ApiErrorCode $c) => $c->value, ApiErrorCode::cases());
    sort($coqui);
    // Exact set equality: complete (every catalog code exists) AND closed (no extras).
    expect($coqui)->toBe($catalog);
})->group('conformance');

it('CORE-40: every HTTP status documented in error-coverage.json is produced by some catalog code', function () {
    $coverage = json_decode(
        file_get_contents(__DIR__ . '/spec/conformance/error-coverage.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $documented = [];
    foreach ($coverage as $statuses) {
        foreach ($statuses as $s) {
            $documented[(int) $s] = true;
        }
    }
    // Every documented status is reachable from the closed catalog. 412 is the If-Match
    // precondition status emitted with the version_conflict code + a ?int status override.
    $reachable = [412 => true];
    foreach (ApiErrorCode::cases() as $c) {
        $reachable[$c->httpStatus()] = true;
    }
    foreach (array_keys($documented) as $status) {
        expect($reachable)->toHaveKey($status, "status {$status} has no catalog code");
    }
})->group('conformance');

/**
 * Build the real SessionHandler over a temp SQLite db + temp workspace. The CAP
 * PATCH write path needs no role/persona/group wiring, so a bare RoleResolver +
 * PersonaDiscovery satisfy the constructor.
 *
 * @return array{0: SessionHandler, 1: SessionStorage, 2: string, 3: string}
 */
function makeSessionHandler(): array
{
    $workspace = sys_get_temp_dir() . '/coqui-session-ws-' . bin2hex(random_bytes(8));
    mkdir($workspace, 0755, true);

    $dbPath = sys_get_temp_dir() . '/coqui-session-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    $handler = new SessionHandler(
        $storage,
        new RoleResolver(OpenClawConfig::fromArray([])),
        new PersonaDiscovery($workspace),
    );

    return [$handler, $storage, $dbPath, $workspace];
}

function sessionPatchRequest(string $id, array $body, ?int $ifMatch = null): \React\Http\Message\ServerRequest
{
    $headers = ['Content-Type' => 'application/json'];
    if ($ifMatch !== null) {
        $headers['If-Match'] = (string) $ifMatch;
    }

    return new \React\Http\Message\ServerRequest(
        'PATCH',
        '/api/v1/sessions/' . $id,
        $headers,
        json_encode($body) ?: '',
    );
}

it('CORE-54: session PATCH clears model, sets workspace, and rejects an empty body', function () {
    [$handler, $storage, $dbPath, $ws] = makeSessionHandler();
    try {
        $id = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', null, workspace: '/work/old');
        $clear = $handler->update(sessionPatchRequest($id, ['model' => null]), $id);       // clear→inherit
        expect($clear->getStatusCode())->toBe(200);
        expect($storage->getSession($id)['model'])->toBeNull();
        $work = $handler->update(sessionPatchRequest($id, ['workspace' => '/work/x']), $id);
        expect($work->getStatusCode())->toBe(200);
        expect($storage->getSession($id)['workspace'])->toBe('/work/x');
        $empty = $handler->update(sessionPatchRequest($id, []), $id);
        expect($empty->getStatusCode())->toBe(422);
    } finally {
        cleanupSqliteTestDb($dbPath);
        cleanupTestTree($ws);
    }
})->group('conformance');

it('CORE-53: a creator deduplicates on a repeated Idempotency-Key, minting the resource once', function () {
    $ws = sys_get_temp_dir() . '/coqui-core53-ws-' . bin2hex(random_bytes(8));
    mkdir($ws, 0755, true);
    $dbPath = sys_get_temp_dir() . '/coqui-core53-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    // A role-aware config so the real creator returns 201 (not a role-validation 400).
    $config = OpenClawConfig::fromArray([
        'agents' => ['defaults' => [
            'model' => ['primary' => 'ollama/qwen3:latest'],
            'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
        ]],
    ]);
    $handler = new SessionHandler($storage, new RoleResolver($config), new PersonaDiscovery($ws));
    try {
        $middleware = new IdempotencyMiddleware(
            new IdempotencyStore($storage->getPdo()),
            [['method' => 'POST', 'path' => '/api/v1/sessions']],
        );

        $countSessions = static fn (): int
            => (int) $storage->getPdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn();

        // A real creator behind the middleware: POST /sessions mints a session row.
        $create = static fn (\Psr\Http\Message\ServerRequestInterface $req): \React\Http\Message\Response
            => $handler->create($req);

        $withKey = static fn (): \React\Http\Message\ServerRequest => new \React\Http\Message\ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k-1'],
            json_encode(['model_role' => 'orchestrator']) ?: '',
        );

        $first = $middleware($withKey(), $create);
        $second = $middleware($withKey(), $create);

        // The side-effect happened exactly once.
        expect($countSessions())->toBe(1);
        // The repeated request replays the first response verbatim.
        expect($first->getStatusCode())->toBe(201);
        expect($second->getStatusCode())->toBe($first->getStatusCode());
        expect((string) $second->getBody())->toBe((string) $first->getBody());

        // No header ⇒ the handler runs again and a second resource is minted.
        $noHeader = new \React\Http\Message\ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode(['model_role' => 'orchestrator']) ?: '',
        );
        $middleware($noHeader, $create);
        expect($countSessions())->toBe(2);

        // A different key ⇒ the handler runs again.
        $otherKey = new \React\Http\Message\ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k-2'],
            json_encode(['model_role' => 'orchestrator']) ?: '',
        );
        $middleware($otherKey, $create);
        expect($countSessions())->toBe(3);
    } finally {
        cleanupSqliteTestDb($dbPath);
        cleanupTestTree($ws);
    }
})->group('conformance');

it('CORE-10: a stale If-Match session write is rejected 409 version_conflict', function () {
    [$handler, $storage, $dbPath, $ws] = makeSessionHandler();
    try {
        $id = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', null);
        expect($storage->getSession($id)['version'])->toBe(1);
        $ok = $handler->update(sessionPatchRequest($id, ['title' => 'a'], ifMatch: 1), $id); // fresh
        expect($ok->getStatusCode())->toBe(200);
        expect($storage->getSession($id)['version'])->toBe(2);
        $stale = $handler->update(sessionPatchRequest($id, ['title' => 'b'], ifMatch: 1), $id); // stale
        expect($stale->getStatusCode())->toBe(409);
        expect(json_decode((string) $stale->getBody(), true)['code'])->toBe('version_conflict');
        expect(ApiErrorCode::tryFrom('version_conflict'))->not->toBeNull();
    } finally {
        cleanupSqliteTestDb($dbPath);
        cleanupTestTree($ws);
    }
})->group('conformance');

it('CORE-6: the loop live snapshot is fully typed', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core6-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $loopStore = new LoopStore($storage->getPdo());

        $loopId = $loopStore->createLoop(
            definitionName: 'review-cycle',
            goal: 'Ship the typed live snapshot.',
            configuration: ['roles' => ['researcher', 'implementer']],
            maxIterations: 10,
        );
        $iterationId = $loopStore->createIteration($loopId, 1);
        $stage0 = $loopStore->createStage($iterationId, 0, 'researcher');
        $stage1 = $loopStore->createStage($iterationId, 1, 'implementer');
        $loopStore->updateStage($stage0, 'completed', resultSummary: 'Gathered prior art.');
        $loopStore->updateStage($stage1, 'running');
        $loopStore->updateLoopProgress($loopId, 1, 1);

        $wire = (new LoopLiveProducer($loopStore))->toWire($loopId);

        $v = new ConformanceValidator();
        expect($v->isValid('loop-live.json', $wire))->toBeTrue($v->errorText('loop-live.json', $wire));
        expect($wire)->toHaveKeys(['loop_id', 'status', 'current_iteration', 'current_stage', 'budget', 'stages']);
        expect($wire['stages'])->toHaveCount(2);
        // Internal 'completed' maps to the CAP stage status 'done'.
        expect($wire['stages'][0]['status'])->toBe('done');
        expect($wire['stages'][1]['status'])->toBe('running');
        expect($wire['budget']['max_iterations'])->toBe(10);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-7: verdict is typed and approval requires both flags with no Critical/Important', function () {
    $v = new ConformanceValidator();

    $approved = Verdict::fromFindings(requirementsMet: true, qualityPass: true, findings: [
        new StageFinding(StageSeverity::Minor, 'nit'),
    ]);
    expect($v->isValid('verdict.json', $approved->toWire()))->toBeTrue($v->errorText('verdict.json', $approved->toWire()));
    expect($approved->isApproved())->toBeTrue();

    // Both flags true but a Critical finding blocks approval.
    $blocked = Verdict::fromFindings(true, true, [new StageFinding(StageSeverity::Critical, 'boom')]);
    expect($v->isValid('verdict.json', $blocked->toWire()))->toBeTrue($v->errorText('verdict.json', $blocked->toWire()));
    expect($blocked->isApproved())->toBeFalse();

    // An Important finding also blocks approval.
    expect(Verdict::fromFindings(true, true, [new StageFinding(StageSeverity::Important, 'gap')])->isApproved())->toBeFalse();

    // A false flag blocks approval even with no findings.
    $noFindings = Verdict::fromFindings(true, false, []);
    expect($v->isValid('verdict.json', $noFindings->toWire()))->toBeTrue($v->errorText('verdict.json', $noFindings->toWire()));
    expect($noFindings->toWire()['findings'])->toBe([]);
    expect(Verdict::fromFindings(false, true, [])->isApproved())->toBeFalse();
    expect(Verdict::fromFindings(true, false, [])->isApproved())->toBeFalse();
})->group('conformance');

it('CORE-11: instances expose a typed model catalog (id, context_window, tokenizer_hint)', function () {
    $v = new ConformanceValidator();
    $hints = ['o200k_base', 'cl100k_base', 'claude', 'heuristic-3.5', 'heuristic-4', 'unknown'];
    $caps = ['tools', 'vision', 'thinking'];

    // A fully-capable Anthropic model: all three capabilities, claude tokenizer.
    $opus = new ModelDefinition(
        id: 'claude-opus-4',
        name: 'Claude Opus 4',
        provider: 'anthropic',
        contextWindow: 200000,
        maxTokens: 32000,
        family: 'claude',
        toolCalls: true,
        vision: true,
        thinking: true,
    );
    $wire = ModelProducer::toWire($opus);

    expect($v->isValid('model.json', $wire))->toBeTrue($v->errorText('model.json', $wire));
    expect($wire['id'])->toBe('anthropic/claude-opus-4');
    expect($wire['context_window'])->toBeInt()->toBeGreaterThanOrEqual(1);
    expect($hints)->toContain($wire['tokenizer_hint']);
    expect($wire['tokenizer_hint'])->toBe('claude');
    expect($wire['capabilities'])->toBe(['tools', 'vision', 'thinking']);
    foreach ($wire['capabilities'] as $capability) {
        expect($caps)->toContain($capability);
    }

    // An OpenAI gpt-4o model: no capabilities, absent max_output → null, o200k hint.
    $gpt4o = new ModelDefinition(
        id: 'gpt-4o',
        name: 'GPT-4o',
        provider: 'openai',
        contextWindow: 128000,
        maxTokens: 0,
        family: 'gpt-4o',
    );
    $gpt4oWire = ModelProducer::toWire($gpt4o);
    expect($v->isValid('model.json', $gpt4oWire))->toBeTrue($v->errorText('model.json', $gpt4oWire));
    expect($gpt4oWire['tokenizer_hint'])->toBe('o200k_base');
    expect($gpt4oWire['max_output_tokens'])->toBeNull();
    expect($gpt4oWire['capabilities'])->toBe([]);

    // Older OpenAI (gpt-4) tokenizes with cl100k_base.
    $gpt4 = new ModelDefinition(id: 'gpt-4', name: 'GPT-4', provider: 'openai', family: 'gpt-4', toolCalls: true);
    $gpt4Wire = ModelProducer::toWire($gpt4);
    expect($v->isValid('model.json', $gpt4Wire))->toBeTrue($v->errorText('model.json', $gpt4Wire));
    expect($gpt4Wire['tokenizer_hint'])->toBe('cl100k_base');
    expect($gpt4Wire['capabilities'])->toBe(['tools']);

    // An unrecognized provider/family defaults to the conservative unknown hint.
    $llama = new ModelDefinition(id: 'llama3', name: 'Llama 3', provider: 'ollama', family: 'llama');
    $llamaWire = ModelProducer::toWire($llama);
    expect($v->isValid('model.json', $llamaWire))->toBeTrue($v->errorText('model.json', $llamaWire));
    expect($llamaWire['tokenizer_hint'])->toBe('unknown');
})->group('conformance');

/**
 * Build a real FileUploadHandler over a temp SQLite db + temp workspace.
 *
 * @return array{0: FileUploadHandler, 1: SessionStorage, 2: string, 3: string}
 */
function makeFileUploadHandler(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-core44-' . bin2hex(random_bytes(8)) . '.db';
    $ws = sys_get_temp_dir() . '/coqui-core44-ws-' . bin2hex(random_bytes(8));
    mkdir($ws, 0755, true);

    $storage = new SessionStorage($dbPath);
    $handler = new FileUploadHandler($storage, new FileUploadStorage());

    return [$handler, $storage, $dbPath, $ws];
}

/** A raw-binary (non-multipart) content upload request. */
function binaryUploadRequest(string $sid, string $bytes, string $mime): \React\Http\Message\ServerRequest
{
    return new \React\Http\Message\ServerRequest(
        'POST',
        '/api/v1/sessions/' . $sid . '/files',
        ['Content-Type' => $mime],
        $bytes,
    );
}

/** A content download request, optionally carrying a Range header. */
function rangeGetRequest(string $sid, string $ref, ?string $range): \React\Http\Message\ServerRequest
{
    return new \React\Http\Message\ServerRequest(
        'GET',
        '/api/v1/sessions/' . $sid . '/files/' . $ref,
        $range !== null ? ['Range' => $range] : [],
    );
}

it('CORE-44: content upload returns a typed content object; download honors Range and 404s a missing ref', function () {
    [$handler, $storage, $dbPath, $ws] = makeFileUploadHandler();
    try {
        $sid = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', null);
        $up = $handler->upload(binaryUploadRequest($sid, 'hello world', 'text/plain'));
        $obj = json_decode((string) $up->getBody(), true);
        $v = new ConformanceValidator();
        expect($v->isValid('content.json', $obj))->toBeTrue($v->errorText('content.json', $obj));
        // Range download → 206 with the requested slice.
        $part = $handler->get(rangeGetRequest($sid, $obj['content_ref'], 'bytes=0-4'));
        expect($part->getStatusCode())->toBe(206);
        expect((string) $part->getBody())->toBe('hello');
        expect($part->getHeaderLine('Content-Range'))->toStartWith('bytes 0-4/');
        expect($part->getHeaderLine('Accept-Ranges'))->toBe('bytes');
        // Missing ref → content_not_found.
        $missing = $handler->get(rangeGetRequest($sid, 'nope', null));
        expect($missing->getStatusCode())->toBe(404);
        expect(json_decode((string) $missing->getBody(), true)['code'])->toBe('content_not_found');
    } finally {
        cleanupSqliteTestDb($dbPath);
        cleanupTestTree($ws);
    }
})->group('conformance');

it('CORE-45: the export envelope types a content collection', function () {
    $map = new ExportCollectionMap();
    expect($map->has('content'))->toBeTrue();
    // A produced content row validates against content.json (element type of the export content[]).
    $dbPath = sys_get_temp_dir() . '/coqui-core45-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $store = new ContentStore($storage->getPdo());
        $wire = $store->store('bytes here', 'application/octet-stream');
        $v = new ConformanceValidator();
        expect($v->isValid('content.json', $wire))->toBeTrue($v->errorText('content.json', $wire));
        // The typed producer projects the same row identically.
        $produced = ContentProducer::toWire($wire);
        expect($v->isValid('content.json', $produced))->toBeTrue($v->errorText('content.json', $produced));
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-52: an SSE frame id is an opaque string cursor that sorts lexicographically', function () {
    // coqui encodes rowids so string order == numeric order.
    expect(SseCursor::encode(9) < SseCursor::encode(10))->toBeTrue();
    expect(SseCursor::decode(SseCursor::encode(4242)))->toBe(4242);
    $v = new ConformanceValidator();
    // A frame coqui would emit validates; the numeric-id shape is rejected by sse-frame.json.
    expect($v->isValid('sse-frame.json', ['id' => SseCursor::encode(42), 'text' => 'Hello']))->toBeTrue();
    expect($v->isValid('sse-frame.json', ['id' => 42, 'text' => 'Hello']))->toBeFalse();
})->group('conformance');

it('CORE-51: turn SSE frames are emitted only in the closed per-channel event set', function () {
    $v = new ConformanceValidator();
    // The turn stream's terminal frame is `done` — a full turn.json record,
    // projected by the real producer — never the legacy `complete`.
    $turnRecord = TurnHandler::toWire([
        'id' => '01J00000000000000000TURN01',
        'session_id' => '01J0000000000000000SESSION',
        'turn_number' => 1,
        'user_prompt' => 'Summarize the conformance vector suite.',
        'response_text' => 'The suite validates canonical Core objects against their schemas.',
        'model' => 'anthropic/claude-sonnet-4',
        'prompt_tokens' => 42,
        'completion_tokens' => 18,
        'total_tokens' => 60,
        'iterations' => 1,
        'duration_ms' => 1200,
        'tools_used' => [],
        'status' => 'completed',
        'created_at' => '2026-07-28T00:00:00Z',
        'completed_at' => '2026-07-28T00:00:02Z',
    ]);
    $frame = MessageHandler::buildTurnEventFrame('done', $turnRecord, SseCursor::encode(7)); // small pure builder
    expect($v->isValid('sse-turn-event.json', $frame))->toBeTrue($v->errorText('sse-turn-event.json', $frame));
    expect(in_array($frame['event'], ['token', 'message', 'tool_call', 'tool_result', 'question', 'done', 'error'], true))->toBeTrue();
    // An unknown event shape (name outside the closed set) is rejected.
    $unknown = MessageHandler::buildTurnEventFrame('reasoning', ['content' => 'thinking'], SseCursor::encode(8));
    expect($v->isValid('sse-turn-event.json', $unknown))->toBeFalse();
})->group('conformance');

it('CORE-5: a turn stream reconnect replays only events after the Last-Event-ID cursor', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core5-' . bin2hex(random_bytes(8)) . '.db';
    try {
        $storage = new SessionStorage($dbPath);
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $turnProcessId = $storage->createTurnProcess($sessionId, 'stream me');
        // Seed 3 turn_events — a fresh db AUTOINCREMENTs their ids to 1, 2, 3.
        $storage->appendTurnEvent($turnProcessId, 'text_delta', ['content' => 'a']);
        $storage->appendTurnEvent($turnProcessId, 'text_delta', ['content' => 'b']);
        $storage->appendTurnEvent($turnProcessId, 'text_delta', ['content' => 'c']);

        // A reconnect echoes the transport cursor as a Last-Event-ID header (the
        // encoded string form of rowid 2); the resolver decodes it to the numeric
        // rowid used to filter the replay.
        $after = MessageHandler::resolveReplayCursor(sseReconnectRequest(lastEventId: SseCursor::encode(2)));
        expect($after)->toBe(2);

        $replayed = $storage->getTurnEvents($turnProcessId, sinceId: $after);
        expect(array_map(static fn(array $e): int => (int) $e['id'], $replayed))->toBe([3]);

        // Precedence: header wins over ?since, which wins over legacy ?since_id.
        expect(MessageHandler::resolveReplayCursor(
            sseReconnectRequest(lastEventId: SseCursor::encode(2), since: SseCursor::encode(1), sinceId: '0'),
        ))->toBe(2);
        expect(MessageHandler::resolveReplayCursor(
            sseReconnectRequest(since: SseCursor::encode(1), sinceId: '0'),
        ))->toBe(1);
        expect(MessageHandler::resolveReplayCursor(sseReconnectRequest(sinceId: '3')))->toBe(3);
        // A fresh connection (no cursor) replays from the beginning.
        expect(MessageHandler::resolveReplayCursor(sseReconnectRequest()))->toBeNull();
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-41: an SSE error frame carries a code from the closed catalog', function () {
    $frame = MessageHandler::buildErrorFrame(ApiErrorCode::INTERNAL_ERROR, 'the turn crashed', SseCursor::encode(42));
    $v = new ConformanceValidator();
    expect($v->isValid('sse-error.json', $frame))->toBeTrue($v->errorText('sse-error.json', $frame));
    expect($frame['data']['code'])->toBe('internal_error');
    expect(ApiErrorCode::tryFrom($frame['data']['code']))->not->toBeNull();
    // A code outside the closed catalog is rejected by the schema.
    $bad = $frame;
    $bad['data']['code'] = 'kaboom';
    expect($v->isValid('sse-error.json', $bad))->toBeFalse();
})->group('conformance');

/**
 * Build a real InstanceInfoBuilder with the knobs the CORE-30/31/35/36/39/46
 * flips need: persona_count sourced from a live PersonaDiscovery over a temp
 * workspace (no files ⇒ 0, and nothing is written, so no cleanup), one native
 * host toolkit, bearer auth, typed limits/api/builtin_toolkits, and optional
 * mcp / profile_versions / an extra (unknown) profile.
 *
 * @param array<string, string> $profileVersions
 */
function makeInstanceInfoBuilder(bool $withMcp = false, array $profileVersions = [], ?string $extraProfile = null): InstanceInfoBuilder
{
    // Live source for persona_count over a temp workspace (missing ⇒ empty).
    $personaCount = count(
        (new PersonaDiscovery(sys_get_temp_dir() . '/coqui-iinfo-' . bin2hex(random_bytes(6))))->discoverAll(),
    );

    $profiles = ['artifacts', 'questions', 'skills', 'schedules'];
    if ($withMcp) {
        $profiles[] = 'mcp';
    }
    foreach (array_keys($profileVersions) as $versionedProfile) {
        $profiles[] = $versionedProfile; // profile_versions keys are profiles too
    }
    if ($extraProfile !== null) {
        $profiles[] = $extraProfile; // OPEN set: never allowlist-filtered
    }

    return new InstanceInfoBuilder(
        profiles: array_values($profiles),
        bindings: ['in-process', 'http-sse'],
        personaCount: $personaCount,
        hostToolkits: [
            ['namespace' => 'images', 'description' => 'native image understanding/generation', 'tools' => ['describe', 'generate']],
        ],
        builtinToolkits: ['shell', 'fs', 'web'],
        mcpTransports: $withMcp ? ['stdio', 'http'] : null,
        profileVersions: $profileVersions,
        authRequired: true, // bearer auth
        limits: ['max_page_size' => 100, 'max_payload_bytes' => 10_485_760, 'max_content_bytes' => 104_857_600],
        api: ['base_path' => '/api/v1', 'api_major' => '1'],
    );
}

it('CORE-30/46: InstanceInfo is typed, declares host_toolkits, and types auth/limits/api/builtin_toolkits', function () {
    $info = makeInstanceInfoBuilder()->build(); // over a temp workspace with one host toolkit + bearer auth
    $v = new ConformanceValidator();
    expect($v->isValid('instance-info.json', $info))->toBeTrue($v->errorText('instance-info.json', $info));
    expect($info)->toHaveKeys(['protocol_version', 'profiles', 'bindings']);
    // host_toolkits are declared (native, non-portable), distinct from builtin_toolkits.
    expect($info['host_toolkits'][0]['namespace'])->toBe('images');
    expect($info['builtin_toolkits'])->toContain('shell');
    // api + limits are typed.
    expect($info['api'])->toHaveKeys(['base_path', 'api_major']);
    expect($info['limits'])->toHaveKeys(['max_page_size', 'max_payload_bytes', 'max_content_bytes']);
    expect($info['bindings'])->each->toBeIn(['in-process', 'http-sse']);        // closed set
    if (isset($info['auth'])) {
        expect($info['auth']['scheme'])->toBe('bearer'); // closed scheme
    }
})->group('conformance');

it('CORE-31: InstanceInfo mcp.transports is a closed set', function () {
    $info = makeInstanceInfoBuilder(withMcp: true)->build();
    $v = new ConformanceValidator();
    expect($v->isValid('instance-info.json', $info))->toBeTrue($v->errorText('instance-info.json', $info));
    expect($info['mcp']['transports'])->each->toBeIn(['stdio', 'http']);
})->group('conformance');

it('CORE-35: InstanceInfo profile_versions use semver', function () {
    $info = makeInstanceInfoBuilder(profileVersions: ['mcp' => '0.3.0'])->build();
    expect((new ConformanceValidator())->isValid('instance-info.json', $info))->toBeTrue();
    expect($info['profile_versions']['mcp'])->toBe('0.3.0');
})->group('conformance');

/**
 * Build a schema-valid InstanceInfo whose portable built-in toolkit list is the
 * argument — production-representative (mirrors ApiCommand's wiring), varying
 * only the builtin_toolkits so CORE-32 can assert `vision` is declared.
 *
 * @param list<string> $builtins
 * @return array<string, mixed>
 */
function instanceInfoWithBuiltins(array $builtins): array
{
    return (new InstanceInfoBuilder(
        profiles: ['artifacts', 'questions', 'skills', 'schedules', 'mcp'],
        bindings: ['in-process', 'http-sse'],
        builtinToolkits: $builtins,
        mcpTransports: ['stdio'],
        authRequired: true,
        limits: ['max_page_size' => 100, 'max_payload_bytes' => 10_485_760, 'max_content_bytes' => 104_857_600],
        api: ['base_path' => '/api/v1', 'api_major' => '1'],
    ))->build();
}

it('CORE-32: vision is a declared access-gated built-in; generation is extension-only', function () {
    $v = new ConformanceValidator();
    $info = instanceInfoWithBuiltins(['shell', 'fs', 'web', 'vision']);
    expect($v->isValid('instance-info.json', $info))->toBeTrue($v->errorText('instance-info.json', $info));
    // vision (image understanding) is a portable, access-gated built-in.
    expect($info['builtin_toolkits'])->toContain('vision');
    // generation is NOT a built-in — it is extension-only (absent from core).
    expect($info['builtin_toolkits'])->not->toContain('image_generation');
    expect($info['builtin_toolkits'])->not->toContain('generate_image');
    // "access-gated" means vision is reachable at a non-full access level:
    // `readonly` is a valid access level in the source-of-truth constant, and
    // VisionTool (vision_analyze) is read-safe, so it survives read-only gating.
    $accessLevels = (new \ReflectionClass(RoleParser::class))->getConstant('VALID_ACCESS_LEVELS');
    expect($accessLevels)->toContain('readonly');
})->group('conformance');

it('CORE-36/39: profiles is an OPEN set — an unknown profile still validates and is not rejected', function () {
    // The vendored schema puts no enum on profiles.items; an unknown profile MUST validate (CORE-39),
    // and coqui's discovery must not reject it (CORE-36 forward tolerance).
    $info = makeInstanceInfoBuilder(extraProfile: 'telepathy')->build();
    $v = new ConformanceValidator();
    expect($v->isValid('instance-info.json', $info))->toBeTrue($v->errorText('instance-info.json', $info));
    expect($info['profiles'])->toContain('telepathy');

    // The vendored future-profile vector is the same open-set contract, validated directly.
    $vector = json_decode(
        (string) file_get_contents(__DIR__ . '/spec/conformance/vectors/valid/instance-info.future-profile.json'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );
    expect($v->isValid('instance-info.json', $vector))->toBeTrue($v->errorText('instance-info.json', $vector));
})->group('conformance');

it('CORE-57: an in-process thrown error is typed with a code from the closed catalog', function () {
    $v = new ConformanceValidator();
    $thrown = (new RequestBodyException(ApiErrorCode::NOT_FOUND, 'No such persona', ['id' => 'p_missing']))->toThrownError();
    expect($v->isValid('error-thrown.json', $thrown))->toBeTrue($v->errorText('error-thrown.json', $thrown));
    expect($thrown['code'])->toBe('not_found');
    expect(ApiErrorCode::tryFrom($thrown['code']))->not->toBeNull();
    // An off-catalog code is rejected by the schema (mirrors invalid/error-thrown.bad-code.json).
    $bad = $thrown;
    $bad['code'] = 'kaboom';
    expect($v->isValid('error-thrown.json', $bad))->toBeFalse();
})->group('conformance');

/**
 * A complete, valid session DB row as {@see SessionStorage::getSession()} returns
 * it — every column {@see SessionHandler::toWire()} reads is present. The three
 * enum-bearing inputs are overridable so a single row shape drives the whole
 * (is_archived, is_closed) status matrix and the kind-coercion checks.
 *
 * @return array<string, mixed>
 */
function sessionRow(int $isArchived = 0, int $isClosed = 0, string $kind = 'chat'): array
{
    return [
        'id' => '01J0000000000000000SESSION',
        'persona_id' => '01J000000000000000000PERSONA',
        'group_members' => [],
        'kind' => $kind,
        'is_archived' => $isArchived,
        'is_closed' => $isClosed,
        'pinned' => false,
        'version' => 1,
        'model' => 'anthropic/claude-sonnet-4',
        'workspace' => null,
        'title' => 'Release planning',
        'token_count' => 0,
        'created_at' => '2026-07-28T00:00:00Z',
        'updated_at' => '2026-07-28T00:00:00Z',
    ];
}

it('CORE-2: session enums are closed — out-of-set status/kind never produced and rejected', function () {
    $v = new ConformanceValidator();
    // (a) schema-reject: the vendored bad-status vector is rejected.
    $bad = json_decode((string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/session.bad-status.json'), true);
    expect($v->isValid('session.json', $bad))->toBeFalse();

    // (b) producer-total: over every (is_archived,is_closed) combination, status stays in-set.
    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$arch, $closed]) {
        $wire = SessionHandler::toWire(sessionRow(isArchived: $arch, isClosed: $closed, kind: 'chat'));
        expect($wire['status'])->toBeIn(['active', 'archived', 'closed']);
    }
    // (c) kind is pinned: a garbage stored kind is coerced into the closed set, never leaked.
    $wire = SessionHandler::toWire(sessionRow(kind: 'wat'));
    expect($wire['kind'])->toBeIn(['chat', 'loop_workscope']);
    expect($v->isValid('session.json', SessionHandler::toWire(sessionRow(kind: 'loop_workscope'))))
        ->toBeTrue();
})->group('conformance');

/**
 * A realistic composition-ordered budget snapshot (Budgeting.md §1): pinned
 * critical/workflow sections compose first, the deferrable volatile section
 * (memory) composes last and is shed under budget pressure. The producer
 * preserves composition order (schema/budget-breakdown.json: "in composition
 * order"), so this order is exactly what a real over-budget prompt yields — and
 * because composition order runs pinned-first, the priority field comes out
 * non-decreasing, making the shed order inspectable.
 */
function budgetSnapshotWithShed(): PromptBudgetSnapshot
{
    $section = static fn (string $id, string $priority, bool $included, int $tokens, string $decision): array => [
        'id' => $id,
        'title' => ucfirst($id),
        'group' => 'system',
        'priority' => $priority,
        'pinned' => $priority !== 'volatile',
        'deferrable' => $priority === 'volatile',
        'included' => $included,
        'decision' => $decision,
        'rationale' => '',
        'source' => null,
        'tokens' => $tokens,
    ];

    return new PromptBudgetSnapshot(
        role: 'orchestrator',
        model: 'ollama/qwen3:latest',
        toolCount: 0,
        toolkitCount: 0,
        promptTokens: 150,
        toolTokens: 0,
        totalTokens: 150,
        toolkitBreakdown: [],
        promptSections: [
            $section('security', 'critical', true, 100, 'included'),
            $section('workflow', 'workflow', true, 50, 'included'),
            // Shed under budget pressure: excluded, carries a reason.
            $section('memory', 'volatile', false, 40, 'memory_cap'),
        ],
        appliedLoadingModes: [],
        loadingDecisions: [],
        deferredToolkits: [],
        toolkitBudget: [
            'effective_role' => 'orchestrator',
            'budget_tokens' => 0,
            'budget_source' => 'default',
            'promotion_budget_percent' => 0,
            'promotion_budget_source' => 'default',
            'promotion_budget_tokens' => 0,
            'auto_candidate_count' => 0,
            'auto_candidate_tokens' => 0,
            'used_promotion_budget_tokens' => 0,
            'within_budget' => false,
            'deferred_count' => 0,
        ],
        contextWindow: new ContextUsageSnapshot(
            maxTokens: 8192,
            reservedTokens: 0,
            usedTokens: 150,
            usagePercent: 1.83,
            breakdown: [],
        ),
    );
}

it('CORE-12: budget breakdown exposes priority tiers and shed order inspectably', function () {
    $v = new ConformanceValidator();
    $wire = (new BudgetBreakdownProducer())->toWire(budgetSnapshotWithShed());
    expect($v->isValid('budget-breakdown.json', $wire))->toBeTrue($v->errorText('budget-breakdown.json', $wire));

    // pinned/security ranks first (lowest priority int).
    $priorities = array_column($wire['sections'], 'priority');
    expect(min($priorities))->toBe(0);

    // a shed section is inspectable: excluded, carries a reason.
    $shed = array_values(array_filter($wire['sections'], static fn (array $s): bool => $s['included'] === false));
    expect($shed)->not->toBeEmpty();
    expect($shed[0]['shed_reason'])->toBeString();

    // composition order runs pinned-first, so priorities are non-decreasing —
    // the actual shed order is legible from the wire.
    $sorted = $priorities;
    sort($sorted);
    expect($priorities)->toBe($sorted);
})->group('conformance');

/**
 * Build a raw `questions` table row (id, session_id, turn_id, loop_id, stage_id,
 * responder_kind, request, answer, status, created_at, answered_at), serializing
 * `request`/`answer` as JSON exactly as SessionStorage does. The stored `request`
 * carries the authoring shape (prompt/format/options/allow_other/suggested); the
 * `answer` column is the coqui `{selected, text}` object (or null when unanswered).
 *
 * @param list<array<string, mixed>> $options   Authoring options as {label, description?}.
 * @param array{selected?: list<string>, text?: ?string}|null $answer
 * @param array{selected?: list<string>, text?: ?string}|null $suggested
 * @return array<string, mixed>
 */
function questionRow(
    string $format = 'text',
    array $options = [],
    string $status = 'pending',
    ?array $answer = null,
    ?array $suggested = null,
    string $id = '01J000000000000000000QCORE49',
    string $sessionId = '01J000000000000000000WS049',
    string $prompt = 'Which toppings would you like?',
    bool $allowOther = false,
    string $createdAt = '2026-07-28T12:05:00Z',
    ?string $answeredAt = null,
): array {
    $request = [
        'id' => $id,
        'prompt' => $prompt,
        'format' => $format,
        'options' => $options,
        'allow_other' => $allowOther,
        'suggested' => $suggested ?? ['selected' => [], 'text' => null],
        'header' => null,
    ];

    return [
        'id' => $id,
        'session_id' => $sessionId,
        'turn_id' => null,
        'loop_id' => null,
        'stage_id' => null,
        'responder_kind' => 'interactive',
        'request' => json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'answer' => $answer === null ? null : json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'status' => $status,
        'created_at' => $createdAt,
        'answered_at' => $answeredAt,
    ];
}

it('CORE-49: question wire is rich (multi-select) with a typed {value,label} option shape', function () {
    $v = new ConformanceValidator();
    // A coqui multi_select question row projects to a valid question.json with typed options.
    $wire = QuestionPersistence::toWire(questionRow(
        format: 'multi_select',
        options: [['label' => 'cheese', 'description' => 'Cheddar'], ['label' => 'mushroom']],
        status: 'pending',                     // stored form
        answer: ['selected' => ['cheese', 'mushroom'], 'text' => null],
    ));
    expect($v->isValid('question.json', $wire))->toBeTrue($v->errorText('question.json', $wire));
    expect($wire['format'])->toBe('multi_select');
    expect($wire['status'])->toBe('open');                 // pending → open
    expect($wire['options'][0])->toMatchArray(['value' => 'cheese']);   // value is required + present
    expect($wire['answer'])->toBe(['cheese', 'mushroom']); // multi_select ⇒ array answer

    // The vendored malformed-option vector (option without value) is rejected.
    $bad = json_decode((string) file_get_contents(__DIR__ . '/spec/conformance/vectors/invalid/question.malformed-option.json'), true);
    expect($v->isValid('question.json', $bad))->toBeFalse();
})->group('conformance');

$rows = [
    // 0.4 binding-interop MUSTs (CORE-36..CORE-59).
    'CORE-47: x-persona operations map cleanly across both bindings (HTTP + in_process)',
    'CORE-56: import supports mode=preserve|remap; remap atomically rewrites every FK',
    'CORE-58: single-vs-list response cardinality agrees across in_process, operations.yaml, and openapi',
];

foreach ($rows as $row) {
    test($row)->todo();
}
