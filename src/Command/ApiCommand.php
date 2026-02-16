<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\AgentFiberExecutor;
use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Api\Handler\CredentialHandler;
use CoquiBot\Coqui\Api\Handler\HealthHandler;
use CoquiBot\Coqui\Api\Handler\MessageHandler;
use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use CoquiBot\Coqui\Api\Middleware\CorsMiddleware;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\HttpServer;
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
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen on', '8080')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind to', '127.0.0.1')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.')
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Disable script sanitization (dangerous)')
            ->addOption('cors-origin', null, InputOption::VALUE_REQUIRED, 'Allowed CORS origins (comma-separated)', '*');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workDir = is_string($input->getOption('workdir'))
            ? $input->getOption('workdir')
            : (getcwd() ?: '.');
        $host = is_string($input->getOption('host')) ? $input->getOption('host') : '127.0.0.1';
        $port = is_string($input->getOption('port')) ? $input->getOption('port') : '8080';
        $unsafeMode = (bool) $input->getOption('unsafe');
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

        // Read API key from config
        $apiKey = $this->resolveApiKey($boot);

        if ($apiKey === null) {
            $output->writeln('<comment>Warning: No API key configured.</comment>');
            $output->writeln('<comment>Set "api.key" in openclaw.json or COQUI_API_KEY env var.</comment>');
            $output->writeln('<comment>Server will run WITHOUT authentication.</comment>');
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
            unsafeMode: $unsafeMode,
        );

        // Create Fiber executor
        $executor = new AgentFiberExecutor(
            agentRunner: $agentRunner,
            storage: $storage,
            blacklist: $boot->blacklist(),
        );

        $startTime = microtime(true);

        // Create handlers
        $healthHandler = new HealthHandler($startTime, $executor);
        $sessionHandler = new SessionHandler($storage, $boot->roleResolver());
        $messageHandler = new MessageHandler($storage, $executor);
        $turnHandler = new TurnHandler($storage);
        $configHandler = new ConfigHandler($boot->config(), $boot->roleResolver());
        $credentialHandler = new CredentialHandler($boot->credentialResolver());

        // Build router
        $router = new Router();
        $this->registerRoutes($router, $healthHandler, $sessionHandler, $messageHandler, $turnHandler, $configHandler, $credentialHandler);

        // Build middleware stack
        $corsOrigins = array_map('trim', explode(',', $corsOrigin));
        $cors = new CorsMiddleware($corsOrigins);

        $middlewareStack = [$cors];

        if ($apiKey !== null) {
            $middlewareStack[] = new AuthMiddleware($apiKey);
        }

        foreach ($middlewareStack as $mw) {
            $router->addMiddleware($mw);
        }

        // Create ReactPHP HTTP server
        $server = new HttpServer(function (\Psr\Http\Message\ServerRequestInterface $request) use ($router, $output): \Psr\Http\Message\ResponseInterface {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();
            $output->writeln(sprintf(
                '<fg=gray>[%s]</> %s %s',
                date('H:i:s'),
                $method,
                $path,
            ), OutputInterface::VERBOSITY_VERBOSE);

            return $router->dispatch($request);
        });

        $listenAddress = "{$host}:{$port}";
        $socket = new SocketServer($listenAddress);

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
    ): void {
        // Health
        $router->get('/api/health', $health);

        // Sessions
        $router->get('/api/sessions', [$session, 'list']);
        $router->post('/api/sessions', [$session, 'create']);
        $router->get('/api/sessions/{id}', [$session, 'get']);
        $router->delete('/api/sessions/{id}', [$session, 'delete']);

        // Messages
        $router->get('/api/sessions/{id}/messages', [$message, 'list']);
        $router->post('/api/sessions/{id}/messages', [$message, 'send']);

        // Turns
        $router->get('/api/sessions/{id}/turns', [$turn, 'list']);
        $router->get('/api/sessions/{id}/turns/{turnId}', [$turn, 'get']);

        // Config
        $router->get('/api/config', [$config, 'get']);
        $router->get('/api/config/roles', [$config, 'roles']);
        $router->get('/api/config/models', [$config, 'models']);

        // Credentials
        $router->get('/api/credentials', [$credential, 'list']);
        $router->post('/api/credentials', [$credential, 'set']);
        $router->delete('/api/credentials/{key}', [$credential, 'delete']);
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
