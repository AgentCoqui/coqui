<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Agent\OrchestratorAgent;
use CoquiBot\Coqui\Agent\OrchestratorDependencies;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Config\ToolProfileResolver;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;
use CoquiBot\Coqui\Memory\MemoryEntry;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Shared, MCP-free harness for the Lean Default plan (Tasks 3–8).
 *
 * Builds a real OrchestratorAgent on an offline provider (ollama/qwen3:latest
 * with a localhost base URL — never called in prompt-only assertions) so that
 * profile-aware toolkit deferral can be observed through the agent's public
 * accessors. Deliberately does NOT register any MCP discovery entry so the
 * harness stays usable while coqui-toolkit-mcp-client is unavailable.
 *
 * @param array<string, mixed> $configOverrides Dot-notation config overrides merged over the base.
 * @param list<string>         $pinEager        Toolkit basenames to pin Eager before boot.
 * @param list<string>         $seedMemories    Memory contents seeded for passive-recall assertions.
 */
function makeOrchestrator(
    array $configOverrides = [],
    array $pinEager = [],
    array $seedMemories = [],
): OrchestratorAgent {
    $chatModel = 'ollama/qwen3:latest';

    $workspacePath = sys_get_temp_dir() . '/coqui-lean-harness-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    file_put_contents($workspacePath . '/.env', '');

    $projectRoot = dirname(__DIR__, 3);

    $base = [
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => $chatModel,
                ],
                'roles' => [
                    'orchestrator' => $chatModel,
                ],
            ],
        ],
        'models' => [
            'providers' => [
                'ollama' => ['baseUrl' => 'http://localhost:11434/v1'],
            ],
        ],
    ];

    $config = OpenClawConfig::fromArray(leanHarnessMergeConfig($base, $configOverrides));

    $discovery = new ToolkitDiscovery(
        projectRoot: $projectRoot,
        workspacePath: $workspacePath,
    );

    // Construct the registry with the profile-resolved core set as its second
    // argument. Otherwise every SYSTEM_TOOLKITS entry stays "system" and
    // setMode(..., Eager) would throw for the non-core toolkits we pin.
    $loadingRegistry = new ToolkitLoadingRegistry(
        $workspacePath,
        (new ToolProfileResolver($config))->coreToolkits(),
    );

    foreach ($pinEager as $basename) {
        $loadingRegistry->setMode($basename, ToolkitLoadingMode::Eager);
    }

    // Storage-backed stores so the store-gated built-ins (Memory, Schedule,
    // Loop, Project, Artifact) are actually constructed — and therefore
    // observable as deferred — under the lean profile.
    $storage = new SessionStorage($workspacePath . '/data/sessions.db');
    $sessionId = $storage->createSession('orchestrator', $chatModel);
    $projectStore = new ProjectStore($storage->getPdo());
    $roleDiscovery = new RoleDiscovery($workspacePath, $projectRoot);

    $memoryStore = new MemoryStore($workspacePath . '/data/memory.db');
    $memorySummarizer = new MemorySummarizer($memoryStore);
    foreach ($seedMemories as $content) {
        $memoryStore->save(new MemoryEntry(
            content: $content,
            area: 'preferences',
            metadata: ['importance' => 0.95],
        ));
    }

    $provider = (new ProviderFactory($config))->create($chatModel);

    // ConfigManager backs the agent-facing `config` tool — one of the always-core
    // standalone tools under the lean profile. Without it, ConfigTool is never
    // constructed and 'config' can never appear in tools(), independent of profile.
    $configManager = new ConfigManager($workspacePath, $projectRoot, new DefaultsLoader());

    return new OrchestratorAgent(
        provider: $provider,
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: $projectRoot,
        workspacePath: $workspacePath,
        deps: new OrchestratorDependencies(
            discovery: $discovery,
            loadingRegistry: $loadingRegistry,
            storage: $storage,
            sessionId: $sessionId,
            projectStore: $projectStore,
            roleDiscovery: $roleDiscovery,
            memoryStore: $memoryStore,
            memorySummarizer: $memorySummarizer,
            configManager: $configManager,
        ),
    );
}

/**
 * Merge dot-notation overrides into a nested base config array.
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $overrides Keys may be dot-notation paths.
 * @return array<string, mixed>
 */
function leanHarnessMergeConfig(array $base, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (!str_contains($key, '.')) {
            $base[$key] = $value;
            continue;
        }

        $segments = explode('.', $key);
        $cursor = &$base;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        unset($cursor);
    }

    return $base;
}
