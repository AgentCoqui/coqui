<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\AgentRunnerDependencies;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Provider\ReactHttpClientAdapter;
use CoquiBot\Coqui\Storage\SessionStorage;
use SplObserver;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AgentRunnerFactory
{
    public static function create(
        BootManager $boot,
        string $projectRoot,
        SessionStorage $storage,
        ?SplObserver $observer = null,
        bool $unsafeMode = false,
        bool $backgroundTasksEnabled = false,
        bool $includeConfigManager = false,
        bool $includeVisibilityRegistry = false,
        bool $includeLoadingData = false,
        ?TickCallbackInterface $tickCallback = null,
        ?ToolExecutorInterface $toolExecutor = null,
        ?HttpClientInterface $httpClient = null,
    ): AgentRunner {
        $httpClient = $httpClient ?? new ReactHttpClientAdapter();
        $providerFactory = $boot->providerFactory($httpClient);

        return new AgentRunner(
            roleResolver: $boot->roleResolver(),
            config: $boot->config(),
            projectRoot: $projectRoot,
            workspacePath: $boot->workspacePath(),
            storage: $storage,
            observer: $observer,
            discovery: $boot->discovery(),
            blacklist: $boot->blacklist(),
            credentialResolver: $boot->credentialResolver(),
            providerFactory: $providerFactory,
            deps: new AgentRunnerDependencies(
                skillDiscovery: $boot->skillDiscovery(),
                roleDiscovery: $boot->roleDiscovery(),
                unsafeMode: $unsafeMode,
                backgroundTasksEnabled: $backgroundTasksEnabled,
                memoryStore: $boot->memoryStore(),
                memorySummarizer: $boot->memorySummarizer(),
                mountManager: $boot->mountManager(),
                configManager: $includeConfigManager ? $boot->configManager() : null,
                configGuard: $includeConfigManager ? new ConfigGuard() : null,
                visibilityRegistry: $includeVisibilityRegistry ? $boot->visibilityRegistry() : null,
                modsToolkit: $boot->modsToolkit(),
                todoStore: $boot->todoStore(),
                artifactStore: $boot->artifactStore(),
                projectStore: $boot->projectStore(),
                defaultsLoader: $boot->defaultsLoader(),
                tickCallback: $tickCallback,
                toolExecutor: $toolExecutor,
                httpClient: $httpClient,
                loadingRegistry: $includeLoadingData ? $boot->loadingRegistry() : null,
                usageTracker: $includeLoadingData ? $boot->usageTracker() : null,
                notificationStore: $boot->notificationStore(),
            ),
        );
    }
}