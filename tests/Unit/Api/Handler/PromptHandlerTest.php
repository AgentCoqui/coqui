<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\Handler\PromptHandler;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\PromptInspectionService;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use React\Http\Message\ServerRequest;

function makePromptHandlerCredentialResolver(string $workspacePath): CredentialResolverInterface
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

function createPromptHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-prompt-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/data', 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    file_put_contents($workspacePath . '/.env', '');
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($workspacePath . '/profiles/caelum/preferences.json', json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
        ],
    ], JSON_THROW_ON_ERROR));

    $dbPath = sys_get_temp_dir() . '/coqui-prompt-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);
    $credentialResolver = makePromptHandlerCredentialResolver($workspacePath);
    $projectRoot = dirname(__DIR__, 4);

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

    $inspectionService = new PromptInspectionService($runner, $workspacePath, $projectRoot);

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'handler' => new PromptHandler($inspectionService),
    ];
}

function cleanupPromptHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('prompt handler exposes source-aware file and folder breakdowns', function () {
    $fixture = createPromptHandlerFixture();

    try {
        $response = $fixture['handler']->get(
            (new ServerRequest('GET', '/api/v1/server/prompt'))->withQueryParams(['profile' => 'caelum'])
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['profile'])->toBe('caelum');
        expect($body['role'])->toBe('orchestrator');
        expect($body['resolved_model'])->toBe('ollama/qwen3:latest');
        expect($body['prompt'])->toContain('Caelum');
        expect($body['budget']['prompt_sections'])->not->toBeEmpty();
        expect($body['prompt_sources']['files'])->not->toBeEmpty();
        expect($body['prompt_sources']['folders'])->not->toBeEmpty();
        expect($body['prompt_sources']['last_modified_at'])->not->toBeNull();
        expect($body['prompt_sources']['file_backed_tokens'])->toBeGreaterThan(0);

        $workspaceFilePaths = array_map(
            static fn(array $entry): string => $entry['scope'] . ':' . $entry['path'],
            $body['prompt_sources']['files'],
        );
        $workspaceFolderPaths = array_map(
            static fn(array $entry): string => $entry['scope'] . ':' . $entry['path'],
            $body['prompt_sources']['folders'],
        );
        $hasProjectPromptFile = false;
        foreach ($workspaceFilePaths as $path) {
            if (str_starts_with($path, 'project:prompts/')) {
                $hasProjectPromptFile = true;
                break;
            }
        }

        expect($workspaceFilePaths)->toContain('workspace:profiles/caelum/soul.md');
        expect($workspaceFilePaths)->toContain('workspace:profiles/caelum/preferences.json');
        expect($hasProjectPromptFile)->toBeTrue();
        expect($workspaceFolderPaths)->toContain('workspace:profiles/caelum');
    } finally {
        cleanupPromptHandlerFixture($fixture);
    }
});

test('prompt handler exposes effective profile policy summary', function () {
    $fixture = createPromptHandlerFixture();

    try {
        file_put_contents($fixture['workspacePath'] . '/profiles/caelum/preferences.json', json_encode([
            'prompts' => [
                'features' => [
                    'loops' => false,
                ],
                'prompt_sections' => [
                    'tools' => 'stub',
                ],
                'roles' => [
                    'allow' => ['orchestrator'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $response = $fixture['handler']->get(
            (new ServerRequest('GET', '/api/v1/server/prompt'))->withQueryParams(['profile' => 'caelum'])
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['profile_policy']['tools_stubbed'])->toBeTrue();
        expect($body['profile_policy']['features']['loops'])->toBeFalse();
        expect($body['profile_policy']['roles']['allow'])->toBe(['orchestrator']);
        expect($body['profile_policy']['excluded_tool_prompt_slugs'])->toContain('loops');
    } finally {
        cleanupPromptHandlerFixture($fixture);
    }
});
