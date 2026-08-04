<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\ApiLifecycleController;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\SessionTitleJobManager;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Api\ScheduleManager;
use CoquiBot\Coqui\Api\WatchJob\ScheduleFileWatchJob;
use CoquiBot\Coqui\Api\WorkspaceWatcher;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\Handler\ArtifactHandler;
use CoquiBot\Coqui\Api\Handler\AuditHandler;
use CoquiBot\Coqui\Api\Handler\BudgetHandler;
use CoquiBot\Coqui\Api\Handler\CommandCatalogHandler;
use CoquiBot\Coqui\Api\Discovery\InstanceInfoBuilder;
use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Api\Handler\CredentialHandler;
use CoquiBot\Coqui\Api\Handler\FileUploadHandler;
use CoquiBot\Coqui\Api\Handler\HealthHandler;
use CoquiBot\Coqui\Api\Handler\LoopHandler as ApiLoopHandler;
use CoquiBot\Coqui\Api\Handler\McpServerHandler;
use CoquiBot\Coqui\Api\Handler\MessageHandler;
use CoquiBot\Coqui\Api\Handler\ProjectHandler;
use CoquiBot\Coqui\Api\Handler\PromptHandler;
use CoquiBot\Coqui\Api\Handler\QuestionHandler;
use CoquiBot\Coqui\Api\Handler\RoleHandler;
use CoquiBot\Coqui\Api\Handler\ScheduleHandler;
use CoquiBot\Coqui\Api\Handler\ServerHandler;
use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\SessionProjectHandler;
use CoquiBot\Coqui\Api\Handler\TaskHandler;
use CoquiBot\Coqui\Api\Handler\ToolkitHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use CoquiBot\Coqui\Api\Middleware\ContentTypeMiddleware;
use CoquiBot\Coqui\Api\Middleware\CorsMiddleware;
use CoquiBot\Coqui\Api\Middleware\RateLimitMiddleware;
use CoquiBot\Coqui\Api\Middleware\RequestSizeMiddleware;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\ModelFamilyResolver;
use CoquiBot\Coqui\Config\ModelMetadataResolver;
use CoquiBot\Coqui\Command\WorkspaceOverrideResolver;
use CoquiBot\Coqui\Notification\NotificationPublisher;
use CoquiBot\Coqui\Notification\NotificationAutomationRunner;
use CoquiBot\Coqui\Notification\RetryBackgroundTaskAction;
use CoquiBot\Coqui\Notification\EscalateLoopFailureAction;
use CoquiBot\Coqui\Provider\ReactHttpClientAdapter;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Agent\GoalEvaluator;
use CoquiBot\Coqui\Agent\StageGateEvaluator;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\ObjectVersionStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\RuntimeStateStore;
use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\PromptInspectionService;
use CoquiBot\Coqui\Support\PersonaSessionLifecycleManager;
use CoquiBot\Coqui\Mcp\McpRuntime;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use CoquiBot\Coqui\Contract\CoquiDefaults;

#[AsCommand(
    name: 'api',
    description: 'Start the Coqui HTTP API server',
)]
final class ApiCommand extends Command
{
    private const RESTART_EXIT_CODE = 10;

    protected function configure(): void
    {
        $this
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen on', (string) CoquiDefaults::API_DEFAULT_PORT)
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind to', '127.0.0.1')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory (project root)', getcwd() ?: '.')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace directory (overrides config and default)')
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Disable script sanitization (dangerous)')
            ->addOption('cors-origin', null, InputOption::VALUE_REQUIRED, 'Allowed CORS origins (comma-separated)', '*');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workDir = is_string($input->getOption('workdir'))
            ? $input->getOption('workdir')
            : (getcwd() ?: '.');
        $host = is_string($input->getOption('host')) ? $input->getOption('host') : '127.0.0.1';

        // Allow COQUI_API_HOST env var to override the default when --host is not explicitly passed
        $envHost = getenv('COQUI_API_HOST');
        if ($host === '127.0.0.1' && is_string($envHost) && $envHost !== '') {
            $host = $envHost;
        }

        $port = is_string($input->getOption('port')) ? $input->getOption('port') : (string) CoquiDefaults::API_DEFAULT_PORT;
        $unsafeMode = (bool) $input->getOption('unsafe')
            || filter_var(getenv('COQUI_UNSAFE'), FILTER_VALIDATE_BOOLEAN);
        $corsOrigin = is_string($input->getOption('cors-origin'))
            ? $input->getOption('cors-origin')
            : '*';

        $output->writeln('<info>Coqui API Server is running!</info>');

        // Boot sequence (headless — no SymfonyStyle)
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $workspaceOverride = WorkspaceOverrideResolver::resolve($input);

        $boot = new BootManager($workDir, $workspaceOverride);
        $result = $boot->boot(io: null, configPath: $configPath);

        if (!$result) {
            $output->writeln('<error>Boot failed — check openclaw.json</error>');
            return Command::FAILURE;
        }

        // Initialize storage
        $dbPath = $boot->workspacePath() . '/data/coqui.db';
        $storage = new SessionStorage($dbPath, auditRedactor: $boot->auditRedactor());
        $uploadStorage = new FileUploadStorage();

        // Read API key from config
        $apiKey = $this->resolveApiKey($boot);
        $isLocalhost = ($host === '127.0.0.1' || $host === 'localhost');

        if ($apiKey === null && !$isLocalhost) {
            // Network-bound without auth — refuse to start
            $output->writeln('<error>No API key configured.</error>');
            $output->writeln('');
            $output->writeln('An API key is required when binding to a network address.');
            $output->writeln('');
            $output->writeln('Set an API key using one of these methods:');
            $output->writeln('  1. Set <fg=cyan>"api.key"</> in your openclaw.json');
            $output->writeln('  2. Set the <fg=cyan>COQUI_API_KEY</> environment variable');
            $output->writeln('  3. Run <fg=cyan>coqui setup</> to generate one automatically');
            $output->writeln('');
            return Command::FAILURE;
        }

        if ($apiKey === null) {
            $output->writeln('<info>No API key configured — running without authentication (localhost only).</info>');
            $output->writeln('');
        } elseif (!$isLocalhost) {
            $output->writeln(sprintf(
                '<comment>WARNING: API will be accessible on the network (%s:%s). Ensure your API key is strong and consider using a reverse proxy with TLS for production.</comment>',
                $host,
                $port,
            ));
            $output->writeln('');
        }

        // Create background task manager + agent turn manager
        $coquiBinPath = realpath(dirname(__DIR__, 2) . '/bin/coqui-console') ?: dirname(__DIR__, 2) . '/bin/coqui-console';
        $maxConcurrentTasks = (int) ($boot->config()->get('api.tasks.maxConcurrent') ?? CoquiDefaults::MAX_CONCURRENT_TASKS);

        // Notification publisher for background task / loop lifecycle events
        $notificationStore = $boot->notificationStore();
        $notificationPublisher = $notificationStore !== null ? new NotificationPublisher($notificationStore) : null;

        $taskManager = new BackgroundTaskManager(
            storage: $storage,
            coquiBinPath: $coquiBinPath,
            configPath: $configPath ?? '',
            workDir: $workDir,
            workspacePath: $boot->workspacePath(),
            maxConcurrent: max(1, $maxConcurrentTasks),
            unsafeMode: $unsafeMode,
            publisher: $notificationPublisher,
        );

        $titleJobManager = new SessionTitleJobManager(
            storage: $storage,
            coquiBinPath: $coquiBinPath,
            configPath: $configPath ?? '',
            workDir: $workDir,
            workspacePath: $boot->workspacePath(),
            unsafeMode: $unsafeMode,
        );

        $turnManager = new AgentTurnManager(
            storage: $storage,
            coquiBinPath: $coquiBinPath,
            configPath: $configPath ?? '',
            workDir: $workDir,
            workspacePath: $boot->workspacePath(),
            unsafeMode: $unsafeMode,
        );

        // Crash recovery: mark orphaned tasks from previous server run as failed
        $orphanCount = $storage->markOrphanedTasksFailed();
        $orphanTurnCount = $storage->markOrphanedTurnProcessesFailed();
        $orphanTitleCount = $storage->requeueOrphanedSessionTitleJobs();
        $totalOrphans = $orphanCount + $orphanTurnCount + $orphanTitleCount;
        if ($totalOrphans > 0) {
            $output->writeln(sprintf('<comment>Recovered %d orphaned process(es) from previous run</comment>', $totalOrphans));
        }

        $startTime = microtime(true);

        // Schedule store (created early for health endpoint)
        $scheduleStore = new ScheduleStore($storage->getPdo());
        $runtimeStateStore = new RuntimeStateStore($storage->getPdo());
        $lifecycle = new ApiLifecycleController(
            runtimeStateStore: $runtimeStateStore,
            managedByLauncher: getenv('COQUI_LAUNCHER_MANAGED') === '1',
            startedAt: Clock::nowUtc(),
            pid: getmypid() ?: 0,
        );
        $lifecycle->markBooted();

        $artifactStore = new ArtifactStore(
            $storage->getPdo(),
            new ArtifactFileService($boot->workspacePath()),
        );

        // Loop + Schedule managers (autonomous execution engines)
        $loopStore = $boot->loopStore();
        $loopDiscovery = $boot->loopDiscovery();
        $projectStore = $boot->projectStore();
        $notificationConfig = $boot->config()->getNotificationConfig();
        $notificationAutomationConfig = $notificationConfig['automation'];

        $scheduleManager = new ScheduleManager($storage, $scheduleStore);
        // Workspace file watcher — polls directories for changes
        $watcher = new WorkspaceWatcher();
        $schedulesDir = $boot->workspacePath() . '/schedules';
        if (!is_dir($schedulesDir)) {
            @mkdir($schedulesDir, CoquiDefaults::DIRECTORY_MODE, true);
        }
        $watcher->register(new ScheduleFileWatchJob($schedulesDir, $scheduleStore));
        $watcher->initialSync();

        $notificationAutomationRunner = null;
        if ($notificationStore !== null && $notificationAutomationConfig['enabled']) {
            $automationHandlers = [
                new RetryBackgroundTaskAction($storage),
            ];

            if ($loopStore !== null) {
                $automationHandlers[] = new EscalateLoopFailureAction($storage, $loopStore);
            }

            $notificationAutomationRunner = new NotificationAutomationRunner(
                store: $notificationStore,
                handlers: $automationHandlers,
                leaseSeconds: $notificationAutomationConfig['leaseSeconds'],
                batchSize: $notificationAutomationConfig['batchSize'],
                maxAttempts: $notificationAutomationConfig['maxAttempts'],
                retryDelaySeconds: $notificationAutomationConfig['retryDelaySeconds'],
            );
        }

        $loopManager = null;
        if ($loopStore !== null && $projectStore !== null) {
            // Resolve utility model provider for goal_bound + gate evaluation.
            $goalEvaluator = null;
            $stageGateEvaluator = null;
            try {
                $factory = $boot->providerFactory(new ReactHttpClientAdapter());
                $utilityModel = $boot->roleResolver()->resolveUtility();
                if ($utilityModel !== '') {
                    $goalEvaluator = new GoalEvaluator($factory->create($utilityModel));
                    $stageGateEvaluator = new StageGateEvaluator($factory->create($utilityModel));
                }
            } catch (\Throwable) {
                // Evaluation degrades gracefully — gate falls back to keyword matching.
            }

            $loopExecutor = new LoopExecutor(
                loopStore: $loopStore,
                projectStore: $projectStore,
                sessionStorage: $storage,
                goalEvaluator: $goalEvaluator,
                stageGateEvaluator: $stageGateEvaluator,
                memoryStore: $boot->memoryStore(),
            );
            $loopManager = new LoopManager($storage, $loopStore, $loopExecutor, $artifactStore, $notificationPublisher);
        }

        // Create handlers
        $healthHandler = new HealthHandler($startTime, $turnManager, $boot->workspacePath(), $dbPath, $taskManager, $loopManager, $scheduleStore, $lifecycle);
        $personaSessionLifecycle = new PersonaSessionLifecycleManager(
            storage: $storage,
            providerFactory: $boot->providerFactory(),
            roleResolver: $boot->roleResolver(),
            memoryStore: $boot->memoryStore(),
            artifactStore: $boot->artifactStore(),
        );

        $sessionHandler = new SessionHandler($storage, $boot->roleResolver(), $boot->personaDiscovery(), $personaSessionLifecycle, artifactStore: $artifactStore);
        $messageHandler = new MessageHandler($storage, $turnManager);
        $turnHandler = new TurnHandler($storage);
        $configHandler = new ConfigHandler(
            $boot->config(),
            new ConfigValidator(),
            $boot->personaDiscovery(),
            new ModelMetadataResolver(
                $boot->defaultsLoader(),
                new ModelFamilyResolver($boot->defaultsLoader()->familyNames()),
                $boot->config(),
                $boot->providerFactory(),
            ),
            $boot->roleResolver(),
            $boot->configManager(),
            new \CoquiBot\Coqui\Config\ConfigGuard(),
            $lifecycle,
            new ObjectVersionStore($storage->getPdo()),
        );
        $credentialHandler = new CredentialHandler($boot->credentialResolver(), $boot->discovery());
        $roleHandler = new RoleHandler($boot->roleDiscovery(), $boot->roleResolver(), $boot->personaDiscovery(), new ObjectVersionStore($storage->getPdo()));
        $taskHandler = new TaskHandler($storage, $taskManager, $boot->roleResolver(), $boot->personaDiscovery(), $projectStore);
        $fileUploadHandler = new FileUploadHandler($storage, $uploadStorage);
        $serverHandler = new ServerHandler($storage, $startTime, $turnManager, $boot->workspacePath(), $dbPath, $taskManager, $loopManager, $lifecycle, $this->buildInstanceInfo($boot, $apiKey));

        $previewRunner = AgentRunnerFactory::create(
            boot: $boot,
            projectRoot: $workDir,
            storage: $storage,
            includeConfigManager: true,
            includeVisibilityRegistry: true,
        );
        $promptInspectionService = new PromptInspectionService($previewRunner, $boot->workspacePath(), $workDir);
        $toolkitHandler = new ToolkitHandler($boot->discovery(), $boot->visibilityRegistry(), $previewRunner);
        $promptHandler = new PromptHandler($promptInspectionService);
        $budgetHandler = new BudgetHandler($previewRunner, $storage);
        $commandCatalogHandler = new CommandCatalogHandler();
        $mcpRuntime = McpRuntime::fromWorkspace(
            $boot->workspacePath(),
            fn (string $key): mixed => $boot->config()->get($key),
        );
        $mcpRuntime->connectEnabled();
        $mcpServerHandler = new McpServerHandler($mcpRuntime->managementService());
        $artifactHandler = new ArtifactHandler($artifactStore, $storage, $projectStore);
        $questionHandler = new QuestionHandler(
            new QuestionPersistence($storage),
            $storage,
        );
        $scheduleHandler = new ScheduleHandler($scheduleStore, $storage);
        $projectHandler = $projectStore !== null ? new ProjectHandler($projectStore, $storage) : null;
        $sessionProjectHandler = $projectStore !== null ? new SessionProjectHandler($storage, $projectStore) : null;
        $auditHandler = new AuditHandler(
            new \CoquiBot\Coqui\Storage\AuditLogStore($storage->getPdo()),
            $storage,
        );

        $loopApiHandler = ($loopStore !== null && $loopDiscovery !== null)
            ? new ApiLoopHandler($loopStore, $loopDiscovery, $loopExecutor ?? null, $storage, $projectStore, $boot->personaDiscovery(), new ObjectVersionStore($storage->getPdo()))
            : null;

        // Build router
        $router = new Router();
        $this->registerRoutes($router, $healthHandler, $sessionHandler, $messageHandler, $turnHandler, $configHandler, $credentialHandler, $roleHandler, $taskHandler, $fileUploadHandler, $serverHandler, $toolkitHandler, $promptHandler, $budgetHandler, $commandCatalogHandler, $mcpServerHandler, $artifactHandler, $questionHandler, $scheduleHandler, $loopApiHandler, $projectHandler, $sessionProjectHandler, $auditHandler);

        // Discover and register API features from installed mods. Failures are
        // isolated so one faulty third-party mod cannot abort API-server boot.
        $coreServices = new \CoquiBot\Coqui\Api\CoreServices($storage, $boot->personaDiscovery(), $boot->config());
        $apiFeatureDiscovery = new \CoquiBot\Coqui\Config\ApiFeatureDiscovery();
        $apiFeatureDiscovery->registerAll(
            $apiFeatureDiscovery->discover(),
            $router,
            $coreServices,
            static function (\CoquiBot\Coqui\Contract\ApiFeatureInterface $feature, \Throwable $e) use ($output): void {
                $output->writeln(sprintf('<comment>Skipped API feature %s: %s</comment>', $feature::class, $e->getMessage()));
            },
        );

        // Boot-time audit: surface exactly which routes are exposed without an
        // API key, so an operator can see the public surface (core + mods) at a glance.
        foreach ($router->publicRoutes() as $publicRoute) {
            $output->writeln(sprintf(
                '<info>Public API route (no auth):</info> %s %s',
                $publicRoute['method'],
                $publicRoute['path'],
            ));
        }

        // Build middleware stack (order: CORS → rate limit → request size → content type → auth)
        $corsOrigins = array_map('trim', explode(',', $corsOrigin));
        $cors = new CorsMiddleware($corsOrigins);

        // Rate limiting: configurable via openclaw.json, default 30 req/min
        $rateLimitConfig = $boot->config()->get('api.rateLimit', []);
        $rateLimitMax = is_array($rateLimitConfig) ? (int) ($rateLimitConfig['maxRequests'] ?? 30) : 30;
        $rateLimitWindow = is_array($rateLimitConfig) ? (int) ($rateLimitConfig['windowSeconds'] ?? 60) : 60;

        $middlewareStack = [
            $cors,
            new RateLimitMiddleware($rateLimitMax, $rateLimitWindow),
            new RequestSizeMiddleware(),
            new ContentTypeMiddleware(),
        ];

        if ($apiKey !== null) {
            $middlewareStack[] = new AuthMiddleware(
                $apiKey,
                static fn (string $path): bool => $router->isPublicPath($path),
            );
        }

        foreach ($middlewareStack as $mw) {
            $router->addMiddleware($mw);
        }

        // Create ReactPHP HTTP server with explicit middleware for file upload support.
        // The default auto-registered middleware caps body at 64 KiB which is too small
        // for file uploads. We configure a 50 MiB buffer and multipart parser.
        $maxUploadBytes = CoquiDefaults::MAX_UPLOAD_FILE_SIZE; // 50 MiB
        $server = new HttpServer(
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(100),
            new RequestBodyBufferMiddleware($maxUploadBytes),
            new RequestBodyParserMiddleware($maxUploadBytes, 20),
            function (\Psr\Http\Message\ServerRequestInterface $request) use ($router, $output): \Psr\Http\Message\ResponseInterface {
                $method = $request->getMethod();
                $path = $request->getUri()->getPath();
                $output->writeln(sprintf(
                    '<fg=gray>[%s]</> %s %s',
                    date('H:i:s'),
                    $method,
                    $path,
                ), OutputInterface::VERBOSITY_VERBOSE);

                return $router->dispatch($request);
            },
        );

        $listenAddress = "{$host}:{$port}";
        $context = ['socket' => ['so_reuseaddr' => true]];
        $socket = new SocketServer($listenAddress, $context);

        $lifecycle->configureRestartHandler(function (string $reason) use ($output, $socket, $taskManager, $titleJobManager, $turnManager): void {
            Loop::addTimer(0.15, function () use ($reason, $output, $socket, $taskManager, $titleJobManager, $turnManager): void {
                $output->writeln(sprintf('<comment>Restart requested: %s</comment>', $reason));
                $turnManager->shutdown();
                $titleJobManager->shutdown();
                $taskManager->shutdown();
                $socket->close();
                exit(self::RESTART_EXIT_CODE);
            });
        });

        // Graceful shutdown on SIGTERM/SIGINT — close socket + stop event loop
        // SIGINT (2) = direct Ctrl+C — show shutdown message (standalone mode)
        // SIGTERM (15) = sent by launcher — stay silent (launcher owns the UX)
        $shutdownHandler = static function (int $signal) use ($socket, $output, $taskManager, $titleJobManager, $turnManager): void {
            $output->writeln('');
            if ($signal === 2) {
                $output->writeln('');
                $output->writeln(' <info>[INFO] Shutting down Coqui.</info>');
                $output->writeln('');
            }
            $turnManager->shutdown();
            $titleJobManager->shutdown();
            $taskManager->shutdown();
            $socket->close();
            Loop::stop();
        };

        if (defined('SIGTERM')) {
            Loop::addSignal(SIGTERM, $shutdownHandler);
        }
        if (defined('SIGINT')) {
            Loop::addSignal(SIGINT, $shutdownHandler);
        }
        if (defined('SIGHUP')) {
            Loop::addSignal(SIGHUP, $shutdownHandler);
        }

        $output->writeln('');
        $output->writeln(sprintf('Listening on <info>http://%s</info>', $listenAddress));
        $output->writeln(sprintf('Server: <fg=gray>%s</>', $workDir));
        $output->writeln(sprintf('Workspace: <fg=gray>%s</>', $boot->workspacePath()));
        $output->writeln(sprintf('PID: <fg=gray>%s</>', getmypid()));
        $output->writeln('');

        if ($unsafeMode) {
            $output->writeln('<comment>WARNING: Running in unsafe mode — script sanitization disabled</comment>');
        }

        $output->writeln('Press Ctrl+C to stop.');
        $output->writeln('');

        $server->listen($socket);

        // Periodic timer: tick the background task manager every second
        Loop::addPeriodicTimer(1.0, static function () use ($taskManager): void {
            $taskManager->tick();
        });

        Loop::addPeriodicTimer(1.0, static function () use ($titleJobManager): void {
            $titleJobManager->tick();
        });

        // Periodic timer: reap finished turn processes
        Loop::addPeriodicTimer(1.0, static function () use ($turnManager): void {
            $turnManager->tick();
        });

        if ($notificationAutomationRunner !== null) {
            Loop::addPeriodicTimer((float) $notificationAutomationConfig['processTickSeconds'], static function () use ($notificationAutomationRunner): void {
                $notificationAutomationRunner->tick();
            });

            Loop::addPeriodicTimer((float) $notificationAutomationConfig['reclaimTickSeconds'], static function () use ($notificationAutomationRunner): void {
                $notificationAutomationRunner->reclaim();
            });
        }

        // Periodic timer: evaluate due schedules every 60 seconds
        Loop::addPeriodicTimer(60.0, static function () use ($scheduleManager): void {
            $scheduleManager->tick();
        });

        // Periodic timer: reconcile completed schedule tasks every 10 seconds
        Loop::addPeriodicTimer(10.0, static function () use ($scheduleManager): void {
            $scheduleManager->reconcile();
        });

        // Periodic timer: workspace file watcher every 10 seconds
        Loop::addPeriodicTimer(10.0, static function () use ($watcher): void {
            $watcher->tick();
        });

        // Periodic timer: advance running loops every 5 seconds
        if ($loopManager !== null) {
            Loop::addPeriodicTimer(5.0, static function () use ($loopManager): void {
                $loopManager->tick();
            });

            // Periodic timer: reconcile completed loop stage tasks every 3 seconds
            Loop::addPeriodicTimer(3.0, static function () use ($loopManager): void {
                $loopManager->reconcile();
            });
        }

        return Command::SUCCESS;
    }

    /**
     * Register all API routes on the router.
     *
    * Routes are registered here as the canonical API surface for sessions,
    * orchestration resources, config inspection, and operator controls.
     */
    private function registerRoutes(
        Router $router,
        HealthHandler $health,
        SessionHandler $session,
        MessageHandler $message,
        TurnHandler $turn,
        ConfigHandler $config,
        CredentialHandler $credential,
        RoleHandler $role,
        TaskHandler $task,
        FileUploadHandler $fileUpload,
        ServerHandler $server,
        ToolkitHandler $toolkit,
        PromptHandler $prompt,
        BudgetHandler $budget,
        CommandCatalogHandler $commands,
        McpServerHandler $mcp,
        ArtifactHandler $artifact,
        QuestionHandler $question,
        ScheduleHandler $schedule,
        ?ApiLoopHandler $loop,
        ?ProjectHandler $project,
        ?SessionProjectHandler $sessionProject,
        AuditHandler $audit,
    ): void {
        $v1 = '/api/v1';

        // Health (public — no API key required so liveness probes work unauthenticated)
        $router->addPublicRoute('GET', $v1 . '/health', $health);

        // Sessions
        $router->get($v1 . '/sessions', [$session, 'list']);
        $router->post($v1 . '/sessions', [$session, 'create']);
        $router->post($v1 . '/sessions/resolve', [$session, 'resolve']);
        $router->get($v1 . '/sessions/{id}', [$session, 'get']);
        $router->get($v1 . '/sessions/{id}/summary', [$session, 'summary']);
        $router->patch($v1 . '/sessions/{id}', [$session, 'update']);
        $router->delete($v1 . '/sessions/{id}', [$session, 'delete']);
        $router->get($v1 . '/sessions/{id}/members', [$session, 'members']);
        $router->put($v1 . '/sessions/{id}/members', [$session, 'replaceMembers']);
        $router->post($v1 . '/sessions/{id}/members', [$session, 'addMember']);
        $router->delete($v1 . '/sessions/{id}/members/{persona}', [$session, 'removeMember']);
        if ($sessionProject !== null) {
            $router->get($v1 . '/sessions/{id}/project', [$sessionProject, 'get']);
            $router->patch($v1 . '/sessions/{id}/project', [$sessionProject, 'update']);
        }

        // Structured questions (core authenticated — never public)
        $router->get($v1 . '/sessions/{id}/questions', [$question, 'list']);
        $router->post($v1 . '/sessions/{id}/questions/{questionId}/answer', [$question, 'answer']);

        // Messages
        $router->get($v1 . '/sessions/{id}/messages', [$message, 'list']);
        $router->post($v1 . '/sessions/{id}/messages', [$message, 'send']);
        $router->delete($v1 . '/sessions/{id}/messages/{messageId}', [$message, 'delete']);

        // File uploads
        $router->post($v1 . '/sessions/{id}/files', [$fileUpload, 'upload']);
        $router->get($v1 . '/sessions/{id}/files/{fileId}', [$fileUpload, 'get']);

        // Turns
        $router->get($v1 . '/sessions/{id}/turns', [$turn, 'list']);
        $router->get($v1 . '/sessions/{id}/turns/{turnId}', [$turn, 'get']);
        $router->get($v1 . '/sessions/{id}/turns/{turnId}/events', [$turn, 'events']);

        // Audit log — authenticated routes only (API-key middleware). Registers
        // both the global GET /audit and the session-scoped GET
        // /sessions/{id}/audit. The latter is placed with the session routes; its
        // literal third segment cannot be shadowed by any sibling here — every
        // {param} compiles to a single anchored segment, and no session route has
        // a param in that position.
        $audit->register($router);

        // Config (read-oriented plus narrow safe context mutation)
        $router->get($v1 . '/config', [$config, 'get']);
        $router->get($v1 . '/config/context', [$config, 'getContext']);
        $router->patch($v1 . '/config/context', [$config, 'updateContext']);
        $router->post($v1 . '/config/validate', [$config, 'validate']);
        $router->get($v1 . '/config/models', [$config, 'models']);
        $router->get($v1 . '/config/personas', [$config, 'personas']);
        $router->get($v1 . '/config/persona-preferences/schema', [$config, 'personaPreferenceSchema']);
        $router->get($v1 . '/config/personas/{name}', [$config, 'persona']);

        // Roles (create/update via PUT; delete is REPL-only)
        $router->get($v1 . '/config/roles', [$role, 'list']);
        $router->get($v1 . '/config/roles/{name}', [$role, 'get']);
        $router->get($v1 . '/roles', [$role, 'list']);
        $router->get($v1 . '/roles/{name}', [$role, 'get']);
        $router->put($v1 . '/roles/{name}', [$role, 'put']);
        $router->get($v1 . '/personas', [$config, 'personas']);
        $router->get($v1 . '/personas/{name}', [$config, 'persona']);
        $router->post($v1 . '/personas', [$config, 'createPersona']);
        $router->patch($v1 . '/personas/{name}', [$config, 'updatePersona']);
        $router->delete($v1 . '/personas/{name}', [$config, 'deletePersona']);

        // Credentials
        $router->get($v1 . '/credentials', [$credential, 'list']);
        $router->post($v1 . '/credentials', [$credential, 'set']);
        $router->get($v1 . '/credentials/requirements', [$credential, 'requirements']);
        $router->delete($v1 . '/credentials/{key}', [$credential, 'delete']);

        // Background tasks
        $router->post($v1 . '/tasks', [$task, 'create']);
        $router->get($v1 . '/tasks', [$task, 'list']);
        $router->get($v1 . '/tasks/{id}', [$task, 'get']);
        $router->get($v1 . '/tasks/{id}/events', [$task, 'events']);
        $router->post($v1 . '/tasks/{id}/input', [$task, 'addInput']);
        $router->post($v1 . '/tasks/{id}/cancel', [$task, 'cancel']);
        if ($project !== null) {
            $router->post($v1 . '/projects', [$project, 'create']);
            $router->get($v1 . '/projects', [$project, 'list']);
            $router->get($v1 . '/projects/{idOrSlug}', [$project, 'get']);
            $router->patch($v1 . '/projects/{idOrSlug}', [$project, 'update']);
            $router->delete($v1 . '/projects/{idOrSlug}', [$project, 'delete']);
            $router->post($v1 . '/projects/{idOrSlug}/archive', [$project, 'archive']);
            $router->post($v1 . '/projects/{idOrSlug}/activate', [$project, 'activate']);
        }

        // Child runs
        $router->get($v1 . '/sessions/{id}/child-runs', [$session, 'childRuns']);

        // Server
        $router->get($v1 . '/server/info', [$server, 'info']);
        $router->get($v1 . '/server/instance', [$server, 'instance']);
        $router->get($v1 . '/server/stats', [$server, 'stats']);
        $router->post($v1 . '/server/restart', [$server, 'restart']);
        $router->get($v1 . '/server/prompt', [$prompt, 'get']);
        $router->get($v1 . '/server/budget', [$budget, 'get']);
        $router->get($v1 . '/sessions/{id}/budget', [$budget, 'session']);
        $router->get($v1 . '/server/commands', [$commands, 'get']);

        // Artifacts
        $router->post($v1 . '/sessions/{id}/artifacts', [$artifact, 'create']);
        $router->get($v1 . '/sessions/{id}/artifacts', [$artifact, 'list']);
        $router->get($v1 . '/sessions/{id}/artifacts/{artifactId}', [$artifact, 'get']);
        $router->patch($v1 . '/sessions/{id}/artifacts/{artifactId}', [$artifact, 'update']);
        $router->delete($v1 . '/sessions/{id}/artifacts/{artifactId}', [$artifact, 'delete']);

        // Toolkit visibility management
        $router->get($v1 . '/toolkits', [$toolkit, 'list']);
        $router->post($v1 . '/toolkits/visibility', [$toolkit, 'setVisibility']);

        // MCP servers
        $mcp->register($router);

        // Schedules
        $router->post($v1 . '/schedules', [$schedule, 'create']);
        $router->get($v1 . '/schedules', [$schedule, 'list']);
        $router->get($v1 . '/schedules/upcoming', [$schedule, 'upcoming']);
        $router->get($v1 . '/schedules/stats', [$schedule, 'stats']);
        $router->get($v1 . '/schedules/{id}', [$schedule, 'get']);
        $router->get($v1 . '/schedules/{id}/runs', [$schedule, 'runs']);
        $router->patch($v1 . '/schedules/{id}', [$schedule, 'update']);
        $router->delete($v1 . '/schedules/{id}', [$schedule, 'delete']);
        $router->post($v1 . '/schedules/{id}/enable', [$schedule, 'enable']);
        $router->post($v1 . '/schedules/{id}/disable', [$schedule, 'disable']);
        $router->post($v1 . '/schedules/{id}/trigger', [$schedule, 'trigger']);

        // Loops
        $loop?->register($router);
    }

    /**
     * Assemble the aggregated CAP InstanceInfo builder from live runtime sources.
     *
     * Required fields are always present; optional fields are omitted when their
     * source is unavailable. `auth` is emitted only when the surface is network-
     * bound (an API key is configured) — embedded/no-auth omits it entirely, and
     * `profiles` is an OPEN set (never allowlist-filtered).
     */
    private function buildInstanceInfo(BootManager $boot, ?string $apiKey): InstanceInfoBuilder
    {
        $config = $boot->config();

        // Portable built-in profiles this variant implements (open set). `remote`
        // is advertised only when the surface is network-bound (an API key exists).
        $profiles = ['artifacts', 'questions', 'skills', 'schedules', 'mcp'];
        if ($apiKey !== null) {
            $profiles[] = 'remote';
        }

        $personaCount = count($boot->personaDiscovery()->discoverAll());

        $modelResolver = new ModelMetadataResolver(
            $boot->defaultsLoader(),
            new ModelFamilyResolver($boot->defaultsLoader()->familyNames()),
            $config,
            $boot->providerFactory(),
        );
        $models = array_values($modelResolver->configuredModels());

        $primary = $config->get('agents.defaults.model.primary');
        $defaultModel = is_string($primary) && $primary !== '' ? $primary : null;

        return new InstanceInfoBuilder(
            profiles: $profiles,
            bindings: ['in-process', 'http-sse'],
            personaCount: $personaCount,
            defaultModel: $defaultModel,
            models: $models,
            // Portable built-in toolkits; native host toolkits are absent (⇒ none).
            builtinToolkits: ['shell', 'fs', 'web'],
            // Only the stdio transport class exists today.
            mcpTransports: ['stdio'],
            // Omit auth entirely when embedded/no-key; require it when a key is set.
            authRequired: $apiKey !== null ? true : null,
            limits: [
                'max_page_size' => \CoquiBot\Coqui\Api\CursorPage::MAX_LIMIT,
                'max_payload_bytes' => CoquiDefaults::MAX_UPLOAD_FILE_SIZE,
                'max_content_bytes' => CoquiDefaults::MAX_UPLOAD_FILE_SIZE,
            ],
            api: ['base_path' => '/api/v1', 'api_major' => '1'],
        );
    }

    /**
     * Resolve the API key from config or environment.
     */
    private function resolveApiKey(BootManager $boot): ?string
    {
        // Check openclaw.json: api.key
        $configKey = $boot->config()->get('api.key');

        if (is_string($configKey) && $configKey !== '') {
            return $configKey;
        }

        // Check environment
        $envKey = getenv('COQUI_API_KEY');

        if (is_string($envKey) && $envKey !== '') {
            return $envKey;
        }

        // Check workspace .env
        $credKey = $boot->credentialResolver()->get('COQUI_API_KEY');

        if ($credKey !== null && $credKey !== '') {
            return $credKey;
        }

        return null;
    }

}
