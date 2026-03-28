<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\Handler\ArtifactHandler;
use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Api\Handler\CredentialHandler;
use CoquiBot\Coqui\Api\Handler\FileUploadHandler;
use CoquiBot\Coqui\Api\Handler\HealthHandler;
use CoquiBot\Coqui\Api\Handler\MessageHandler;
use CoquiBot\Coqui\Api\Handler\PromptHandler;
use CoquiBot\Coqui\Api\Handler\RoleHandler;
use CoquiBot\Coqui\Api\Handler\ScheduleHandler;
use CoquiBot\Coqui\Api\Handler\ServerHandler;
use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\SummarizeHandler;
use CoquiBot\Coqui\Api\Handler\TaskHandler;
use CoquiBot\Coqui\Api\Handler\ToolkitHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Api\Handler\WebhookHandler;
use CoquiBot\Coqui\Api\Handler\WebhookManagementHandler;
use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use CoquiBot\Coqui\Api\Middleware\ContentTypeMiddleware;
use CoquiBot\Coqui\Api\Middleware\CorsMiddleware;
use CoquiBot\Coqui\Api\Middleware\RateLimitMiddleware;
use CoquiBot\Coqui\Api\Middleware\RequestSizeMiddleware;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\ScheduleManager;
use CoquiBot\Coqui\Api\Webhook\WebhookVerifierRegistry;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
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
    protected function configure(): void
    {
        $this
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen on', '3300')
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

        $port = is_string($input->getOption('port')) ? $input->getOption('port') : '3300';
        $unsafeMode = (bool) $input->getOption('unsafe')
            || filter_var(getenv('COQUI_UNSAFE'), FILTER_VALIDATE_BOOLEAN);
        $corsOrigin = is_string($input->getOption('cors-origin'))
            ? $input->getOption('cors-origin')
            : '*';

        $output->writeln('<info>Coqui API Server is running!</info>');

        // Boot sequence (headless — no SymfonyStyle)
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $workspaceOverride = $this->resolveWorkspaceOverride($input);

        $boot = new BootManager($workDir, $workspaceOverride);
        $result = $boot->boot(io: null, configPath: $configPath);

        if (!$result) {
            $output->writeln('<error>Boot failed — check openclaw.json</error>');
            return Command::FAILURE;
        }

        // Initialize storage
        $dbPath = $boot->workspacePath() . '/data/coqui.db';
        $storage = new SessionStorage($dbPath);
        $uploadStorage = new FileUploadStorage($boot->workspacePath());

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
        $coquiBinPath = realpath(dirname(__DIR__, 2) . '/bin/coqui') ?: dirname(__DIR__, 2) . '/bin/coqui';
        $maxConcurrentTasks = (int) ($boot->config()->get('api.tasks.maxConcurrent') ?? CoquiDefaults::MAX_CONCURRENT_TASKS);

        $taskManager = new BackgroundTaskManager(
            storage: $storage,
            coquiBinPath: $coquiBinPath,
            configPath: $configPath ?? '',
            workDir: $workDir,
            workspacePath: $boot->workspacePath(),
            maxConcurrent: max(1, $maxConcurrentTasks),
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
        $totalOrphans = $orphanCount + $orphanTurnCount;
        if ($totalOrphans > 0) {
            $output->writeln(sprintf('<comment>Recovered %d orphaned process(es) from previous run</comment>', $totalOrphans));
        }

        $startTime = microtime(true);

        // Restart flag — set by ConfigHandler when config is updated via API
        $restartRequested = false;

        /** @var SocketServer|null $socket Pre-declared for closure capture; assigned after server creation. */
        $socket = null;

        // Schedule & webhook stores (created early for health endpoint)
        $scheduleStore = new ScheduleStore($storage->getPdo());
        $webhookStore = new WebhookStore($storage->getPdo());

        // Create handlers
        $healthHandler = new HealthHandler($startTime, $turnManager, $taskManager, $scheduleStore, $webhookStore);
        $sessionHandler = new SessionHandler($storage, $boot->roleResolver());
        $messageHandler = new MessageHandler($storage, $turnManager, $uploadStorage);
        $turnHandler = new TurnHandler($storage);
        $configHandler = new ConfigHandler(
            $boot->config(),
            $boot->configManager(),
            new ConfigValidator(),
            onRestart: function () use (&$restartRequested, &$socket, $output, $taskManager, $turnManager): void {
                $restartRequested = true;
                $output->writeln('');
                $output->writeln('<comment>Config updated via API — scheduling restart...</comment>');
                // Defer shutdown to next tick so the HTTP response is sent first
                Loop::futureTick(static function () use (&$socket, $taskManager, $turnManager): void {
                    $turnManager->shutdown();
                    $taskManager->shutdown();
                    if ($socket instanceof SocketServer) {
                        $socket->close();
                    }
                    Loop::stop();
                });
            },
        );
        $credentialHandler = new CredentialHandler($boot->credentialResolver(), $boot->discovery());
        $roleHandler = new RoleHandler($boot->roleDiscovery(), $boot->roleResolver());
        $taskHandler = new TaskHandler($storage, $taskManager, $boot->roleResolver());
        $fileUploadHandler = new FileUploadHandler($storage, $uploadStorage);
        $serverHandler = new ServerHandler($storage, $startTime, $turnManager, $taskManager);

        $previewRunner = new AgentRunner(
            roleResolver: $boot->roleResolver(),
            config: $boot->config(),
            projectRoot: $workDir,
            workspacePath: $boot->workspacePath(),
            storage: $storage,
            observer: null,
            discovery: $boot->discovery(),
            blacklist: $boot->blacklist(),
            credentialResolver: $boot->credentialResolver(),
            skillDiscovery: $boot->skillDiscovery(),
            roleDiscovery: $boot->roleDiscovery(),
            memoryStore: $boot->memoryStore(),
            memorySummarizer: $boot->memorySummarizer(),
            mountManager: $boot->mountManager(),
            configManager: $boot->configManager(),
            visibilityRegistry: $boot->visibilityRegistry(),
            spaceToolkit: $boot->spaceToolkit(),
            projectStore: $boot->projectStore(),
            defaultsLoader: $boot->defaultsLoader(),
        );
        $toolkitHandler = new ToolkitHandler($boot->discovery(), $boot->visibilityRegistry(), $previewRunner);
        $promptHandler = new PromptHandler($previewRunner);
        $artifactStore = new ArtifactStore($storage->getPdo());
        $artifactHandler = new ArtifactHandler($artifactStore);
        $todoStore = new \CoquiBot\Coqui\Storage\TodoStore($storage->getPdo());
        $todoHandler = new \CoquiBot\Coqui\Api\Handler\TodoHandler($todoStore);
        $summarizeHandler = new SummarizeHandler($storage, $boot->config(), $boot->roleResolver(), $boot->memoryStore(), $todoStore, $artifactStore);

        // Schedule & webhook infrastructure
        $scheduleManager = new ScheduleManager($scheduleStore, $storage);
        $scheduleManager->setOnNotify(static function (string $event, array $data) use ($output): void {
            $output->writeln(sprintf(
                '<fg=gray>[%s]</> <info>%s</info>: %s',
                date('H:i:s'),
                $event,
                json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}',
            ), OutputInterface::VERBOSITY_VERBOSE);
        });
        $verifierRegistry = new WebhookVerifierRegistry();
        $scheduleHandler = new ScheduleHandler($scheduleStore, $scheduleManager);
        $webhookHandler = new WebhookHandler($webhookStore, $storage, $verifierRegistry);
        $webhookMgmtHandler = new WebhookManagementHandler($webhookStore);

        // Build router
        $router = new Router();
        $this->registerRoutes($router, $healthHandler, $sessionHandler, $messageHandler, $turnHandler, $configHandler, $credentialHandler, $roleHandler, $taskHandler, $fileUploadHandler, $serverHandler, $toolkitHandler, $promptHandler, $summarizeHandler, $artifactHandler, $todoHandler, $scheduleHandler, $webhookHandler, $webhookMgmtHandler);

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
            $middlewareStack[] = new AuthMiddleware($apiKey);
        }

        foreach ($middlewareStack as $mw) {
            $router->addMiddleware($mw);
        }

        // Create ReactPHP HTTP server with explicit middleware for file upload support.
        // The default auto-registered middleware caps body at 64 KiB which is too small
        // for file uploads. We configure a 50 MiB buffer and multipart parser.
        $maxUploadBytes = 50 * 1024 * 1024; // 50 MiB
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

        // Graceful shutdown on SIGTERM/SIGINT — close socket + stop event loop
        // SIGINT (2) = direct Ctrl+C — show shutdown message (standalone mode)
        // SIGTERM (15) = sent by launcher — stay silent (launcher owns the UX)
        $shutdownHandler = static function (int $signal) use ($socket, $output, $taskManager, $turnManager): void {
            $output->writeln('');
            if ($signal === 2) {
                $output->writeln('');
                $output->writeln(' <info>[INFO] Shutting down Coqui.</info>');
                $output->writeln('');
            }
            $turnManager->shutdown();
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

        $output->writeln('');
        $output->writeln(sprintf('Listening on <info>http://%s</info>', $listenAddress));
        $output->writeln(sprintf('Project root: <fg=gray>%s</>', $workDir));
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

        // Periodic timer: reap finished turn processes
        Loop::addPeriodicTimer(1.0, static function () use ($turnManager): void {
            $turnManager->tick();
        });

        // Periodic timer: evaluate scheduled tasks every 60 seconds
        Loop::addPeriodicTimer(60.0, static function () use ($scheduleManager): void {
            $scheduleManager->tick();
        });

        // Periodic timer: purge old webhook delivery logs daily (3600s check)
        Loop::addPeriodicTimer(3600.0, static function () use ($webhookStore): void {
            $webhookStore->purgeOldDeliveries();
        });

        return $restartRequested ? RunCommand::RESTART_EXIT_CODE : Command::SUCCESS;
    }

    /**
     * Register all API routes on the router.
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
        SummarizeHandler $summarize,
        ArtifactHandler $artifact,
        \CoquiBot\Coqui\Api\Handler\TodoHandler $todo,
        ScheduleHandler $schedule,
        WebhookHandler $webhook,
        WebhookManagementHandler $webhookMgmt,
    ): void {
        $v1 = '/api/v1';

        // Health
        $router->get($v1 . '/health', $health);

        // Sessions
        $router->get($v1 . '/sessions', [$session, 'list']);
        $router->post($v1 . '/sessions', [$session, 'create']);
        $router->get($v1 . '/sessions/{id}', [$session, 'get']);
        $router->patch($v1 . '/sessions/{id}', [$session, 'update']);
        $router->delete($v1 . '/sessions/{id}', [$session, 'delete']);

        // Messages
        $router->get($v1 . '/sessions/{id}/messages', [$message, 'list']);
        $router->post($v1 . '/sessions/{id}/messages', [$message, 'send']);
        $router->delete($v1 . '/sessions/{id}/messages/{messageId}', [$message, 'delete']);

        // File uploads
        $router->post($v1 . '/sessions/{id}/files', [$fileUpload, 'upload']);
        $router->get($v1 . '/sessions/{id}/files', [$fileUpload, 'list']);
        $router->get($v1 . '/sessions/{id}/files/{fileId}', [$fileUpload, 'get']);
        $router->delete($v1 . '/sessions/{id}/files/{fileId}', [$fileUpload, 'delete']);

        // Turns
        $router->get($v1 . '/sessions/{id}/turns', [$turn, 'list']);
        $router->get($v1 . '/sessions/{id}/turns/{turnId}', [$turn, 'get']);

        // Config
        $router->get($v1 . '/config', [$config, 'get']);
        $router->put($v1 . '/config', [$config, 'update']);
        $router->post($v1 . '/config/validate', [$config, 'validate']);
        $router->get($v1 . '/config/models', [$config, 'models']);

        // Roles
        $router->get($v1 . '/config/roles', [$role, 'list']);
        $router->post($v1 . '/config/roles', [$role, 'create']);
        $router->get($v1 . '/config/roles/{name}', [$role, 'get']);
        $router->patch($v1 . '/config/roles/{name}', [$role, 'update']);
        $router->delete($v1 . '/config/roles/{name}', [$role, 'delete']);

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

        // Child runs
        $router->get($v1 . '/sessions/{id}/child-runs', [$session, 'childRuns']);

        // Summarization
        $router->post($v1 . '/sessions/{id}/summarize', [$summarize, 'summarize']);

        // Server
        $router->get($v1 . '/server/info', [$server, 'info']);
        $router->get($v1 . '/server/stats', [$server, 'stats']);
        $router->get($v1 . '/server/prompt', [$prompt, 'get']);

        // Artifacts
        $router->get($v1 . '/sessions/{id}/artifacts', [$artifact, 'list']);
        $router->post($v1 . '/sessions/{id}/artifacts', [$artifact, 'create']);
        $router->get($v1 . '/sessions/{id}/artifacts/{artifactId}', [$artifact, 'get']);
        $router->patch($v1 . '/sessions/{id}/artifacts/{artifactId}', [$artifact, 'update']);
        $router->delete($v1 . '/sessions/{id}/artifacts/{artifactId}', [$artifact, 'delete']);
        $router->get($v1 . '/sessions/{id}/artifacts/{artifactId}/versions', [$artifact, 'versions']);

        // Todos
        $router->get($v1 . '/sessions/{id}/todos', [$todo, 'list']);
        $router->post($v1 . '/sessions/{id}/todos', [$todo, 'create']);
        $router->post($v1 . '/sessions/{id}/todos/bulk', [$todo, 'bulkCreate']);
        $router->patch($v1 . '/sessions/{id}/todos/bulk', [$todo, 'bulkUpdate']);
        $router->get($v1 . '/sessions/{id}/todos/stats', [$todo, 'stats']);
        $router->get($v1 . '/sessions/{id}/todos/{todoId}', [$todo, 'get']);
        $router->patch($v1 . '/sessions/{id}/todos/{todoId}', [$todo, 'update']);
        $router->post($v1 . '/sessions/{id}/todos/{todoId}/complete', [$todo, 'complete']);
        $router->delete($v1 . '/sessions/{id}/todos/{todoId}', [$todo, 'delete']);

        // Toolkit visibility management
        $router->get($v1 . '/toolkits', [$toolkit, 'list']);
        $router->post($v1 . '/toolkits/visibility', [$toolkit, 'setVisibility']);

        // Schedules
        $router->get($v1 . '/schedules', [$schedule, 'list']);
        $router->post($v1 . '/schedules', [$schedule, 'create']);
        $router->get($v1 . '/schedules/{id}', [$schedule, 'get']);
        $router->patch($v1 . '/schedules/{id}', [$schedule, 'update']);
        $router->delete($v1 . '/schedules/{id}', [$schedule, 'delete']);
        $router->post($v1 . '/schedules/{id}/trigger', [$schedule, 'trigger']);
        $router->post($v1 . '/schedules/{id}/enable', [$schedule, 'enable']);
        $router->post($v1 . '/schedules/{id}/disable', [$schedule, 'disable']);

        // Webhooks (incoming receiver + management CRUD)
        $webhook->register($router);
        $webhookMgmt->register($router);
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

    private function resolveWorkspaceOverride(InputInterface $input): ?string
    {
        $option = $input->getOption('workspace');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $env = getenv('COQUI_WORKSPACE');

        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }
}
