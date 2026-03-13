<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\OpenClawConfig;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Agent\OrchestratorAgent;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\MountDefinition;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-agent-test-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);

    // CredentialResolver reads this file
    file_put_contents($this->workspace . '/.env', '');

    $this->projectRoot = dirname(__DIR__, 3);

    $this->config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);

    $this->roleResolver = new RoleResolver($this->config);

    // Minimal provider stub — never called during construction
    $this->provider = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw new \RuntimeException('Not implemented');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            throw new \RuntimeException('Not implemented');
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            throw new \RuntimeException('Not implemented');
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return false;
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
});

afterEach(function () {
    @unlink($this->workspace . '/.env');
    // Clean mnt/ if created
    $mntDir = $this->workspace . '/mnt';
    if (is_dir($mntDir)) {
        $entries = scandir($mntDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_link($mntDir . '/' . $entry)) {
                unlink($mntDir . '/' . $entry);
            }
        }
        rmdir($mntDir);
    }
    if (is_dir($this->workspace)) {
        rmdir($this->workspace);
    }
});

test('constructs successfully without MountManager', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);
});

test('constructs successfully with empty MountManager', function () {
    $mountManager = new MountManager($this->workspace);

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        mountManager: $mountManager,
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);
});

test('constructs successfully with MountManager and real mounts', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-agent-mount-' . bin2hex(random_bytes(4));
    mkdir($mountDir, 0755, true);

    $mountManager = new MountManager($this->workspace, [
        new MountDefinition($mountDir, 'external', 'rw', 'Test mount'),
    ]);

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        mountManager: $mountManager,
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);

    rmdir($mountDir);
});

test('constructs with null mountManager using null-safe allowedPaths', function () {
    // This specifically tests the line: allowedPaths: $this->mountManager?->allowedPaths() ?? []
    // where mountManager is null — the ?-> should return null, ?? coalesces to []
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        mountManager: null,
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);
});

test('tools returns expected standalone tools', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $toolNames = array_map(fn($t) => $t->name(), $agent->tools());

    expect($toolNames)->toContain('spawn_agent');
    expect($toolNames)->toContain('credentials');
    expect($toolNames)->toContain('package_info');
    expect($toolNames)->toContain('php_execute');
});

test('tools excludes restart_coqui when no onRestart callback', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $toolNames = array_map(fn($t) => $t->name(), $agent->tools());

    expect($toolNames)->not->toContain('restart_coqui');
});

test('tools includes restart_coqui when onRestart callback provided', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        onRestart: fn() => null,
    );

    $toolNames = array_map(fn($t) => $t->name(), $agent->tools());

    expect($toolNames)->toContain('restart_coqui');
});

test('instructions returns non-empty string', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $instructions = $agent->instructions();

    expect($instructions)->toBeString();
    expect($instructions)->not->toBeEmpty();
});

test('instructions include mount storage map when mounts exist', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-agent-mount-' . bin2hex(random_bytes(4));
    mkdir($mountDir, 0755, true);

    $mountManager = new MountManager($this->workspace, [
        new MountDefinition($mountDir, 'datasets', 'ro', 'Training data'),
    ]);

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        mountManager: $mountManager,
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('datasets');

    rmdir($mountDir);
});

test('getSpawnTool returns SpawnAgentTool', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    expect($agent->getSpawnTool()->name())->toBe('spawn_agent');
});
