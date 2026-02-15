<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\SetupWizard;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
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

    /**
     * Exit code that signals the launcher script to restart the process.
     * Chosen to avoid collision with Symfony Console reserved codes (0, 1, 2)
     * and common signal-based codes (128+N).
     */
    public const RESTART_EXIT_CODE = 10;

    private BootManager $boot;
    private AgentRunner $agentRunner;
    private SessionStorage $storage;
    private TerminalObserver $observer;
    private string $sessionId;
    private string $workDir;
    private bool $unsafeMode = false;
    private bool $autoApprove = false;
    private bool $restartRequested = false;

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Start a new session')
            ->addOption('session', 's', InputOption::VALUE_REQUIRED, 'Resume a specific session ID')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.')
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Disable script sanitization for power users (dangerous)')
            ->addOption('auto-approve', null, InputOption::VALUE_NONE, 'Auto-approve all tool executions (dangerous)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workDirOption = $input->getOption('workdir');
        $this->workDir = is_string($workDirOption) ? $workDirOption : (getcwd() ?: '.');
        $this->observer = new TerminalObserver($output);
        $this->unsafeMode = (bool) $input->getOption('unsafe');
        $this->autoApprove = (bool) $input->getOption('auto-approve');

        // Boot sequence: config, workspace, credentials, toolkit discovery
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $this->boot = new BootManager($this->workDir);
        $this->boot->boot($io, $configPath);

        // Initialize storage inside workspace
        $dbPath = $this->boot->workspacePath() . '/data/coqui.db';
        $this->storage = new SessionStorage($dbPath);

        // Initialize agent runner
        $this->agentRunner = new AgentRunner(
            roleResolver: $this->boot->roleResolver(),
            config: $this->boot->config(),
            projectRoot: $this->workDir,
            workspacePath: $this->boot->workspacePath(),
            storage: $this->storage,
            observer: $this->observer,
            discovery: $this->boot->discovery(),
            blacklist: $this->boot->blacklist(),
            credentialResolver: $this->boot->credentialResolver(),
            unsafeMode: $this->unsafeMode,
            autoApprove: $this->autoApprove,
        );

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

        // Display safety mode warnings
        if ($this->unsafeMode) {
            $io->warning('UNSAFE MODE — all PHP functions allowed, catastrophic commands still blocked.');
        }
        if ($this->autoApprove) {
            $io->warning('AUTO-APPROVE MODE — all tool executions approved automatically, catastrophic commands still blocked.');
        }

        // Display welcome
        $io->title('Coqui REPL');
        $io->text([
            '<fg=gray>Session:</> ' . substr($this->sessionId, 0, 8) . '...',
            '<fg=gray>Model:</> ' . $this->boot->roleResolver()->resolve('orchestrator'),
            '<fg=gray>Project root:</> ' . $this->workDir,
            '<fg=gray>Workspace:</> ' . $this->boot->workspacePath(),
            '',
            '<fg=gray>Commands: /new, /history, /sessions, /config, /restart, /quit</>',
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
                $result = $this->handleCommand($prompt, $io);
                if ($result !== true) {
                    return $result;
                }
                continue;
            }

            // Run agent
            $this->restartRequested = $this->agentRunner->run($prompt, $this->sessionId, $io);

            // Check if agent requested a restart via RestartTool
            if ($this->restartRequested) {
                $io->info('Restart requested by agent. Restarting...');
                return self::RESTART_EXIT_CODE;
            }
        }
    }

    /**
     * Handle a REPL slash command.
     *
     * @return int|true True to continue the REPL loop, or an exit code to terminate.
     */
    private function handleCommand(string $command, SymfonyStyle $io): int|true
    {
        $parts = explode(' ', $command, 2);
        $cmd = $parts[0];
        $arg = $parts[1] ?? '';

        match ($cmd) {
            '/quit', '/exit', '/q' => false,

            '/restart' => (function () use ($io) {
                $io->info('Restarting Coqui...');
                return true;
            })(),

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

            '/config' => (function () use ($io, $arg) {
                $this->handleConfigCommand($io, $arg);
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
                        ['/config', 'Show config (use /config edit to re-run wizard)'],
                        ['/restart', 'Restart Coqui (re-reads config, re-discovers toolkits)'],
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

        return match ($cmd) {
            '/quit', '/exit', '/q' => Command::SUCCESS,
            '/restart' => self::RESTART_EXIT_CODE,
            default => true,
        };
    }

    private function createNewSession(SymfonyStyle $io): string
    {
        $modelString = $this->boot->roleResolver()->resolve('orchestrator');
        $sessionId = $this->storage->createSession('orchestrator', $modelString);

        $this->saveSessionFile($sessionId);

        return $sessionId;
    }

    private function loadOrCreateSession(SymfonyStyle $io): string
    {
        // Check for session file
        $sessionFile = $this->boot->workspacePath() . '/' . self::SESSION_FILE;
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
        $sessionFile = $this->boot->workspacePath() . '/' . self::SESSION_FILE;
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
            $model = $this->boot->roleResolver()->resolve($role);
            $io->writeln("<fg=gray>{$role}:</> {$model}");
            return;
        }

        $io->section('Model Configuration');
        $roles = $this->boot->roleResolver()->toArray();

        $rows = [];
        foreach ($roles as $r => $m) {
            $rows[] = [$r, $m];
        }

        $io->table(['Role', 'Model'], $rows);
    }

    private function handleConfigCommand(SymfonyStyle $io, string $subCommand): void
    {
        match (trim($subCommand)) {
            'edit' => $this->runConfigWizard($io),
            'show' => $this->showConfigFile($io),
            default => $this->showConfigSummary($io),
        };
    }

    private function runConfigWizard(SymfonyStyle $io): void
    {
        $outputPath = $this->workDir . '/openclaw.json';
        $wizard = new SetupWizard($io, $this->boot->defaultsLoader());
        $saved = $wizard->runAndSave($outputPath);

        if ($saved && file_exists($outputPath)) {
            $this->boot->reloadConfig($outputPath);
            $io->success('Configuration reloaded. Changes take effect on the next agent run.');
        }
    }

    private function showConfigFile(SymfonyStyle $io): void
    {
        $configPath = $this->workDir . '/openclaw.json';

        if (!file_exists($configPath)) {
            $io->warning('No openclaw.json found. Run /config edit to create one.');
            return;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            $io->error('Unable to read openclaw.json.');
            return;
        }

        $io->section('openclaw.json');
        $io->writeln($content);
    }

    private function showConfigSummary(SymfonyStyle $io): void
    {
        $io->section('Current Configuration');

        // Primary model
        $primary = $this->boot->config()->getPrimaryModel();
        $io->writeln('<fg=gray>Primary model:</> ' . ($primary !== '' ? $primary : '<fg=yellow>not set</>'));

        // Roles
        $roles = $this->boot->roleResolver()->toArray();
        if (!empty($roles)) {
            $io->newLine();
            $rows = [];
            foreach ($roles as $role => $model) {
                $rows[] = [$role, $model];
            }
            $io->table(['Role', 'Model'], $rows);
        }

        // Workspace
        $io->writeln('<fg=gray>Workspace:</> ' . $this->boot->workspacePath());
        $io->writeln('<fg=gray>Project root:</> ' . $this->workDir);
        $io->newLine();
        $io->text('<fg=gray>Use <fg=cyan>/config edit</> to re-run the setup wizard, or <fg=cyan>/config show</> to view raw JSON.</>'); 
    }
}
