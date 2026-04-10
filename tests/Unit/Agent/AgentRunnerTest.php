<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\EditHistory;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Provider\Usage;

function makeTestCredentialResolver(string $workspacePath): CredentialResolverInterface
{
    return new class($workspacePath) implements CredentialResolverInterface {
        public function __construct(private readonly string $workspacePath) {}

        public function get(string $key): ?string
        {
            return null;
        }

        public function has(string $key): bool
        {
            return false;
        }

        public function set(string $key, string $value): void {}

        public function delete(string $key): void {}

        public function loadIntoProcessEnv(): void {}

        public function keys(): array
        {
            return [];
        }

        public function envPath(): string
        {
            return $this->workspacePath . '/.env';
        }
    };
}

/**
 * @return array{
 *   workspacePath: string,
 *   dbPath: string,
 *   memoryDbPath: string,
 *   storage: SessionStorage,
 *   config: OpenClawConfig,
 *   discovery: ToolkitDiscovery,
 *   blacklist: CatastrophicBlacklist
 * }
 */
function createAgentRunnerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-agent-runner-ws-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/data', 0755, true);
    file_put_contents($workspacePath . '/.env', '');

    $dbPath = sys_get_temp_dir() . '/coqui-agent-runner-' . bin2hex(random_bytes(8)) . '.db';
    $memoryDbPath = sys_get_temp_dir() . '/coqui-agent-runner-memory-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);

    $discovery = new ToolkitDiscovery(
        projectRoot: dirname(__DIR__, 3),
        workspacePath: $workspacePath,
        credentialResolver: makeTestCredentialResolver($workspacePath),
    );

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'memoryDbPath' => $memoryDbPath,
        'storage' => $storage,
        'config' => $config,
        'discovery' => $discovery,
        'blacklist' => new CatastrophicBlacklist(),
    ];
}

/**
 * @param array{workspacePath: string, dbPath: string, memoryDbPath: string} $fixture
 */
function cleanupAgentRunnerFixture(array $fixture): void
{
    foreach ([$fixture['dbPath'], $fixture['memoryDbPath']] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }

        foreach (['-wal', '-shm'] as $suffix) {
            $path = $file . $suffix;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    if (!is_dir($fixture['workspacePath'])) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture['workspacePath'], RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($fixture['workspacePath']);
}

function makeAgentRunnerFixture(
    OpenClawConfig $config,
    SessionStorage $storage,
    string $workspacePath,
    ToolkitDiscovery $discovery,
    CatastrophicBlacklist $blacklist,
    ?TodoStore $todoStore = null,
    ?ArtifactStore $artifactStore = null,
    ?ProjectStore $projectStore = null,
    ?MemoryStore $memoryStore = null,
): AgentRunner {
    return new AgentRunner(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: dirname(__DIR__, 3),
        workspacePath: $workspacePath,
        storage: $storage,
        observer: null,
        discovery: $discovery,
        blacklist: $blacklist,
        credentialResolver: makeTestCredentialResolver($workspacePath),
        providerFactory: new ProviderFactory($config),
        todoStore: $todoStore,
        artifactStore: $artifactStore,
        projectStore: $projectStore,
        memoryStore: $memoryStore,
    );
}

test('buildWorkflowContext returns null when no workflow state exists', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
        );

        $method = new ReflectionMethod($runner, 'buildWorkflowContext');
        $context = $method->invoke($runner, $sessionId);

        expect($context)->toBeNull();
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('buildWorkflowContext includes todo artifact and sprint summaries', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $pdo = $fixture['storage']->getPdo();
        $todoStore = new TodoStore($pdo);
        $artifactStore = new ArtifactStore($pdo);
        $projectStore = new ProjectStore($pdo);

        $projectId = $projectStore->createProject('Testing Project', 'testing-project');
        $fixture['storage']->setActiveProject($sessionId, $projectId);

        $sprintId = $projectStore->createSprint(
            projectId: $projectId,
            title: 'Sprint One',
            lastSessionId: $sessionId,
        );
        $projectStore->transitionSprint($sprintId, 'in_progress');

        $inProgressTodoId = $todoStore->create($sessionId, 'Write regression tests', sprintId: $sprintId);
        $todoStore->update($inProgressTodoId, status: 'in_progress');
        $todoStore->create($sessionId, 'Review CI output', sprintId: $sprintId);
        $completedTodoId = $todoStore->create($sessionId, 'Add coverage command', sprintId: $sprintId);
        $todoStore->complete($completedTodoId);

        $artifactStore->create(
            sessionId: $sessionId,
            title: 'Implementation Plan',
            content: 'Plan body',
            type: 'plan',
            stage: 'review',
        );

        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
            todoStore: $todoStore,
            artifactStore: $artifactStore,
            projectStore: $projectStore,
        );

        $method = new ReflectionMethod($runner, 'buildWorkflowContext');
        $context = $method->invoke($runner, $sessionId);

        expect($context)->toContain('Todos: 1/3 completed')
            ->toContain('[in_progress] Write regression tests')
            ->toContain('[pending] Review CI output')
            ->toContain('Artifacts:')
            ->toContain('[plan/review] Implementation Plan')
            ->toContain('Active sprints:')
            ->toContain("Sprint #1 'Sprint One'");
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('collectFileEdits returns normalized recent edit entries', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
        );

        $before = (new DateTimeImmutable())->format('c');
        usleep(10_000);

        $history = new EditHistory($fixture['workspacePath'] . '/data/edit-history');
        $history->record($fixture['workspacePath'] . '/src/Test.php', 'write_file', '<?php');

        $method = new ReflectionMethod($runner, 'collectFileEdits');
        $edits = $method->invoke($runner, $before);

        expect($edits)->toBeArray();
        expect($edits[0])->toBe([
            'file_path' => $fixture['workspacePath'] . '/src/Test.php',
            'operation' => 'write_file',
        ]);
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('autoExtractMemories returns early when auto extraction is disabled', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->addMessage($sessionId, 'user', 'Remember that I use PHP 8.4');
        $fixture['storage']->addMessage($sessionId, 'assistant', 'Noted');

        $memoryStore = new MemoryStore($fixture['memoryDbPath']);
        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
            memoryStore: $memoryStore,
        );

        $conversation = $fixture['storage']->loadConversation($sessionId);
        $notifications = [];

        $method = new ReflectionMethod($runner, 'autoExtractMemories');
        $method->invoke($runner, $conversation, $sessionId, function (string $event, mixed $payload) use (&$notifications): void {
            $notifications[] = [$event, $payload];
        });

        expect($memoryStore->count())->toBe(0);
        expect($notifications)->toBe([]);
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('resolveExitFlags distinguishes max-iteration exits from budget exhaustion', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
        );

        $method = new ReflectionMethod($runner, 'resolveExitFlags');

        $maxIterationFlags = $method->invoke(
            $runner,
            new Output(content: 'max', iterations: 3, finishReason: AgentFinishReason::MaxIterations),
            3,
        );

        $budgetFlags = $method->invoke(
            $runner,
            new Output(content: 'budget', iterations: 3, finishReason: AgentFinishReason::BudgetExhausted),
            3,
        );

        expect($maxIterationFlags)->toBe([
            'iterationLimitReached' => true,
            'budgetExhausted' => false,
        ])->and($budgetFlags)->toBe([
            'iterationLimitReached' => false,
            'budgetExhausted' => true,
        ]);
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

// --- sanitizeUsage: clamp implausible provider-reported prompt tokens ---

test('sanitizeUsage returns provider usage when prompt tokens are reasonable', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
        );

        // Build a conversation with enough content that 200 prompt tokens is plausible
        $conversation = new Conversation();
        $conversation->add(new SystemMessage(str_repeat('You are a helpful assistant that provides detailed answers. ', 20)));
        $conversation->add(new UserMessage(str_repeat('Can you explain the details of PHP 8.4 property hooks and how they work in practice? ', 10)));
        $conversation->add(new AssistantMessage('PHP 8.4 introduces property hooks.'));

        $output = new Output(
            content: 'PHP 8.4 introduces property hooks.',
            iterations: 1,
            conversation: $conversation,
            usage: new Usage(promptTokens: 200, completionTokens: 20, totalTokens: 220),
        );

        // Provider says 200 prompt tokens, heuristic estimates ~similar — should pass through
        $method = new ReflectionMethod($runner, 'sanitizeUsage');
        $sanitized = $method->invoke(
            $runner,
            $output->usage,
            $output,
            'ollama/qwen3:latest',
        );

        expect($sanitized->promptTokens)->toBe(200);
        expect($sanitized->completionTokens)->toBe(20);
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('sanitizeUsage clamps implausibly high prompt tokens from Ollama num_ctx', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
        );

        // Build a small conversation (~50 tokens)
        $conversation = new Conversation();
        $conversation->add(new SystemMessage('You are a helper.'));
        $conversation->add(new UserMessage('Hello'));
        $conversation->add(new AssistantMessage('Hi'));

        $output = new Output(
            content: 'Hi',
            iterations: 1,
            conversation: $conversation,
            usage: new Usage(promptTokens: 32768, completionTokens: 10, totalTokens: 32778),
        );

        // Provider reports 32768 (Ollama num_ctx) but real content is ~50 tokens
        $method = new ReflectionMethod($runner, 'sanitizeUsage');
        $sanitized = $method->invoke(
            $runner,
            $output->usage,
            $output,
            'ollama/qwen3:latest',
        );

        // Should be clamped to heuristic estimate, NOT 32768
        expect($sanitized->promptTokens)->toBeLessThan(500);
        // Completion tokens from provider are preserved
        expect($sanitized->completionTokens)->toBe(10);
        // Total should be recalculated
        expect($sanitized->totalTokens)->toBe($sanitized->promptTokens + 10);
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('sanitizeUsage trusts provider when conversation is null', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
        );

        $output = new Output(
            content: 'response',
            iterations: 1,
            conversation: null,
            usage: new Usage(promptTokens: 32768, completionTokens: 10, totalTokens: 32778),
        );

        $method = new ReflectionMethod($runner, 'sanitizeUsage');
        $sanitized = $method->invoke(
            $runner,
            $output->usage,
            $output,
            'ollama/qwen3:latest',
        );

        // No conversation to estimate from — trust provider as-is
        expect($sanitized->promptTokens)->toBe(32768);
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});
