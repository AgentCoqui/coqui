<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\Handler\BackstoryHandler;
use CoquiBot\Coqui\Api\Handler\PromptHandler;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Backstory\BackstoryAssembler;
use CoquiBot\Coqui\Backstory\BackstoryInspectionService;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\PromptInspectionService;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use React\Http\Message\ServerRequest;

function makePromptBackstoryRouteCredentialResolver(string $workspacePath): CredentialResolverInterface
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

function createPromptBackstoryRouteFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-route-inspection-' . bin2hex(random_bytes(8));
    $profilePath = $workspacePath . '/profiles/caelum';
    mkdir($workspacePath . '/data', 0755, true);
    mkdir($profilePath . '/backstory', 0755, true);
    file_put_contents($workspacePath . '/.env', '');
    file_put_contents($profilePath . '/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($profilePath . '/preferences.json', json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($profilePath . '/backstory/intro.md', "# Intro\n\nCaelum remembers details.");

    $assembler = new BackstoryAssembler();
    $assembler->generate($profilePath);

    $dbPath = sys_get_temp_dir() . '/coqui-route-inspection-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);
    $credentialResolver = makePromptBackstoryRouteCredentialResolver($workspacePath);
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

    $router = new Router();
    $router->get(
        '/api/v1/server/prompt',
        [new PromptHandler(new PromptInspectionService($runner, $workspacePath, $projectRoot)), 'get'],
    );
    $router->get(
        '/api/v1/server/backstory',
        [new BackstoryHandler(new BackstoryInspectionService($workspacePath, new ProfileDiscovery($workspacePath), $assembler)), 'get'],
    );

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'router' => $router,
    ];
}

function cleanupPromptBackstoryRouteFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('router dispatches prompt inspection endpoint', function () {
    $fixture = createPromptBackstoryRouteFixture();

    try {
        $request = (new ServerRequest('GET', '/api/v1/server/prompt'))->withQueryParams(['profile' => 'caelum']);
        $response = $fixture['router']->dispatch($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['profile'])->toBe('caelum');
        expect($body['prompt_sources']['files'])->not->toBeEmpty();
    } finally {
        cleanupPromptBackstoryRouteFixture($fixture);
    }
});

test('router dispatches backstory inspection endpoint', function () {
    $fixture = createPromptBackstoryRouteFixture();

    try {
        $request = (new ServerRequest('GET', '/api/v1/server/backstory'))->withQueryParams(['profile' => 'caelum']);
        $response = $fixture['router']->dispatch($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['profile'])->toBe('caelum');
        expect($body['content'])->toContain('## Backstory');
        expect($body['files'])->toHaveCount(1);
    } finally {
        cleanupPromptBackstoryRouteFixture($fixture);
    }
});