<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\ModelFamilyResolver;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\ToolUsageTracker;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;
use CoquiBot\ModManager\ModManagerToolkit;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use SplObserver;

/**
 * Optional collaborators and context for {@see OrchestratorAgent}.
 *
 * Groups the agent's many opt-in services, session/turn context, and tuning
 * knobs into one named-argument-friendly value object. The agent's required
 * core (provider, role resolver, config, project root, workspace path) stays
 * as direct constructor parameters; everything else flows through here. Some
 * fields are forwarded to {@see \CarmeloSantana\PHPAgents\Agent\AbstractAgent}'s
 * constructor; the rest are stored on the agent. All fields default, so callers
 * pass only what they need.
 */
final readonly class OrchestratorDependencies
{
    /**
     * @param \Closure():void|null $onRestart Optional callback that restarts the host process.
     */
    public function __construct(
        // --- forwarded to parent::__construct() or consumed locally during setup ---
        public ?ToolkitDiscovery $discovery = null,
        public int $maxIterations = AbstractAgent::DEFAULT_MAX_ITERATIONS,
        public ?ToolExecutionPolicyInterface $executionPolicy = null,
        public ?\Closure $onRestart = null,
        public ?CredentialResolverInterface $credentialResolver = null,
        public ?CancellationTokenInterface $cancellationToken = null,
        public ?PendingInputProviderInterface $pendingInputProvider = null,
        public ?BackgroundTaskToolkit $backgroundTaskToolkit = null,
        public ?ConfigManager $configManager = null,
        public ?ConfigGuard $configGuard = null,
        public ?ToolExecutorInterface $toolExecutor = null,
        public ?TickCallbackInterface $tickCallback = null,
        // --- stored on the agent ---
        public ?SessionStorage $storage = null,
        public ?string $sessionId = null,
        public ?string $currentTurnId = null,
        public ?SplObserver $observer = null,
        public ?ScriptSanitizer $sanitizer = null,
        public ?SkillDiscovery $skillDiscovery = null,
        public ?RoleDiscovery $roleDiscovery = null,
        public ?MemoryStore $memoryStore = null,
        public ?MemorySummarizer $memorySummarizer = null,
        public ?MountManager $mountManager = null,
        public ?ToolkitVisibilityRegistry $visibilityRegistry = null,
        public ?ModManagerToolkit $modsToolkit = null,
        public ?string $activeRole = null,
        public ?ProjectStore $projectStore = null,
        public ?DefaultsLoader $defaultsLoader = null,
        public ?ModelFamilyResolver $familyResolver = null,
        public bool $unsafeMode = false,
        public ?HttpClientInterface $httpClient = null,
        public ?ToolkitLoadingRegistry $loadingRegistry = null,
        public ?ProviderFactory $providerFactory = null,
        public ?ToolUsageTracker $usageTracker = null,
        public ?string $workScopeSessionId = null,
        public ?string $defaultProjectId = null,
        public ?string $defaultSprintId = null,
        public float $budgetExitThreshold = 0.0,
        public int $budgetExitWrapUpIterations = 2,
        public ?string $activeProfile = null,
        public ?string $activeProfilePath = null,
        public ?ProfilePreferences $profilePreferences = null,
    ) {
    }
}
