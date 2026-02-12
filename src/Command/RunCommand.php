<?php

declare(strict_types=1);

namespace Coqui\Command;

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use Coqui\Agent\OrchestratorAgent;
use Coqui\Config\RoleResolver;
use Coqui\Config\ToolkitDiscovery;
use Coqui\Config\WorkspaceResolver;
use Coqui\Observer\TerminalObserver;
use Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'run',
    description: 'Start the Coqui REPL',
)]
final class RunCommand extends Command
{
    private const SESSION_FILE = '.coqui-session';

    private SessionStorage $storage;
    private string $sessionId;
    private OpenClawConfig $config;
    private RoleResolver $roleResolver;
    private TerminalObserver $observer;
    private string $workDir;
    private string $workspacePath;
    private ToolkitDiscovery $discovery;

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Start a new session')
            ->addOption('session', 's', InputOption::VALUE_REQUIRED, 'Resume a specific session ID')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workDirOption = $input->getOption('workdir');
        $this->workDir = is_string($workDirOption) ? $workDirOption : (getcwd() ?: '.');
        $this->observer = new TerminalObserver($output, (bool) $input->getOption('verbose'));

        // Load config
        $configPath = $input->getOption('config') ?? $this->workDir . '/openclaw.json';
        if (!file_exists($configPath)) {
            $configPath = __DIR__ . '/../../openclaw.json';
        }

        if (file_exists($configPath)) {
            $this->config = OpenClawConfig::fromFile($configPath);
        } else {
            $io->warning('No openclaw.json found. Using defaults.');
            $this->config = OpenClawConfig::fromArray([
                'agents' => [
                    'defaults' => [
                        'model' => ['primary' => 'ollama/qwen3:latest'],
                        'roles' => ['orchestrator' => 'ollama/qwen3:latest'],
                    ],
                ],
            ]);
        }

        $this->roleResolver = new RoleResolver($this->config);

        // Resolve workspace directory
        $workspaceResolver = new WorkspaceResolver($this->config, $this->workDir);
        $this->workspacePath = $workspaceResolver->resolve();

        // Initialize toolkit discovery
        $this->discovery = new ToolkitDiscovery($this->workDir, $this->workspacePath);

        // Initialize storage inside workspace
        $dbPath = $this->workspacePath . '/data/coqui.db';
        $this->storage = new SessionStorage($dbPath);

        // Handle session
        if ($input->getOption('new')) {
            $this->sessionId = $this->createNewSession($io);
        } elseif ($input->getOption('session')) {
            $this->sessionId = $input->getOption('session');
            if ($this->storage->getSession($this->sessionId) === null) {
                $io->error("Session not found: {$this->sessionId}");
                return Command::FAILURE;
            }
            $io->info("Resumed session: {$this->sessionId}");
        } else {
            $this->sessionId = $this->loadOrCreateSession($io);
        }

        // Display welcome
        $io->title('Coqui REPL');
        $io->text([
            '<fg=gray>Session:</> ' . substr($this->sessionId, 0, 8) . '...',
            '<fg=gray>Model:</> ' . $this->roleResolver->resolve('orchestrator'),
            '<fg=gray>Project root:</> ' . $this->workDir,
            '<fg=gray>Workspace:</> ' . $this->workspacePath,
            '',
            '<fg=gray>Commands: /new, /history, /sessions, /quit</>',
        ]);
        $io->newLine();

        // REPL loop
        return $this->runRepl($io);
    }

    private function runRepl(SymfonyStyle $io): int
    {
        while (true) {
            $prompt = $io->ask('<fg=cyan>You</>');

            if ($prompt === null || trim($prompt) === '') {
                continue;
            }

            $prompt = trim($prompt);

            // Handle commands
            if (str_starts_with($prompt, '/')) {
                $continue = $this->handleCommand($prompt, $io);
                if (!$continue) {
                    return Command::SUCCESS;
                }
                continue;
            }

            // Run agent
            $this->runAgent($prompt, $io);
        }
    }

    private function handleCommand(string $command, SymfonyStyle $io): bool
    {
        $parts = explode(' ', $command, 2);
        $cmd = $parts[0];
        $arg = $parts[1] ?? '';

        match ($cmd) {
            '/quit', '/exit', '/q' => false,

            '/new' => (function () use ($io) {
                $this->sessionId = $this->createNewSession($io);
                $io->success('New session started: ' . substr($this->sessionId, 0, 8) . '...');
                return true;
            })(),

            '/history' => (function () use ($io) {
                $this->showHistory($io);
                return true;
            })(),

            '/sessions' => (function () use ($io) {
                $this->listSessions($io);
                return true;
            })(),

            '/resume' => (function () use ($io, $arg) {
                if ($arg === '') {
                    $io->error('Usage: /resume <session-id>');
                    return true;
                }
                $session = $this->storage->getSession($arg);
                if ($session === null) {
                    $io->error("Session not found: {$arg}");
                    return true;
                }
                $this->sessionId = $arg;
                $this->saveSessionFile();
                $io->success('Resumed session: ' . substr($arg, 0, 8) . '...');
                return true;
            })(),

            '/model' => (function () use ($io, $arg) {
                $this->showModelInfo($io, $arg);
                return true;
            })(),

            '/help' => (function () use ($io) {
                $io->table(
                    ['Command', 'Description'],
                    [
                        ['/new', 'Start a new session'],
                        ['/history', 'Show conversation history'],
                        ['/sessions', 'List all sessions'],
                        ['/resume <id>', 'Resume a session'],
                        ['/model', 'Show model configuration'],
                        ['/quit', 'Exit Coqui'],
                    ],
                );
                return true;
            })(),

            default => (function () use ($io, $cmd) {
                $io->error("Unknown command: {$cmd}. Type /help for available commands.");
                return true;
            })(),
        };

        // The match returns a value but we need to return a bool
        // Re-handle properly
        return match ($cmd) {
            '/quit', '/exit', '/q' => false,
            default => true,
        };
    }

    private function runAgent(string $prompt, SymfonyStyle $io): void
    {
        // Save user message
        $this->storage->addMessage($this->sessionId, 'user', $prompt);

        // Create orchestrator
        $modelString = $this->roleResolver->resolve('orchestrator');
        $provider = ProviderFactory::fromModelString($modelString, $this->config);

        $agent = new OrchestratorAgent(
            provider: $provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->workDir,
            workspacePath: $this->workspacePath,
            storage: $this->storage,
            sessionId: $this->sessionId,
            observer: $this->observer,
            discovery: $this->discovery,
        );

        $agent->attach($this->observer);

        $io->newLine();

        try {
            $output = $agent->run(new UserMessage($prompt));

            // Save assistant response
            $this->storage->addMessage($this->sessionId, 'assistant', $output->content);

            // Display response
            $io->newLine();
            $io->writeln('<fg=green>Assistant:</>');
            $io->writeln($output->content);
            $io->newLine();

            // Display stats
            $stats = [];
            $stats[] = "Iterations: {$output->iterations}";
            if ($output->usage !== null) {
                $stats[] = "Tokens: {$output->usage->totalTokens}";
                $this->storage->updateTokenCount($this->sessionId, $output->usage->totalTokens);
            }
            $io->comment(implode(' | ', $stats));
            $io->newLine();
        } catch (\Throwable $e) {
            $io->error("Agent error: {$e->getMessage()}");
        }
    }

    private function createNewSession(SymfonyStyle $io): string
    {
        $modelString = $this->roleResolver->resolve('orchestrator');
        $sessionId = $this->storage->createSession('orchestrator', $modelString);

        $this->saveSessionFile($sessionId);

        return $sessionId;
    }

    private function loadOrCreateSession(SymfonyStyle $io): string
    {
        // Check for session file
        $sessionFile = $this->workspacePath . '/' . self::SESSION_FILE;
        if (file_exists($sessionFile)) {
            $fileContent = file_get_contents($sessionFile);
            if ($fileContent !== false) {
                $sessionId = trim($fileContent);
                if ($this->storage->getSession($sessionId) !== null) {
                    $io->info('Resumed previous session: ' . substr($sessionId, 0, 8) . '...');
                    return $sessionId;
                }
            }
        }

        // Check for latest session
        $latestId = $this->storage->getLatestSessionId();
        if ($latestId !== null) {
            $this->saveSessionFile($latestId);
            $io->info('Resumed latest session: ' . substr($latestId, 0, 8) . '...');
            return $latestId;
        }

        // Create new session
        $sessionId = $this->createNewSession($io);
        $io->info('Created new session: ' . substr($sessionId, 0, 8) . '...');

        return $sessionId;
    }

    private function saveSessionFile(?string $sessionId = null): void
    {
        $sessionId = $sessionId ?? $this->sessionId;
        $sessionFile = $this->workspacePath . '/' . self::SESSION_FILE;
        file_put_contents($sessionFile, $sessionId);
    }

    private function showHistory(SymfonyStyle $io): void
    {
        $messages = $this->storage->getMessages($this->sessionId);

        if (empty($messages)) {
            $io->info('No messages in this session.');
            return;
        }

        $io->section('Conversation History');

        foreach ($messages as $msg) {
            $role = ucfirst($msg['role']);
            $content = $msg['content'];

            if (strlen($content) > 200) {
                $content = substr($content, 0, 197) . '...';
            }

            $color = match ($msg['role']) {
                'user' => 'cyan',
                'assistant' => 'green',
                'system' => 'yellow',
                default => 'gray',
            };

            $io->writeln("<fg={$color}>{$role}:</> {$content}");
        }
    }

    private function listSessions(SymfonyStyle $io): void
    {
        $sessions = $this->storage->listSessions(20);

        if (empty($sessions)) {
            $io->info('No sessions found.');
            return;
        }

        $rows = [];
        foreach ($sessions as $session) {
            $isCurrent = $session['id'] === $this->sessionId ? ' (current)' : '';
            $rows[] = [
                substr($session['id'], 0, 8) . '...' . $isCurrent,
                $session['model_role'],
                $session['token_count'],
                $session['updated_at'],
            ];
        }

        $io->table(['ID', 'Role', 'Tokens', 'Updated'], $rows);
    }

    private function showModelInfo(SymfonyStyle $io, string $role = ''): void
    {
        if ($role !== '') {
            $model = $this->roleResolver->resolve($role);
            $io->writeln("<fg=gray>{$role}:</> {$model}");
            return;
        }

        $io->section('Model Configuration');
        $roles = $this->roleResolver->toArray();

        $rows = [];
        foreach ($roles as $r => $m) {
            $rows[] = [$r, $m];
        }

        $io->table(['Role', 'Model'], $rows);
    }
}
