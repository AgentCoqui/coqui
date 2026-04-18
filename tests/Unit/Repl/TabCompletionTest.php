<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\CoquiSpace\Installer\ComposerRunner;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\SpaceRegistry;
use CoquiBot\Coqui\CoquiSpace\SpaceToolkit;
use CoquiBot\Coqui\Repl\ReplCommandCatalog;
use CoquiBot\Coqui\Repl\TabCompletion;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createTabCompletionFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-tab-completion-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    mkdir($workspacePath . '/roles', 0755, true);
    mkdir($workspacePath . '/skills/review-skill', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "# Caelum\n\nA calm companion.\n");
    copy(dirname(__DIR__, 3) . '/config/roles/coder.md', $workspacePath . '/roles/coder.md');
    file_put_contents($workspacePath . '/skills/review-skill/' . SpaceRegistry::ORIGIN_FILE, json_encode([
        'source' => 'coqui.space',
        'owner' => 'coquibot',
        'name' => 'review-skill',
        'version' => '1.2.3',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($workspacePath . '/composer.json', json_encode([
        'require' => [
            'coquibot/coqui-test-toolkit' => '^1.0',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());
    new ArtifactStore($storage->getPdo(), null, $projectStore);
    $todoStore = new TodoStore($storage->getPdo());
    $scheduleStore = new ScheduleStore($storage->getPdo());
    $loopStore = new LoopStore($storage->getPdo());

    $roleResolver = new RoleResolver(OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                    'coder' => 'ollama/qwen3-coder:latest',
                    'reviewer' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ]));

    $roleDiscovery = new RoleDiscovery($workspacePath, dirname(__DIR__, 3));
    $profileDiscovery = new ProfileDiscovery($workspacePath);
    $loopDiscovery = new LoopDiscovery($workspacePath, dirname(__DIR__, 3));
    $loopDiscovery->seedBuiltinLoops();
    $skillDiscovery = new SkillDiscovery($workspacePath);
    $skillDiscovery->ensureSkillsDir();
    $visibilityRegistry = new ToolkitVisibilityRegistry($workspacePath);
    $visibilityRegistry->setToolVisibility('shell', ToolkitVisibility::Stub);
    $discovery = new ToolkitDiscovery(dirname(__DIR__, 3), $workspacePath, null, $visibilityRegistry);
    $discovery->register('coquibot/coqui-test-toolkit', ['Acme\\ReviewToolkit']);

    $spaceSearchRequests = 0;
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$spaceSearchRequests): MockResponse {
        $spaceSearchRequests++;

        $query = (string) (($options['query']['q'] ?? ''));
        if ($method !== 'GET' || !str_ends_with((string) parse_url($url, PHP_URL_PATH), '/search/all')) {
            throw new RuntimeException('Unexpected Coqui Space request during completion test.');
        }

        if ($query !== 'coq') {
            throw new RuntimeException('Unexpected Coqui Space completion query: ' . $query);
        }

        return new MockResponse(json_encode([
            'skills' => [
                'results' => [
                    ['owner' => 'coquibot', 'name' => 'review-skill'],
                ],
            ],
            'toolkits' => [
                'results' => [
                    ['name' => 'coquibot/coqui-test-toolkit'],
                ],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    });

    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => '',
        $http,
    );
    $spaceToolkit = new SpaceToolkit(
        $client,
        new SkillInstaller($client, $skillDiscovery, $skillDiscovery->skillsDir()),
        new ToolkitInstaller($client, new ComposerRunner($workspacePath), $discovery, $workspacePath),
        static fn(): string => '',
    );

    $boot = testBootManagerForTabCompletion(
        workspacePath: $workspacePath,
        roleResolver: $roleResolver,
        roleDiscovery: $roleDiscovery,
        profileDiscovery: $profileDiscovery,
        todoStore: $todoStore,
        projectStore: $projectStore,
        loopDiscovery: $loopDiscovery,
        discovery: $discovery,
        visibilityRegistry: $visibilityRegistry,
        spaceToolkit: $spaceToolkit,
    );

    $sessionId = $storage->createSession('orchestrator', 'ollama/qwen3:latest');
    $todoId = $todoStore->create($sessionId, 'Review command help');
    $projectStore->createProject('Docs Cleanup', 'docs-cleanup');
    $scheduleId = $scheduleStore->create('nightly-review', '0 0 * * *', 'Run nightly review');
    $loopId = $loopStore->createLoop('harness', 'Keep REPL docs aligned', []);

    $pendingTaskId = $storage->createTask($sessionId, 'Pending review task');
    $completedTaskId = $storage->createTask($sessionId, 'Completed review task');
    $storage->updateTaskStatus($completedTaskId, 'completed');

    $otherSessionId = $storage->createSession('coder', 'ollama/qwen3-coder:latest');
    $resumeSessionId = $storage->createSession('reviewer', 'ollama/qwen3:latest');

    $completion = new TabCompletion($boot, $storage);
    $completion->setSessionId($sessionId);

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'completion' => $completion,
        'sessionId' => $sessionId,
        'otherSessionId' => $otherSessionId,
        'resumeSessionId' => $resumeSessionId,
        'todoId' => $todoId,
        'scheduleId' => $scheduleId,
        'loopId' => $loopId,
        'pendingTaskId' => $pendingTaskId,
        'completedTaskId' => $completedTaskId,
        'getSpaceSearchRequestCount' => static function () use (&$spaceSearchRequests): int {
            return $spaceSearchRequests;
        },
    ];
}

function cleanupTabCompletionFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

function testBootManagerForTabCompletion(
    string $workspacePath,
    RoleResolver $roleResolver,
    RoleDiscovery $roleDiscovery,
    ProfileDiscovery $profileDiscovery,
    TodoStore $todoStore,
    ProjectStore $projectStore,
    LoopDiscovery $loopDiscovery,
    ToolkitDiscovery $discovery,
    ToolkitVisibilityRegistry $visibilityRegistry,
    SpaceToolkit $spaceToolkit,
): BootManager {
    $reflection = new ReflectionClass(BootManager::class);
    /** @var BootManager $boot */
    $boot = $reflection->newInstanceWithoutConstructor();

    $initializer = function () use (
        $workspacePath,
        $roleResolver,
        $roleDiscovery,
        $profileDiscovery,
        $todoStore,
        $projectStore,
        $loopDiscovery,
        $discovery,
        $visibilityRegistry,
        $spaceToolkit,
    ): void {
        $this->workspacePath = $workspacePath;
        $this->roleResolver = $roleResolver;
        $this->roleDiscovery = $roleDiscovery;
        $this->profileDiscovery = $profileDiscovery;
        $this->todoStore = $todoStore;
        $this->projectStore = $projectStore;
        $this->loopDiscovery = $loopDiscovery;
        $this->discovery = $discovery;
        $this->visibilityRegistry = $visibilityRegistry;
        $this->spaceToolkit = $spaceToolkit;
    };

    \Closure::bind($initializer, $boot, BootManager::class)();

    return $boot;
}

test('top-level completion includes every catalog command and omits nested space pseudo commands', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        $suggestions = $fixture['completion']->complete('/');

        foreach (ReplCommandCatalog::topLevelCommands() as $command) {
            expect($suggestions)->toContain($command);
        }

        expect($suggestions)->not->toContain('/space skills');
        expect($suggestions)->not->toContain('/space toolkits');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('static command completion covers catalog argument hints', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        $cases = [
            '/config ' => ['show', 'edit'],
            '/tasks ' => ['all', 'pending', 'running', 'cancelling', 'completed', 'failed', 'cancelled'],
            '/todos ' => ['pending', 'in_progress', 'completed', 'cancelled', 'delete', 'complete', 'cancel', 'clear'],
            '/schedules ' => ['enable', 'disable', 'delete', 'trigger'],
            '/loops ' => ['start', 'definitions', 'defs', 'status', 'pause', 'resume', 'stop', 'running', 'paused', 'completed', 'failed', 'cancelled'],
            '/prompt ' => ['export'],
            '/summarize ' => ['recent', 'focus'],
            '/roles ' => ['list', 'update', 'ignore', 'unignore'],
            '/backstory ' => ['generate', 'failed'],
            '/space ' => ['status', 'search', 'install', 'remove', 'installed', 'skills', 'toolkits', 'update'],
            '/evaluations ' => ['A', 'B', 'C', 'D', 'F'],
            '/multiline ' => ['on', 'off'],
        ];

        foreach ($cases as $buffer => $expected) {
            $suggestions = $fixture['completion']->complete($buffer);
            foreach ($expected as $value) {
                expect($suggestions)->toContain($value);
            }
        }

        expect($fixture['completion']->complete('/summarize recent '))->toBe(['1', '3', '5', '10', '20']);
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('role, profile, session, task, and todo completion use live state', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/model c'))->toContain('coder');
        expect($fixture['completion']->complete('/budget c'))->toContain('coder');
        expect($fixture['completion']->complete('/role '))->toContain('coder');
        expect($fixture['completion']->complete('/role '))->toContain('reviewer');
        expect($fixture['completion']->complete('/role '))->toContain('orchestrator');
        expect($fixture['completion']->complete('/role '))->toContain('edit');
        expect($fixture['completion']->complete('/role edit '))->toContain('coder');
        expect($fixture['completion']->complete('/roles update '))->toContain('coder');
        expect($fixture['completion']->complete('/profile d'))->toContain('default');
        expect($fixture['completion']->complete('/profile '))->toContain('caelum');
        expect($fixture['completion']->complete('/profile default '))->toContain('caelum');
        expect($fixture['completion']->complete('/profile default '))->toContain('none');
        expect($fixture['completion']->complete('/profile default '))->toContain('clear');
        expect($fixture['completion']->complete('/resume '))->toContain($fixture['otherSessionId']);
        expect($fixture['completion']->complete('/resume '))->toContain($fixture['resumeSessionId']);
        expect($fixture['completion']->complete('/task '))->toContain($fixture['pendingTaskId']);
        expect($fixture['completion']->complete('/task '))->toContain($fixture['completedTaskId']);
        expect($fixture['completion']->complete('/task-cancel '))->toContain($fixture['pendingTaskId']);
        expect($fixture['completion']->complete('/task-cancel '))->not->toContain($fixture['completedTaskId']);
        expect($fixture['completion']->complete('/todos complete '))->toContain('all');
        expect($fixture['completion']->complete('/todos complete '))->toContain($fixture['todoId']);
        expect($fixture['completion']->complete('/todos cancel '))->toContain($fixture['todoId']);
        expect($fixture['completion']->complete('/todos cancel '))->not->toContain('all');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('project, schedule, and loop completion cover filters and live identifiers', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/projects '))->toContain('clear');
        expect($fixture['completion']->complete('/projects '))->toContain('docs-cleanup');
        expect($fixture['completion']->complete('/sprints d'))->toContain('docs-cleanup');
        expect($fixture['completion']->complete('/loops st'))->toContain('start');
        expect($fixture['completion']->complete('/loops st'))->toContain('status');
        expect($fixture['completion']->complete('/loops st'))->toContain('stop');
        expect($fixture['completion']->complete('/loops start '))->toContain('harness');
        expect($fixture['completion']->complete('/loops status '))->toContain('all');
        expect($fixture['completion']->complete('/loops status '))->toContain($fixture['loopId']);
        expect($fixture['completion']->complete('/schedules disable '))->toContain('all');
        expect($fixture['completion']->complete('/schedules disable '))->toContain('nightly-review');
        expect($fixture['completion']->complete('/schedules disable '))->toContain($fixture['scheduleId']);
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('toolkit and space completion cover local installs and cached remote install suggestions', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/toolkits enable '))->toContain('coquibot/coqui-test-toolkit');
        expect($fixture['completion']->complete('/toolkits enable '))->toContain('tool:shell');
        expect($fixture['completion']->complete('/toolkits promote '))->toContain('ReviewToolkit');

        $removeSuggestions = $fixture['completion']->complete('/space remove ');
        expect($removeSuggestions)->toContain('review-skill');
        expect($removeSuggestions)->toContain('coquibot/coqui-test-toolkit');

        $updateSuggestions = $fixture['completion']->complete('/space update ');
        expect($updateSuggestions)->toContain('review-skill');
        expect($updateSuggestions)->toContain('coquibot/coqui-test-toolkit');

        $installSuggestions = $fixture['completion']->complete('/space install coq');
        expect($installSuggestions)->toContain('coquibot/review-skill');
        expect($installSuggestions)->toContain('coquibot/coqui-test-toolkit');
        expect(($fixture['getSpaceSearchRequestCount'])())->toBe(1);

        $cachedInstallSuggestions = $fixture['completion']->complete('/space install coq');
        expect($cachedInstallSuggestions)->toContain('coquibot/review-skill');
        expect($cachedInstallSuggestions)->toContain('coquibot/coqui-test-toolkit');
        expect(($fixture['getSpaceSearchRequestCount'])())->toBe(1);
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});