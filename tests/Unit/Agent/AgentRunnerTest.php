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
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage as ProviderSystemMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;

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
        cleanupSqliteTestDb($file);
    }

    cleanupTestTree($fixture['workspacePath']);
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

function allowAllPolicy(): ToolExecutionPolicyInterface
{
    return new class implements ToolExecutionPolicyInterface {
        public function shouldExecute(string $toolName, array $arguments): true|string
        {
            return true;
        }
    };
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

test('persistTurnMessages stores actor metadata for assistant and tool messages', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $turnId = $fixture['storage']->createTurn($sessionId, 'Review the change.');

        $runner = makeAgentRunnerFixture(
            config: $fixture['config'],
            storage: $fixture['storage'],
            workspacePath: $fixture['workspacePath'],
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
        );

        $conversation = new Conversation();
        $conversation->add(new SystemMessage('System'));
        $conversation->add(new UserMessage('Review the change.'));
        $conversation->add(new AssistantMessage('', [new ToolCall('call_1', 'read_file', ['path' => 'README.md'])]));
        $conversation->add(new ToolResultMessage((new ToolResult(ToolResultStatus::Success, 'README contents'))->withCallId('call_1')));
        $conversation->add(new AssistantMessage('The README looks good.'));

        $method = new ReflectionMethod($runner, 'persistTurnMessages');
        $method->invoke($runner, $conversation, 0, $sessionId, $turnId, 'nova', 'orchestrator');

        $messages = $fixture['storage']->getMessages($sessionId);

        expect(array_column($messages, 'role'))->toBe(['assistant', 'tool', 'assistant']);
        expect(array_column($messages, 'actor_name'))->toBe(['nova', 'nova', 'nova']);
        expect(array_column($messages, 'actor_role'))->toBe(['orchestrator', 'orchestrator', 'orchestrator']);
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

test('run keeps replayed history and also appends prior history in final system prompt section when enabled', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
                    'context' => ['conversationHistoryInSystemPrompt' => true],
                ],
            ],
        ]);

        $capturedMessages = [];
        $providerResolver = static function (string $modelString) use (&$capturedMessages): ProviderInterface {
            return new class($capturedMessages) implements ProviderInterface {
                /** @var array<int, array<int, mixed>> */
                private array $capturedMessages;

                public function __construct(array &$capturedMessages)
                {
                    $this->capturedMessages = &$capturedMessages;
                }

                public function chat(array $messages, array $tools = [], array $options = []): Response
                {
                    $this->capturedMessages[] = $messages;

                    return new Response(
                        content: 'Done.',
                        finishReason: ProviderFinishReason::Stop,
                        model: 'test/mock',
                        usage: new Usage(promptTokens: 10, completionTokens: 2, totalTokens: 12),
                    );
                }

                public function stream(array $messages, array $tools = [], array $options = []): iterable
                {
                    $this->capturedMessages[] = $messages;

                    yield new Response(
                        content: 'Done.',
                        finishReason: ProviderFinishReason::Stop,
                        model: 'test/mock',
                        usage: new Usage(promptTokens: 10, completionTokens: 2, totalTokens: 12),
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
                    return 'test/mock';
                }

                public function withModel(string $model): static
                {
                    return $this;
                }
            };
        };

        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->addMessage($sessionId, 'user', 'Earlier question');
        $fixture['storage']->addMessage(
            $sessionId,
            'assistant',
            'Let me inspect that.',
            toolCalls: json_encode([
                ['id' => 'call_1', 'name' => 'read_file', 'arguments' => ['path' => 'README.md']],
            ], JSON_THROW_ON_ERROR),
        );
        $fixture['storage']->addMessage($sessionId, 'tool', 'README contents', toolCallId: 'call_1');
        $fixture['storage']->addMessage(
            $sessionId,
            'user',
            "[CONVERSATION SUMMARY - 2026-04-26 10:00] (3 messages condensed)\n\nPrior work summary\n\nFocus on the most recent messages below for the user's current intent. This summary provides background context only.",
        );

        $runner = new AgentRunner(
            roleResolver: new RoleResolver($config),
            config: $config,
            projectRoot: dirname(__DIR__, 3),
            workspacePath: $fixture['workspacePath'],
            storage: $fixture['storage'],
            observer: null,
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
            credentialResolver: makeTestCredentialResolver($fixture['workspacePath']),
            providerFactory: new ProviderFactory($config),
            providerResolver: $providerResolver,
        );

        $result = $runner->run('Current request', $sessionId, allowAllPolicy());

        expect($result->error)->toBeNull();
        expect($capturedMessages)->toHaveCount(1);
        expect($capturedMessages[0])->toHaveCount(6);
        expect($capturedMessages[0][0])->toBeInstanceOf(ProviderSystemMessage::class);
        expect($capturedMessages[0][1])->toBeInstanceOf(UserMessage::class);
        expect($capturedMessages[0][2])->toBeInstanceOf(AssistantMessage::class);
        expect($capturedMessages[0][3])->toBeInstanceOf(ToolResultMessage::class);
        expect($capturedMessages[0][4])->toBeInstanceOf(UserMessage::class);
        expect($capturedMessages[0][5])->toBeInstanceOf(UserMessage::class);

        $systemPrompt = $capturedMessages[0][0]->content();
        expect($systemPrompt)->toContain('## Conversation History')
            ->toContain('Earlier question')
            ->toContain('[tool-result:read_file]')
            ->toContain('[summary]')
            ->toContain('Prior work summary')
            ->not->toContain('Current request');

        expect($capturedMessages[0][1]->content())->toBe('Earlier question');
        expect($capturedMessages[0][5]->content())->toBe('Current request');
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('run keeps replayed history when conversation history prompt mode is disabled', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $capturedMessages = [];
        $providerResolver = static function (string $modelString) use (&$capturedMessages): ProviderInterface {
            return new class($capturedMessages) implements ProviderInterface {
                /** @var array<int, array<int, mixed>> */
                private array $capturedMessages;

                public function __construct(array &$capturedMessages)
                {
                    $this->capturedMessages = &$capturedMessages;
                }

                public function chat(array $messages, array $tools = [], array $options = []): Response
                {
                    $this->capturedMessages[] = $messages;

                    return new Response(
                        content: 'Done.',
                        finishReason: ProviderFinishReason::Stop,
                        model: 'test/mock',
                        usage: new Usage(promptTokens: 10, completionTokens: 2, totalTokens: 12),
                    );
                }

                public function stream(array $messages, array $tools = [], array $options = []): iterable
                {
                    $this->capturedMessages[] = $messages;

                    yield new Response(
                        content: 'Done.',
                        finishReason: ProviderFinishReason::Stop,
                        model: 'test/mock',
                        usage: new Usage(promptTokens: 10, completionTokens: 2, totalTokens: 12),
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
                    return 'test/mock';
                }

                public function withModel(string $model): static
                {
                    return $this;
                }
            };
        };

        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->addMessage($sessionId, 'user', 'Earlier question');
        $fixture['storage']->addMessage($sessionId, 'assistant', 'Earlier answer');

        $runner = new AgentRunner(
            roleResolver: new RoleResolver($fixture['config']),
            config: $fixture['config'],
            projectRoot: dirname(__DIR__, 3),
            workspacePath: $fixture['workspacePath'],
            storage: $fixture['storage'],
            observer: null,
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
            credentialResolver: makeTestCredentialResolver($fixture['workspacePath']),
            providerFactory: new ProviderFactory($fixture['config']),
            providerResolver: $providerResolver,
        );

        $result = $runner->run('Current request', $sessionId, allowAllPolicy());

        expect($result->error)->toBeNull();
        expect($capturedMessages)->toHaveCount(1);
        expect(count($capturedMessages[0]))->toBeGreaterThan(2);
        expect($capturedMessages[0][0])->toBeInstanceOf(ProviderSystemMessage::class);
        expect($capturedMessages[0][1])->toBeInstanceOf(UserMessage::class);
        expect($capturedMessages[0][2])->toBeInstanceOf(AssistantMessage::class);

        $systemPrompt = $capturedMessages[0][0]->content();
        expect($systemPrompt)->not->toContain('## Conversation History');
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('run renders actor-backed group speakers distinctly in conversation history prompt mode', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
                    'context' => ['conversationHistoryInSystemPrompt' => true],
                ],
            ],
        ]);

        $capturedMessages = [];
        $providerResolver = static function (string $modelString) use (&$capturedMessages): ProviderInterface {
            return new class($capturedMessages) implements ProviderInterface {
                /** @var array<int, array<int, mixed>> */
                private array $capturedMessages;

                public function __construct(array &$capturedMessages)
                {
                    $this->capturedMessages = &$capturedMessages;
                }

                public function chat(array $messages, array $tools = [], array $options = []): Response
                {
                    $this->capturedMessages[] = $messages;

                    return new Response(
                        content: 'Done.',
                        finishReason: ProviderFinishReason::Stop,
                        model: 'test/mock',
                        usage: new Usage(promptTokens: 10, completionTokens: 2, totalTokens: 12),
                    );
                }

                public function stream(array $messages, array $tools = [], array $options = []): iterable
                {
                    $this->capturedMessages[] = $messages;

                    yield new Response(
                        content: 'Done.',
                        finishReason: ProviderFinishReason::Stop,
                        model: 'test/mock',
                        usage: new Usage(promptTokens: 10, completionTokens: 2, totalTokens: 12),
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
                    return 'test/mock';
                }

                public function withModel(string $model): static
                {
                    return $this;
                }
            };
        };

        $sessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 2);
        $fixture['storage']->addMessage($sessionId, 'user', 'Please coordinate this response.');
        $fixture['storage']->addMessage(
            $sessionId,
            'assistant',
            'I will take the first pass.',
            toolCalls: json_encode([
                ['id' => 'call_group_1', 'name' => 'read_file', 'arguments' => ['path' => 'README.md']],
            ], JSON_THROW_ON_ERROR),
            actorName: 'nova',
            actorRole: 'orchestrator',
        );
        $fixture['storage']->addMessage(
            $sessionId,
            'tool',
            'README contents',
            toolCallId: 'call_group_1',
            actorName: 'nova',
            actorRole: 'orchestrator',
        );

        $runner = new AgentRunner(
            roleResolver: new RoleResolver($config),
            config: $config,
            projectRoot: dirname(__DIR__, 3),
            workspacePath: $fixture['workspacePath'],
            storage: $fixture['storage'],
            observer: null,
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
            credentialResolver: makeTestCredentialResolver($fixture['workspacePath']),
            providerFactory: new ProviderFactory($config),
            providerResolver: $providerResolver,
        );

        $result = $runner->run('Current request', $sessionId, allowAllPolicy());

        expect($result->error)->toBeNull();
        expect($capturedMessages)->toHaveCount(1);

        $systemPrompt = $capturedMessages[0][0]->content();
        expect($systemPrompt)
            ->toContain('@nova (orchestrator)')
            ->toContain('[tool-result:read_file]')
            ->not->toContain('nova assistant');
    } finally {
        cleanupAgentRunnerFixture($fixture);
    }
});

test('export prompt to file includes conversation history when session is provided and mode is enabled', function () {
    $fixture = createAgentRunnerFixture();

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
                    'context' => ['conversationHistoryInSystemPrompt' => true],
                ],
            ],
        ]);

        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->addMessage($sessionId, 'user', 'Earlier question');
        $fixture['storage']->addMessage($sessionId, 'assistant', 'Earlier answer');

        $runner = new AgentRunner(
            roleResolver: new RoleResolver($config),
            config: $config,
            projectRoot: dirname(__DIR__, 3),
            workspacePath: $fixture['workspacePath'],
            storage: $fixture['storage'],
            observer: null,
            discovery: $fixture['discovery'],
            blacklist: $fixture['blacklist'],
            credentialResolver: makeTestCredentialResolver($fixture['workspacePath']),
            providerFactory: new ProviderFactory($config),
        );

        $filePath = $runner->exportPromptToFile(sessionId: $sessionId);
        $content = file_get_contents($filePath);

        expect($content)->not->toBeFalse();
        expect($content)->toContain('## Conversation History');
        expect($content)->toContain('Earlier question');
        expect($content)->toContain('Earlier answer');
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
