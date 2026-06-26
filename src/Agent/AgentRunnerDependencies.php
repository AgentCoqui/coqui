<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\ToolUsageTracker;
use CoquiBot\ModManager\ModManagerToolkit;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optional collaborators for {@see AgentRunner}.
 *
 * Groups the runner's many opt-in services into one value object so the
 * constructor takes the required core directly and everything else through
 * a single, named-argument-friendly bag. All fields default to null/false,
 * so callers only set what they need.
 */
final readonly class AgentRunnerDependencies
{
    /**
     * @param \Closure(string):mixed|null $providerResolver Optional override that resolves a provider from a model string.
     */
    public function __construct(
        public ?SkillDiscovery $skillDiscovery = null,
        public ?RoleDiscovery $roleDiscovery = null,
        public bool $unsafeMode = false,
        public bool $backgroundTasksEnabled = false,
        public ?MemoryStore $memoryStore = null,
        public ?MemorySummarizer $memorySummarizer = null,
        public ?MountManager $mountManager = null,
        public ?ConfigManager $configManager = null,
        public ?ConfigGuard $configGuard = null,
        public ?ToolkitVisibilityRegistry $visibilityRegistry = null,
        public ?ModManagerToolkit $modsToolkit = null,
        public ?ArtifactStore $artifactStore = null,
        public ?ProjectStore $projectStore = null,
        public ?DefaultsLoader $defaultsLoader = null,
        public ?TickCallbackInterface $tickCallback = null,
        public ?ToolExecutorInterface $toolExecutor = null,
        public ?HttpClientInterface $httpClient = null,
        public ?ToolkitLoadingRegistry $loadingRegistry = null,
        public ?ToolUsageTracker $usageTracker = null,
        public ?NotificationStore $notificationStore = null,
        public ?\Closure $providerResolver = null,
    ) {
    }
}
