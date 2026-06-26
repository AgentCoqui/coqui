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
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Repl\ReplCommandCatalog;
use CoquiBot\Coqui\Repl\TabCompletion;
use CoquiBot\Coqui\Repl\ToolkitCommandCandidate;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use CoquiBot\ModManager\Config\ModRegistry;
use CoquiBot\ModManager\ModManagerToolkit;

function createTabCompletionFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-tab-completion-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    mkdir($workspacePath . '/profiles/nova', 0755, true);
    mkdir($workspacePath . '/profiles/iris', 0755, true);
    mkdir($workspacePath . '/roles', 0755, true);
    mkdir($workspacePath . '/skills/review-skill', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "# Caelum\n\nA calm companion.\n");
    file_put_contents($workspacePath . '/profiles/nova/soul.md', "# Nova\n\nA direct collaborator.\n");
    file_put_contents($workspacePath . '/profiles/iris/soul.md', "# Iris\n\nA careful reviewer.\n");
    copy(dirname(__DIR__, 3) . '/config/roles/coder.md', $workspacePath . '/roles/coder.md');
    file_put_contents($workspacePath . '/skills/review-skill/' . ModRegistry::ORIGIN_FILE, json_encode([
        'source' => 'coqui.mods',
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
    $scheduleStore = new ScheduleStore($storage->getPdo());
    $loopStore = new LoopStore($storage->getPdo());
    $webhookStore = new WebhookStore($storage->getPdo());

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

    $boot = testBootManagerForTabCompletion(
        workspacePath: $workspacePath,
        roleResolver: $roleResolver,
        roleDiscovery: $roleDiscovery,
        profileDiscovery: $profileDiscovery,
        projectStore: $projectStore,
        loopDiscovery: $loopDiscovery,
        discovery: $discovery,
        visibilityRegistry: $visibilityRegistry,
        modsToolkit: null,
    );

    $sessionId = $storage->createSession('orchestrator', 'ollama/qwen3:latest');
    $projectStore->createProject('Docs Cleanup', 'docs-cleanup');
    $scheduleId = $scheduleStore->create('nightly-review', '0 0 * * *', 'Run nightly review');
    $loopId = $loopStore->createLoop('harness', 'Keep REPL docs aligned', []);
    $webhookId = $webhookStore->create('release-hook', 'Summarize the release payload.');

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
        'scheduleId' => $scheduleId,
        'loopId' => $loopId,
        'webhookId' => $webhookId,
        'pendingTaskId' => $pendingTaskId,
        'completedTaskId' => $completedTaskId,
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
    ProjectStore $projectStore,
    LoopDiscovery $loopDiscovery,
    ToolkitDiscovery $discovery,
    ToolkitVisibilityRegistry $visibilityRegistry,
    ?ModManagerToolkit $modsToolkit,
): BootManager {
    $reflection = new ReflectionClass(BootManager::class);
    /** @var BootManager $boot */
    $boot = $reflection->newInstanceWithoutConstructor();

    $initializer = function () use (
        $workspacePath,
        $roleResolver,
        $roleDiscovery,
        $profileDiscovery,
        $projectStore,
        $loopDiscovery,
        $discovery,
        $visibilityRegistry,
        $modsToolkit,
    ): void {
        $this->workspacePath = $workspacePath;
        $this->roleResolver = $roleResolver;
        $this->roleDiscovery = $roleDiscovery;
        $this->profileDiscovery = $profileDiscovery;
        $this->projectStore = $projectStore;
        $this->loopDiscovery = $loopDiscovery;
        $this->discovery = $discovery;
        $this->visibilityRegistry = $visibilityRegistry;
        $this->modsToolkit = $modsToolkit;
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

        expect($suggestions)->not->toContain('/toolkits enable');
        expect($suggestions)->not->toContain('/loops start');
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
            '/schedules ' => ['status', 'enable', 'disable', 'delete', 'trigger'],
            '/loops ' => ['start', 'definitions', 'defs', 'status', 'pause', 'resume', 'stop', 'running', 'paused', 'completed', 'failed', 'cancelled'],
            '/webhooks ' => ['status', 'deliveries', 'enable', 'disable', 'delete', 'rotate'],
            '/prompt ' => ['export'],
            '/summarize ' => ['recent', 'focus'],
            '/roles ' => ['list', 'update', 'ignore', 'unignore'],
            '/backstory ' => ['generate', 'failed'],
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
        expect($fixture['completion']->complete('/group '))->toContain('start');
        expect($fixture['completion']->complete('/group '))->toContain('status');
        expect($fixture['completion']->complete('/group start '))->toContain('caelum');
        expect($fixture['completion']->complete('/group start '))->toContain('--rounds=3');
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
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('group completion uses current group members for add and remove suggestions', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        $groupSessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);
        $fixture['completion']->setSessionId($groupSessionId);

        expect($fixture['completion']->complete('/group add '))->toContain('iris');
        expect($fixture['completion']->complete('/group add '))->not->toContain('caelum');
        expect($fixture['completion']->complete('/group remove '))->toContain('caelum');
        expect($fixture['completion']->complete('/group remove '))->toContain('nova');
        expect($fixture['completion']->complete('/group rounds '))->toContain('3');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('project, schedule, loop, and webhook completion cover filters and live identifiers', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/projects '))->toContain('clear');
        expect($fixture['completion']->complete('/projects '))->toContain('docs-cleanup');
        expect($fixture['completion']->complete('/loops st'))->toContain('start');
        expect($fixture['completion']->complete('/loops st'))->toContain('status');
        expect($fixture['completion']->complete('/loops st'))->toContain('stop');
        expect($fixture['completion']->complete('/loops start '))->toContain('harness');
        expect($fixture['completion']->complete('/loops status '))->toContain('all');
        expect($fixture['completion']->complete('/loops status '))->toContain($fixture['loopId']);
        expect($fixture['completion']->complete('/schedules status '))->not->toContain('all');
        expect($fixture['completion']->complete('/schedules disable '))->toContain('all');
        expect($fixture['completion']->complete('/schedules disable '))->toContain('nightly-review');
        expect($fixture['completion']->complete('/schedules disable '))->toContain($fixture['scheduleId']);
        expect($fixture['completion']->complete('/webhooks enable '))->toContain('release-hook');
        expect($fixture['completion']->complete('/webhooks enable '))->toContain($fixture['webhookId']);
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('toolkit completion covers local installs', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/toolkits enable '))->toContain('coquibot/coqui-test-toolkit');
        expect($fixture['completion']->complete('/toolkits enable '))->toContain('tool:shell');
        expect($fixture['completion']->complete('/toolkits promote '))->toContain('ReviewToolkit');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('toolkit completion uses accepted handlers and always exposes help', function (): void {
    $fixture = createTabCompletionFixture();

    $firstHandler = new class implements ToolkitCommandHandler
    {
        public function commandName(): string
        {
            return 'image';
        }

        public function subcommands(): array
        {
            return ['generate'];
        }

        public function usage(): string
        {
            return '/image [action]';
        }

        public function description(): string
        {
            return 'Generate images.';
        }

        public function handle(\CoquiBot\Coqui\Contract\ToolkitReplContext $context, string $arg): void
        {
        }
    };

    $duplicateHandler = new class implements ToolkitCommandHandler
    {
        public function commandName(): string
        {
            return 'image';
        }

        public function subcommands(): array
        {
            return ['delete'];
        }

        public function usage(): string
        {
            return '/image delete <id>';
        }

        public function description(): string
        {
            return 'Delete images.';
        }

        public function handle(\CoquiBot\Coqui\Contract\ToolkitReplContext $context, string $arg): void
        {
        }
    };

    try {
        $report = ReplCommandCatalog::registerToolkitHandlers([
            new ToolkitCommandCandidate('vendor/first-images', $firstHandler),
            new ToolkitCommandCandidate('vendor/second-images', $duplicateHandler),
        ]);
        $fixture['completion']->setToolkitCommandHandlers($report->acceptedHandlers);

        expect($fixture['completion']->complete('/image '))->toContain('generate');
        expect($fixture['completion']->complete('/image '))->toContain('help');
        expect($fixture['completion']->complete('/image '))->not->toContain('delete');
        expect($report->collisions)->toHaveCount(1);
        expect($report->collisions[0]->reason)->toBe('toolkit');
        expect($report->collisions[0]->winnerPackage)->toBe('vendor/first-images');
        expect($report->collisions[0]->skippedPackage)->toBe('vendor/second-images');
    } finally {
        ReplCommandCatalog::clearToolkitHandlers();
        cleanupTabCompletionFixture($fixture);
    }
});