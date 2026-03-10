<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\TitleGenerator;
use CoquiBot\Coqui\Api\AgentFiberExecutor;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Api\Handler\CredentialHandler;
use CoquiBot\Coqui\Api\Handler\FileUploadHandler;
use CoquiBot\Coqui\Api\Handler\HealthHandler;
use CoquiBot\Coqui\Api\Handler\MessageHandler;
use CoquiBot\Coqui\Api\Handler\RoleHandler;
use CoquiBot\Coqui\Api\Handler\ServerHandler;
use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\TaskHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use CoquiBot\Coqui\Api\Middleware\ContentTypeMiddleware;
use CoquiBot\Coqui\Api\Middleware\CorsMiddleware;
use CoquiBot\Coqui\Api\Middleware\RateLimitMiddleware;
use CoquiBot\Coqui\Api\Middleware\RequestSizeMiddleware;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\SessionStorage;
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
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.')
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Disable script sanitization (dangerous)')
            ->addOption('no-auth', null, InputOption::VALUE_NONE, 'Run without API key authentication (binds to 127.0.0.1 only)')
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
        $noAuth = (bool) $input->getOption('no-auth')
            || filter_var(getenv('COQUI_NO_AUTH'), FILTER_VALIDATE_BOOLEAN);
        $corsOrigin = is_string($input->getOption('cors-origin'))
            ? $input->getOption('cors-origin')
            : '*';

        $output->writeln('<info>Coqui API Server</info>');
        $output->writeln('');

        // Boot sequence (headless — no SymfonyStyle)
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $boot = new BootManager($workDir);
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

        if ($apiKey === null && !$noAuth) {
            // Require API key after setup unless --no-auth is explicitly passed
            $output->writeln('<error>No API key configured.</error>');
            $output->writeln('');
            $output->writeln('Set an API key using one of these methods:');
            $output->writeln('  1. Set <fg=cyan>"api.key"</> in your openclaw.json');
            $output->writeln('  2. Set the <fg=cyan>COQUI_API_KEY</> environment variable');
            $output->writeln('  3. Run <fg=cyan>coqui setup</> to generate one automatically');
            $output->writeln('');
            $output->writeln('Or use <fg=yellow>--no-auth</> to run without authentication (localhost only).');
            $output->writeln('');
            return Command::FAILURE;
        }

        if ($noAuth) {
            // Force localhost binding for safety when running without auth
            $host = '127.0.0.1';
            $apiKey = null;
            $output->writeln('<comment>WARNING: Running without authentication. Binding to 127.0.0.1 only.</comment>');
            $output->writeln('');
        } elseif ($host !== '127.0.0.1' && $host !== 'localhost') {
            // Warn when exposing API to the network
            $output->writeln(sprintf(
                '<comment>WARNING: API will be accessible on the network (%s:%s). Ensure your API key is strong and consider using a reverse proxy with TLS for production.</comment>',
                $host,
                $port,
            ));
            $output->writeln('');
        }

        // Create AgentRunner (headless: NullObserver + no interactive approval)
        $agentRunner = new AgentRunner(
            roleResolver: $boot->roleResolver(),
            config: $boot->config(),
            projectRoot: $workDir,
            workspacePath: $boot->workspacePath(),
            storage: $storage,
            observer: new NullObserver(),
            discovery: $boot->discovery(),
            blacklist: $boot->blacklist(),
            credentialResolver: $boot->credentialResolver(),
            skillDiscovery: $boot->skillDiscovery(),
            roleDiscovery: $boot->roleDiscovery(),
            unsafeMode: $unsafeMode,
            backgroundTasksEnabled: true,
            memoryStore: $boot->memoryStore(),
            memorySummarizer: $boot->memorySummarizer(),
            mountManager: $boot->mountManager(),
        );

        // Create title generator
        $titleGenerator = new TitleGenerator(
            roleResolver: $boot->roleResolver(),
            config: $boot->config(),
            roleDiscovery: $boot->roleDiscovery(),
        );

        // Create Fiber executor
        $executor = new AgentFiberExecutor(
            agentRunner: $agentRunner,
            storage: $storage,
            blacklist: $boot->blacklist(),
            titleGenerator: $titleGenerator,
        );

        // Create background task manager
        $coquiBinPath = realpath(dirname(__DIR__, 2) . '/bin/coqui') ?: dirname(__DIR__, 2) . '/bin/coqui';
        $maxConcurrentTasks = (int) ($boot->config()->get('api.tasks.maxConcurrent') ?? 1);

        $taskManager = new BackgroundTaskManager(
            storage: $storage,
            coquiBinPath: $coquiBinPath,
            configPath: $configPath ?? '',
            workDir: $workDir,
            maxConcurrent: max(1, $maxConcurrentTasks),
            unsafeMode: $unsafeMode,
        );

        // Crash recovery: mark orphaned tasks from previous server run as failed
        $orphanCount = $storage->markOrphanedTasksFailed();
        if ($orphanCount > 0) {
            $output->writeln(sprintf('<comment>Recovered %d orphaned task(s) from previous run</comment>', $orphanCount));
        }

        $startTime = microtime(true);

        // Create handlers
        $healthHandler = new HealthHandler($startTime, $executor, $taskManager);
        $sessionHandler = new SessionHandler($storage, $boot->roleResolver());
        $messageHandler = new MessageHandler($storage, $executor, $uploadStorage);
        $turnHandler = new TurnHandler($storage);
        $configHandler = new ConfigHandler($boot->config(), $boot->configPath());
        $credentialHandler = new CredentialHandler($boot->credentialResolver());
        $roleHandler = new RoleHandler($boot->roleDiscovery(), $boot->roleResolver());
        $taskHandler = new TaskHandler($storage, $taskManager, $boot->roleResolver());
        $fileUploadHandler = new FileUploadHandler($storage, $uploadStorage);
        $serverHandler = new ServerHandler($storage, $startTime, $executor, $taskManager);

        // Build router
        $router = new Router();
        $this->registerRoutes($router, $healthHandler, $sessionHandler, $messageHandler, $turnHandler, $configHandler, $credentialHandler, $roleHandler, $taskHandler, $fileUploadHandler, $serverHandler);

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
        $shutdownHandler = static function (int $signal) use ($socket, $output, $taskManager): void {
            $output->writeln('');
            $output->writeln(sprintf('<comment>Received signal %d, shutting down...</comment>', $signal));
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

        $output->writeln(sprintf('Listening on <info>http://%s</info>', $listenAddress));
        $output->writeln(sprintf('Project root: <fg=gray>%s</>', $workDir));
        $output->writeln(sprintf('Workspace: <fg=gray>%s</>', $boot->workspacePath()));
        $output->writeln(sprintf('Model: <fg=gray>%s</>', $boot->roleResolver()->resolve('orchestrator')));
        $output->writeln(sprintf('Auth: <fg=gray>%s</>', $apiKey !== null ? 'API key required' : 'none'));
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

        return Command::SUCCESS;
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

        // Server
        $router->get($v1 . '/server/info', [$server, 'info']);
        $router->get($v1 . '/server/stats', [$server, 'stats']);
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
