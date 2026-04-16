<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\Handler\BudgetHandler;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Storage\SessionStorage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use React\Http\Message\ServerRequest;

function makeBudgetHandlerCredentialResolver(string $workspacePath): CredentialResolverInterface
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

function createBudgetHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-budget-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/data', 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    file_put_contents($workspacePath . '/.env', '');
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($workspacePath . '/profiles/caelum/preferences.json', json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
        ],
    ], JSON_THROW_ON_ERROR));

    $dbPath = sys_get_temp_dir() . '/coqui-budget-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);
    $credentialResolver = makeBudgetHandlerCredentialResolver($workspacePath);

    $runner = new AgentRunner(
        roleResolver: new CoquiBot\Coqui\Config\RoleResolver($config),
        config: $config,
        projectRoot: dirname(__DIR__, 4),
        workspacePath: $workspacePath,
        storage: $storage,
        observer: null,
        discovery: new ToolkitDiscovery(
            projectRoot: dirname(__DIR__, 4),
            workspacePath: $workspacePath,
            credentialResolver: $credentialResolver,
        ),
        blacklist: new CatastrophicBlacklist(),
        credentialResolver: $credentialResolver,
        providerFactory: new ProviderFactory($config),
    );

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'handler' => new BudgetHandler($runner),
    ];
}

function cleanupBudgetHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('budget handler accepts profile query parameter', function () {
    $fixture = createBudgetHandlerFixture();

    try {
        $handler = $fixture['handler'];

        $defaultResponse = $handler->get(new ServerRequest('GET', '/api/v1/server/budget'));
        $profiledResponse = $handler->get(
            (new ServerRequest('GET', '/api/v1/server/budget'))->withQueryParams(['profile' => 'caelum'])
        );

        $defaultBody = json_decode((string) $defaultResponse->getBody(), true);
        $profiledBody = json_decode((string) $profiledResponse->getBody(), true);
        $defaultIds = array_column($defaultBody['prompt_sections'] ?? [], 'id');
        $profiledIds = array_column($profiledBody['prompt_sections'] ?? [], 'id');

        expect($profiledResponse->getStatusCode())->toBe(200);
        expect($defaultIds)->not->toContain('prompt.preferences');
        expect($profiledIds)->toContain('prompt.preferences');
    } finally {
        cleanupBudgetHandlerFixture($fixture);
    }
});