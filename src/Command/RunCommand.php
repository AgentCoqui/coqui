<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\InteractiveApprovalPolicy;
use CoquiBot\Coqui\Config\SetupWizard;
use CoquiBot\Coqui\Config\UpdateManager;
use CoquiBot\Coqui\Contract\OutputRendererInterface;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Renderer\JsonRenderer;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
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

    /** Tool names that require user confirmation in interactive mode. */
    private const GATED_TOOLS = [
        'composer' => ['require', 'remove', 'update'],
        'exec' => ['*'],
        'php_execute' => ['*'],
        'restart_coqui' => ['*'],
    ];

    private BootManager $boot;
    private AgentRunner $agentRunner;
    private SessionStorage $storage;
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
            ->addOption('auto-approve', null, InputOption::VALUE_NONE, 'Auto-approve all tool executions (dangerous)')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Check for and apply dependency updates, then restart')
            ->addOption('no-terminal', null, InputOption::VALUE_NONE, 'Headless mode: run a single prompt without the REPL')
            ->addOption('prompt', 'p', InputOption::VALUE_REQUIRED, 'Prompt to send in --no-terminal mode')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format for --no-terminal mode (text or json)', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workDirOption = $input->getOption('workdir');
        $this->workDir = is_string($workDirOption) ? $workDirOption : (getcwd() ?: '.');
        $this->unsafeMode = (bool) $input->getOption('unsafe')
            || filter_var(getenv('COQUI_UNSAFE'), FILTER_VALIDATE_BOOLEAN);
        $this->autoApprove = (bool) $input->getOption('auto-approve')
            || filter_var(getenv('COQUI_AUTO_APPROVE'), FILTER_VALIDATE_BOOLEAN);
        $noTerminal = (bool) $input->getOption('no-terminal');

        // Boot sequence: config, workspace, credentials, toolkit discovery
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $this->boot = new BootManager($this->workDir);
        $this->boot->boot($noTerminal ? null : $io, $configPath);

        // Handle --update: apply updates and restart
        if ((bool) $input->getOption('update')) {
            $updateResult = $this->runUpdate($io);
            return $updateResult === true ? Command::SUCCESS : $updateResult;
        }

        // Initialize storage inside workspace
        $dbPath = $this->boot->workspacePath() . '/data/coqui.db';
        $this->storage = new SessionStorage($dbPath);

        // Headless mode: run a single prompt and exit
        if ($noTerminal) {
            return $this->runHeadless($input, $output);
        }

        // Startup update check (controlled by COQUI_CHECK_UPDATES env)
        $this->checkForUpdatesOnStartup($io);

        // If auto-update triggered a restart, exit now
        if ($this->restartRequested) {
            return self::RESTART_EXIT_CODE;
        }

        // Choose observer for terminal mode
        $observer = new TerminalObserver($output);

        // Initialize agent runner
        $this->agentRunner = new AgentRunner(
            roleResolver: $this->boot->roleResolver(),
            config: $this->boot->config(),
            projectRoot: $this->workDir,
            workspacePath: $this->boot->workspacePath(),
            storage: $this->storage,
            observer: $observer,
            discovery: $this->boot->discovery(),
            blacklist: $this->boot->blacklist(),
            credentialResolver: $this->boot->credentialResolver(),
            skillDiscovery: $this->boot->skillDiscovery(),
            roleDiscovery: $this->boot->roleDiscovery(),
            unsafeMode: $this->unsafeMode,
            backgroundTasksEnabled: true,
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
            '<fg=gray>Commands: /new, /history, /sessions, /tasks, /config, /update, /restart, /quit</>',
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

            // Build execution policy for this turn
            $executionPolicy = $this->buildInteractiveExecutionPolicy($this->sessionId, $io);

            // Run agent
            $result = $this->agentRunner->run($prompt, $this->sessionId, $executionPolicy);

            // Render output
            $renderer = new TerminalRenderer($io);
            $renderer->render($result);

            // Check if agent requested a restart via RestartTool
            if ($result->restartRequested) {
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

            '/tasks' => (function () use ($io, $arg) {
                $this->listTasksCommand($io, $arg);
                return true;
            })(),

            '/task' => (function () use ($io, $arg) {
                $this->taskStatusCommand($io, $arg);
                return true;
            })(),

            '/task-cancel' => (function () use ($io, $arg) {
                $this->taskCancelCommand($io, $arg);
                return true;
            })(),

            '/update' => (function () use ($io) {
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
                        ['/tasks [status]', 'List background tasks (optionally filter by status)'],
                        ['/task <id>', 'Show background task status and recent events'],
                        ['/task-cancel <id>', 'Cancel a pending or running background task'],
                        ['/update', 'Check for and apply dependency updates'],
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
            '/update' => $this->runUpdate($io),
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

    private function listTasksCommand(SymfonyStyle $io, string $statusFilter = ''): void
    {
        $status = trim($statusFilter) !== '' ? trim($statusFilter) : null;
        $tasks = $this->storage->listTasks($status, 20);

        if (empty($tasks)) {
            $io->info($status !== null ? "No tasks with status '{$status}'." : 'No background tasks found.');
            return;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $rows[] = [
                substr($task['id'], 0, 8) . '...',
                $task['status'],
                $task['title'] ?? '(untitled)',
                $task['role'],
                $task['created_at'],
            ];
        }

        $io->table(['ID', 'Status', 'Title', 'Role', 'Created'], $rows);

        $counts = $this->storage->getTaskCounts();
        $parts = [];
        foreach ($counts as $s => $c) {
            $parts[] = "{$s}: {$c}";
        }
        if (!empty($parts)) {
            $io->text('<fg=gray>' . implode(' | ', $parts) . '</>');
        }
    }

    private function taskStatusCommand(SymfonyStyle $io, string $taskIdPrefix = ''): void
    {
        if ($taskIdPrefix === '') {
            $io->error('Usage: /task <task-id>');
            return;
        }

        // Support prefix matching for convenience
        $task = $this->resolveTaskByPrefix($taskIdPrefix);

        if ($task === null) {
            $io->error("Task not found: {$taskIdPrefix}");
            return;
        }

        $io->section('Task: ' . ($task['title'] ?? $task['id']));
        $io->definitionList(
            ['ID' => $task['id']],
            ['Status' => $task['status']],
            ['Role' => $task['role']],
            ['Max Iterations' => $task['max_iterations']],
            ['Created' => $task['created_at']],
            ['Started' => $task['started_at'] ?? '(not started)'],
            ['Completed' => $task['completed_at'] ?? '(not completed)'],
        );

        if ($task['result'] !== null) {
            $result = $task['result'];
            if (mb_strlen($result) > 500) {
                $result = mb_substr($result, 0, 500) . '... (' . mb_strlen($task['result']) . ' chars total)';
            }
            $io->text('<fg=green>Result:</> ' . $result);
        }

        if ($task['error'] !== null) {
            $io->text('<fg=red>Error:</> ' . $task['error']);
        }

        // Show recent events
        $events = $this->storage->getTaskEvents($task['id'], limit: 10);
        if (!empty($events)) {
            $io->newLine();
            $io->text('<fg=gray>Recent events:</>');
            foreach ($events as $event) {
                $data = json_decode($event['data'] ?? '{}', true);
                $detail = match ($event['event_type']) {
                    'tool_call' => $data['tool'] ?? '',
                    'tool_result' => mb_substr($data['content'] ?? '', 0, 80),
                    'iteration' => 'iteration ' . ($data['number'] ?? '?'),
                    default => json_encode($data, JSON_UNESCAPED_SLASHES) ?: '',
                };
                $io->writeln(sprintf(
                    '  <fg=gray>%s</> <fg=cyan>%s</> %s',
                    $event['created_at'],
                    $event['event_type'],
                    mb_strlen($detail) > 100 ? mb_substr($detail, 0, 100) . '...' : $detail,
                ));
            }
        }
    }

    private function taskCancelCommand(SymfonyStyle $io, string $taskIdPrefix = ''): void
    {
        if ($taskIdPrefix === '') {
            $io->error('Usage: /task-cancel <task-id>');
            return;
        }

        $task = $this->resolveTaskByPrefix($taskIdPrefix);

        if ($task === null) {
            $io->error("Task not found: {$taskIdPrefix}");
            return;
        }

        if (in_array($task['status'], ['completed', 'failed', 'cancelled'], true)) {
            $io->warning(sprintf('Task is already in terminal state "%s".', $task['status']));
            return;
        }

        if ($task['status'] === 'pending') {
            $this->storage->updateTaskStatus($task['id'], 'cancelled');
            $this->storage->appendTaskEvent($task['id'], 'cancelled', [
                'message' => 'Cancelled from REPL',
            ]);
            $io->success('Task cancelled.');
            return;
        }

        // Running task — mark as cancelling for BackgroundTaskManager to handle
        $this->storage->updateTaskStatus($task['id'], 'cancelling');
        $this->storage->appendTaskEvent($task['id'], 'cancel_requested', [
            'message' => 'Cancellation requested from REPL',
        ]);
        $io->success('Cancellation requested. The task will stop after its current iteration.');
    }

    /**
     * Resolve a task by full ID or prefix match.
     *
     * @return array<string, mixed>|null
     */
    private function resolveTaskByPrefix(string $prefix): ?array
    {
        // Try exact match first
        $task = $this->storage->getTask($prefix);
        if ($task !== null) {
            return $task;
        }

        // Try prefix match
        $tasks = $this->storage->listTasks(limit: 100);
        $matches = array_filter($tasks, fn(array $t): bool => str_starts_with($t['id'], $prefix));

        if (count($matches) === 1) {
            $match = reset($matches);
            return $this->storage->getTask($match['id']);
        }

        return null;
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
        $wizard = new SetupWizard($io, $this->boot->defaultsLoader(), $this->boot->credentialResolver());
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

    /**
     * Run a single prompt in headless mode (no REPL, no terminal I/O).
     */
    private function runHeadless(InputInterface $input, OutputInterface $output): int
    {
        // Get prompt from --prompt flag or stdin
        $promptOption = $input->getOption('prompt');
        $prompt = is_string($promptOption) ? $promptOption : null;

        if ($prompt === null || trim($prompt) === '') {
            // Try reading from stdin (piped input)
            if (!posix_isatty(STDIN)) {
                $prompt = stream_get_contents(STDIN);
            }
        }

        if ($prompt === null || trim($prompt) === '') {
            $output->writeln('<error>No prompt provided. Use --prompt "..." or pipe via stdin.</error>');
            return Command::FAILURE;
        }

        $prompt = trim($prompt);

        // Headless always implies auto-approve
        $this->autoApprove = true;

        // Initialize agent runner with NullObserver (no terminal output during execution)
        $this->agentRunner = new AgentRunner(
            roleResolver: $this->boot->roleResolver(),
            config: $this->boot->config(),
            projectRoot: $this->workDir,
            workspacePath: $this->boot->workspacePath(),
            storage: $this->storage,
            observer: new NullObserver(),
            discovery: $this->boot->discovery(),
            blacklist: $this->boot->blacklist(),
            credentialResolver: $this->boot->credentialResolver(),
            skillDiscovery: $this->boot->skillDiscovery(),
            roleDiscovery: $this->boot->roleDiscovery(),
            unsafeMode: $this->unsafeMode,
        );

        // Handle session
        $sessionOption = $input->getOption('session');
        if (is_string($sessionOption) && $sessionOption !== '') {
            $this->sessionId = $sessionOption;
            if ($this->storage->getSession($this->sessionId) === null) {
                $output->writeln("<error>Session not found: {$this->sessionId}</error>");
                return Command::FAILURE;
            }
        } else {
            $modelString = $this->boot->roleResolver()->resolve('orchestrator');
            $this->sessionId = $this->storage->createSession('orchestrator', $modelString);
        }

        // Build policy and run
        $executionPolicy = $this->buildExecutionPolicy($this->sessionId);
        $result = $this->agentRunner->run($prompt, $this->sessionId, $executionPolicy);

        // Choose renderer based on --format
        $format = $input->getOption('format');
        $renderer = match ($format) {
            'json' => new JsonRenderer($output),
            default => new TerminalRenderer(new SymfonyStyle($input, $output)),
        };

        $renderer->render($result);

        return $result->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Build the execution policy for an agent turn.
     *
     * In auto-approve mode: returns AutoApprovalPolicy.
     * Otherwise: returns InteractiveApprovalPolicy with gated tools.
     */
    private function buildExecutionPolicy(string $sessionId, ?string $turnId = null): \CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface
    {
        if ($this->autoApprove) {
            return new AutoApprovalPolicy(
                blacklist: $this->boot->blacklist(),
                storage: $this->storage,
                sessionId: $sessionId,
                turnId: $turnId,
            );
        }

        // InteractiveApprovalPolicy requires $io — RunCommand always has it in REPL mode
        // This method is only called from contexts where $io is available
        throw new \LogicException(
            'buildExecutionPolicy() for interactive mode must be called from the REPL context. '
            . 'Use the overload that accepts SymfonyStyle.',
        );
    }

    /**
     * Build an interactive execution policy (REPL mode only).
     */
    private function buildInteractiveExecutionPolicy(
        string $sessionId,
        SymfonyStyle $io,
        ?string $turnId = null,
    ): \CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface {
        if ($this->autoApprove) {
            return new AutoApprovalPolicy(
                blacklist: $this->boot->blacklist(),
                storage: $this->storage,
                sessionId: $sessionId,
                turnId: $turnId,
            );
        }

        return new InteractiveApprovalPolicy(
            io: $io,
            gatedTools: self::GATED_TOOLS,
            blacklist: $this->boot->blacklist(),
            storage: $this->storage,
            sessionId: $sessionId,
            turnId: $turnId,
        );
    }

    /**
     * Run `composer update` in project and workspace, then request restart.
     */
    private function runUpdate(SymfonyStyle $io): int|true
    {
        $updateManager = new UpdateManager($this->workDir, $this->boot->workspacePath());

        $io->text('<fg=gray>Checking for updates...</>');
        $check = $updateManager->checkForUpdates();

        if (!$check->hasUpdates) {
            $io->success('All packages are up to date.');
            return true;
        }

        $io->writeln($check->summary());
        $io->newLine();

        if (!$io->confirm('Apply updates now?', true)) {
            return true;
        }

        $io->text('<fg=gray>Updating dependencies...</>');
        $result = $updateManager->applyUpdates();

        if ($result->error !== '') {
            $io->error($result->error);
            return true;
        }

        $io->success('Updates applied successfully. Restarting...');

        return self::RESTART_EXIT_CODE;
    }

    /**
     * Perform a non-blocking startup update check if enabled.
     */
    private function checkForUpdatesOnStartup(SymfonyStyle $io): void
    {
        $updateManager = new UpdateManager($this->workDir, $this->boot->workspacePath());

        if (!$updateManager->isCheckEnabled()) {
            return;
        }

        $check = $updateManager->checkForUpdates();

        if (!$check->hasUpdates) {
            return;
        }

        $count = count($check->packages);
        $io->text("<fg=yellow>{$count} update(s) available.</> Run <fg=cyan>/update</> or <fg=cyan>coqui --update</> to apply.");

        // Auto-update if enabled
        if ($updateManager->isAutoUpdateEnabled()) {
            $io->text('<fg=gray>Auto-update enabled. Applying updates...</>');
            $result = $updateManager->applyUpdates();

            if ($result->error !== '') {
                $io->warning("Auto-update failed: {$result->error}");
                return;
            }

            $io->success('Updates applied. Restarting...');
            // Set restart flag — the REPL loop will pick this up
            $this->restartRequested = true;
        }
    }
}
