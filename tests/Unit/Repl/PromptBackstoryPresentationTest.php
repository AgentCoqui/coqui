<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Backstory\BackstoryAssembler;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Repl\Handler\BackstoryHandler;
use CoquiBot\Coqui\Repl\Handler\BudgetHandler;
use CoquiBot\Coqui\Repl\Handler\ChannelHandler;
use CoquiBot\Coqui\Repl\Handler\ConfigHandler;
use CoquiBot\Coqui\Repl\Handler\ConversationHandler;
use CoquiBot\Coqui\Repl\Handler\GroupHandler;
use CoquiBot\Coqui\Repl\Handler\LoopHandler;
use CoquiBot\Coqui\Repl\Handler\ProfileHandler;
use CoquiBot\Coqui\Repl\Handler\ProjectHandler;
use CoquiBot\Coqui\Repl\Handler\RoleHandler;
use CoquiBot\Coqui\Repl\Handler\ScheduleHandler;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Repl\Handler\TaskHandler;
use CoquiBot\Coqui\Repl\Handler\ThinkingHandler;
use CoquiBot\Coqui\Repl\Handler\TodoHandler;
use CoquiBot\Coqui\Repl\Handler\ToolkitVisibilityHandler;
use CoquiBot\Coqui\Repl\Handler\WebhookHandler;
use CoquiBot\Coqui\Repl\SlashCommandRouter;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\PromptInspectionService;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function makeReplPromptBackstoryCredentialResolver(string $workspacePath): CredentialResolverInterface
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

function createPromptBackstoryRouterFixture(bool $conversationHistoryInSystemPrompt = false): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-repl-prompt-backstory-' . bin2hex(random_bytes(8));
    $profilePath = $workspacePath . '/profiles/caelum';
    mkdir($workspacePath . '/data', 0755, true);
    mkdir($profilePath . '/backstory/nested', 0755, true);
    file_put_contents($workspacePath . '/.env', '');
    file_put_contents($profilePath . '/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($profilePath . '/preferences.json', json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($profilePath . '/backstory/intro.md', "# Intro\n\nCaelum remembers details.");
    file_put_contents($profilePath . '/backstory/nested/notes.txt', "Prefers reflective conversations.");

    $assembler = new BackstoryAssembler();
    $assembler->generate($profilePath);

    $dbPath = sys_get_temp_dir() . '/coqui-repl-prompt-backstory-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
                'context' => ['conversationHistoryInSystemPrompt' => $conversationHistoryInSystemPrompt],
            ],
        ],
    ]);
    $credentialResolver = makeReplPromptBackstoryCredentialResolver($workspacePath);
    $projectRoot = dirname(__DIR__, 3);

    $runner = new AgentRunner(
        roleResolver: new CoquiBot\Coqui\Config\RoleResolver($config),
        config: $config,
        projectRoot: $projectRoot,
        workspacePath: $workspacePath,
        storage: $storage,
        observer: null,
        discovery: new ToolkitDiscovery(
            projectRoot: $projectRoot,
            workspacePath: $workspacePath,
            credentialResolver: $credentialResolver,
        ),
        blacklist: new CatastrophicBlacklist(),
        credentialResolver: $credentialResolver,
        providerFactory: new ProviderFactory($config),
    );

    $instantiate = static function (string $class): object {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    };

    $output = new BufferedOutput();

    $router = new SlashCommandRouter(
        $instantiate(SessionHandler::class),
        $instantiate(TaskHandler::class),
        $instantiate(TodoHandler::class),
        $instantiate(ScheduleHandler::class),
        $instantiate(BudgetHandler::class),
        $instantiate(ChannelHandler::class),
        $instantiate(ProjectHandler::class),
        $instantiate(RoleHandler::class),
        $instantiate(GroupHandler::class),
        $instantiate(ProfileHandler::class),
        $instantiate(ToolkitVisibilityHandler::class),
        $instantiate(ConfigHandler::class),
        $instantiate(ThinkingHandler::class),
        $instantiate(ConversationHandler::class),
        $instantiate(WebhookHandler::class),
        $instantiate(LoopHandler::class),
        new BackstoryHandler(new ProfileDiscovery($workspacePath), $workspacePath, $assembler),
        $runner,
        new PromptInspectionService($runner, $workspacePath, $projectRoot),
        $output,
        $workspacePath,
        null,
        static function (): void {},
        static function (?bool $enable = null): void {},
    );

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'router' => $router,
    ];
}

function cleanupPromptBackstoryRouterFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('slash command router renders prompt output from the shared inspection payload', function (): void {
    $fixture = createPromptBackstoryRouterFixture();

    try {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $fixture['router']->route('/prompt', SystemRole::Orchestrator->value, 'session-1', $io, null, 'caelum');
        $display = $output->fetch();

        expect($result->shouldContinue)->toBeTrue();
        expect($display)->toContain('System Prompt');
        expect($display)->toContain('Prompt File');
        expect($display)->toContain('Generated Source');
        expect($display)->toContain('workspace:profiles/caelum');
        expect($display)->toContain('Warm and curious');
    } finally {
        cleanupPromptBackstoryRouterFixture($fixture);
    }
});

test('slash command router exports prompt with session-aware conversation history', function (): void {
    $fixture = createPromptBackstoryRouterFixture(conversationHistoryInSystemPrompt: true);

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->addMessage($sessionId, 'user', 'Earlier question');
        $fixture['storage']->addMessage($sessionId, 'assistant', 'Earlier answer');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $fixture['router']->route('/prompt export', SystemRole::Orchestrator->value, $sessionId, $io);
        $display = $output->fetch();
        $exports = glob($fixture['workspacePath'] . '/Prompt-*.txt');

        expect($result->shouldContinue)->toBeTrue();
        expect($display)->toContain('Prompt exported to:');
        expect($exports)->toBeArray()->toHaveCount(1);

        $content = file_get_contents($exports[0]);

        expect($content)->not->toBeFalse();
        expect($content)->toContain('## Conversation History');
        expect($content)->toContain('Earlier question');
        expect($content)->toContain('Earlier answer');
    } finally {
        cleanupPromptBackstoryRouterFixture($fixture);
    }
});

test('slash command router renders backstory metadata from the shared inspection payload', function (): void {
    $fixture = createPromptBackstoryRouterFixture();

    try {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $fixture['router']->route('/backstory', SystemRole::Orchestrator->value, 'session-1', $io, null, 'caelum');
        $display = $output->fetch();

        expect($result->shouldContinue)->toBeTrue();
        expect($display)->toContain('Backstory — caelum');
        expect($display)->toContain('Source folder');
        expect($display)->toContain('Generated file');
        expect($display)->toContain('intro.md');
        expect($display)->toContain('nested');
        expect($display)->not->toContain('Generated Backstory');
        expect($display)->not->toContain('Caelum remembers details');
    } finally {
        cleanupPromptBackstoryRouterFixture($fixture);
    }
});