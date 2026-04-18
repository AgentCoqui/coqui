<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Repl\TabCompletion;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;

function createTabCompletionFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-tab-completion-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    mkdir($workspacePath . '/roles', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "# Caelum\n\nA calm companion.\n");
    copy(dirname(__DIR__, 3) . '/config/roles/coder.md', $workspacePath . '/roles/coder.md');

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());
    new ArtifactStore($storage->getPdo(), null, $projectStore);
    $todoStore = new TodoStore($storage->getPdo());

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

    $boot = testBootManagerForTabCompletion(
        workspacePath: $workspacePath,
        roleResolver: $roleResolver,
        roleDiscovery: $roleDiscovery,
        profileDiscovery: $profileDiscovery,
        todoStore: $todoStore,
        projectStore: $projectStore,
        loopDiscovery: $loopDiscovery,
    );

    $sessionId = $storage->createSession('orchestrator', 'ollama/qwen3:latest');
    $todoStore->create($sessionId, 'Review command help');
    $projectStore->createProject('Docs Cleanup', 'docs-cleanup');

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
    TodoStore $todoStore,
    ProjectStore $projectStore,
    LoopDiscovery $loopDiscovery,
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
    ): void {
        $this->workspacePath = $workspacePath;
        $this->roleResolver = $roleResolver;
        $this->roleDiscovery = $roleDiscovery;
        $this->profileDiscovery = $profileDiscovery;
        $this->todoStore = $todoStore;
        $this->projectStore = $projectStore;
        $this->loopDiscovery = $loopDiscovery;
    };

    \Closure::bind($initializer, $boot, BootManager::class)();

    return $boot;
}

test('top-level completion includes quit aliases and omits nested space pseudo commands', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        $suggestions = $fixture['completion']->complete('/');

        expect($suggestions)->toContain('/quit');
        expect($suggestions)->toContain('/exit');
        expect($suggestions)->toContain('/q');
        expect($suggestions)->not->toContain('/space skills');
        expect($suggestions)->not->toContain('/space toolkits');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('profile completion includes default profile actions and available profiles', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/profile d'))->toContain('default');
        expect($fixture['completion']->complete('/profile default '))->toContain('caelum');
        expect($fixture['completion']->complete('/profile default '))->toContain('none');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('resume and task cancellation completion use live stored identifiers', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/resume '))->toContain($fixture['otherSessionId']);
        expect($fixture['completion']->complete('/resume '))->toContain($fixture['resumeSessionId']);
        expect($fixture['completion']->complete('/task-cancel '))->toContain($fixture['pendingTaskId']);
        expect($fixture['completion']->complete('/task-cancel '))->not->toContain($fixture['completedTaskId']);
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('project and sprint completion include status actions and project slugs', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/projects '))->toContain('clear');
        expect($fixture['completion']->complete('/projects '))->toContain('docs-cleanup');
        expect($fixture['completion']->complete('/sprints d'))->toContain('docs-cleanup');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('loop completion exposes actions and available definitions', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        expect($fixture['completion']->complete('/loops st'))->toContain('start');
        expect($fixture['completion']->complete('/loops st'))->toContain('status');
        expect($fixture['completion']->complete('/loops st'))->toContain('stop');
        expect($fixture['completion']->complete('/loops start '))->toContain('harness');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});

test('todo and summarize completion expose accurate static and dynamic suggestions', function (): void {
    $fixture = createTabCompletionFixture();

    try {
        $todoSuggestions = $fixture['completion']->complete('/todos cancel ');

        expect($todoSuggestions)->not->toContain('all');
        expect($todoSuggestions)->not->toBe([]);
        expect($fixture['completion']->complete('/summarize '))->toContain('recent');
        expect($fixture['completion']->complete('/summarize '))->toContain('focus');
    } finally {
        cleanupTabCompletionFixture($fixture);
    }
});