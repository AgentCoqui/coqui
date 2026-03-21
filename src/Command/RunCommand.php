<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\TitleGenerator;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\InteractiveApprovalPolicy;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Config\SetupWizard;
use CoquiBot\Coqui\Config\UpdateManager;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Renderer\JsonRenderer;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
use CoquiBot\Coqui\Storage\SessionStorage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
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
    private EscCancellationObserver $escObserver;
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
            ->addOption('workdir', null, InputOption::VALUE_REQUIRED, 'Working directory (project root)', getcwd() ?: '.')
            ->addOption('wizard', 'w', InputOption::VALUE_NONE, 'Run the setup wizard to edit configuration (no REPL, no session)')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace directory (overrides config and default)')
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

        $workspaceOverride = $this->resolveWorkspaceOverride($input);

        // Handle --wizard: lightweight boot + setup wizard, then exit
        if ((bool) $input->getOption('wizard')) {
            $this->boot = new BootManager($this->workDir, $workspaceOverride);
            $this->boot->bootForWizard($io, $configPath);
            return $this->runWizardAndExit($io);
        }

        $this->boot = new BootManager($this->workDir, $workspaceOverride);
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
        $terminalObserver = new TerminalObserver($output);
        $this->escObserver = new EscCancellationObserver(
            $terminalObserver,
            new ProcessCancellationToken(),
            $output,
        );

        // Initialize agent runner
        $this->agentRunner = new AgentRunner(
            roleResolver: $this->boot->roleResolver(),
            config: $this->boot->config(),
            projectRoot: $this->workDir,
            workspacePath: $this->boot->workspacePath(),
            storage: $this->storage,
            observer: $this->escObserver,
            discovery: $this->boot->discovery(),
            blacklist: $this->boot->blacklist(),
            credentialResolver: $this->boot->credentialResolver(),
            skillDiscovery: $this->boot->skillDiscovery(),
            roleDiscovery: $this->boot->roleDiscovery(),
            unsafeMode: $this->unsafeMode,
            backgroundTasksEnabled: true,
            memoryStore: $this->boot->memoryStore(),
            memorySummarizer: $this->boot->memorySummarizer(),
            mountManager: $this->boot->mountManager(),
            configManager: $this->boot->configManager(),
            configGuard: new ConfigGuard(),
            visibilityRegistry: $this->boot->visibilityRegistry(),
            spaceToolkit: $this->boot->spaceToolkit(),
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
        $io->writeln([
            '',
            '<fg=green> ▄▄·       .▄▄▄  ▄• ▄▌▪  ▄▄▄▄·       ▄▄▄▄▄</>',
            '<fg=green>▐█ ▌▪▪     ▐▀•▀█ █▪██▌██ ▐█ ▀█▪▪     •██  </>',
            '<fg=green>██ ▄▄ ▄█▀▄ █▌·.█▌█▌▐█▌▐█·▐█▀▀█▄ ▄█▀▄  ▐█.▪</>',
            '<fg=green>▐███▌▐█▌.▐▌▐█▪▄█·▐█▄█▌▐█▌██▄▪▐█▐█▌.▐▌ ▐█▌·</>',
            '<fg=green>·▀▀▀  ▀█▄▀▪·▀▀█.  ▀▀▀ ▀▀▀·▀▀▀▀  ▀█▄▀▪ ▀▀▀ </>',
            '',
        ]);
        $io->section('REPL');
        $io->text([
            '<fg=gray>Session:</> ' . substr($this->sessionId, 0, 8) . '...',
            '<fg=gray>Model:</> ' . $this->boot->roleResolver()->resolve('orchestrator'),
            '<fg=gray>Project root:</> ' . $this->workDir,
            '<fg=gray>Workspace:</> ' . $this->boot->workspacePath(),
            '',
            '<fg=gray>Commands: /new, /history, /sessions, /tasks, /config, /update, /restart, /quit</>',
            '<fg=gray>Press ESC or Ctrl+C to cancel agent</>',
        ]);
        $io->newLine();

        // REPL loop
        return $this->runRepl($io);
    }

    /**
     * Interactive REPL loop — reads user input, dispatches commands and agent turns.
     *
     * Signal handling:
     *   During the input prompt, readline's callback API is used with stream_select
     *   so SIGINT is delivered without blocking. The blocking readline() function
     *   internally catches SIGINT (via the readline/libedit C library) and re-prompts
     *   without returning, which forces the user to press Enter after Ctrl+C.
     *   The callback + stream_select approach returns control to PHP between keystrokes,
     *   allowing immediate signal delivery and clean exit.
     *
     *   During agent execution, SIGINT is restored to SIG_DFL so Ctrl+C kills the
     *   process immediately — this is the only reliable way to interrupt blocking
     *   HTTP calls to LLM providers. The launcher treats exit code 130 (SIGINT default)
     *   as a clean exit and stops background services.
     */
    private function runRepl(SymfonyStyle $io): int
    {
        $hasSignals = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');

        if ($hasSignals) {
            pcntl_async_signals(true);
        }

        // Tab autocomplete for REPL slash commands and toolkit names
        if (function_exists('readline_completion_function')) {
            readline_completion_function(function (string $input, int $index): array {
                $raw  = function_exists('readline_info') ? readline_info('line_buffer') : $input;
                $line = trim(is_string($raw) ? $raw : $input);
                $parts = explode(' ', $line);
                $cmd = $parts[0];

                // Complete toolkit/tool names after /toolkits subcommand
                if (count($parts) >= 2 && in_array($cmd, ['/toolkits'], strict: true)) {
                    $sub = $parts[1];
                    $toolkitSubCommands = ['enable', 'stub', 'disable'];

                    if (count($parts) === 2) {
                        // Complete the subcommand
                        return array_filter(
                            $toolkitSubCommands,
                            fn(string $s) => str_starts_with($s, $input),
                        );
                    }

                    if (count($parts) === 3 && in_array($sub, $toolkitSubCommands, strict: true)) {
                        // Complete package/tool names
                        $prefix = $parts[2];
                        $candidates = [];

                        // Package names
                        $allPackages = $this->boot->discovery()->allWithVisibility();
                        foreach ($allPackages as $entry) {
                            $candidates[] = $entry['package'];
                        }

                        // Standalone tool names (via tool: prefix)
                        $state = $this->boot->visibilityRegistry()->all();
                        foreach (array_keys($state['tools']) as $toolName) {
                            $candidates[] = 'tool:' . $toolName;
                        }

                        return array_filter(
                            $candidates,
                            fn(string $c) => str_starts_with($c, $prefix),
                        );
                    }
                }

                // Complete top-level slash commands
                if (str_starts_with($input, '/') || $line === '' || $line === '/') {
                    $commands = [
                        '/new', '/history', '/sessions', '/resume', '/model',
                        '/config', '/tasks', '/task', '/task-cancel', '/toolkits',
                        '/prompt', '/update', '/restart', '/space', '/space skills', '/space toolkits', '/help', '/quit',
                    ];

                    return array_filter($commands, fn(string $c) => str_starts_with($c, $input));
                }

                return [];
            });
        }

        // Stty state for ESC detection — updated before each agent turn, cleared after.
        // Captured by the shutdown function to restore the terminal if the process crashes.
        $shutdownStty = null;
        register_shutdown_function(static function () use (&$shutdownStty): void {
            // PHPStan cannot model that $shutdownStty (captured by reference) is
            // mutated later; it treats it as always-null inside the closure.
            // @phpstan-ignore booleanAnd.alwaysFalse, notIdentical.alwaysFalse, notIdentical.alwaysTrue
            if ($shutdownStty !== null && $shutdownStty !== '') {
                shell_exec('stty ' . escapeshellarg($shutdownStty) . ' 2>/dev/null');
            }
        });

        // Track consecutive Ctrl+C presses at the readline prompt.
        // Persists across iterations; reset on successful input.
        $quitAttempts = 0;

        while (true) {
            $io->writeln('');
            $io->writeln(' <fg=cyan>You:</>');

            // Read input using readline's callback API for non-blocking signal handling.
            // The blocking readline() swallows SIGINT internally (the readline/libedit
            // library catches the signal and re-prompts without returning). The callback
            // + stream_select approach returns control to PHP between keystrokes so
            // SIGINT can be delivered within ~200ms.
            $line = null;
            $lineReady = false;
            // Per-iteration flag: did a SIGINT arrive during this readline cycle?
            // Using a boolean flag (not counting in the handler) prevents two rapid
            // Ctrl+C presses from both being counted before the inner loop exits,
            // which was causing $quitAttempts to reach 2 on what felt like one press.
            $ctrlCPressed = false;

            readline_callback_handler_install(' › ', static function (?string $input) use (&$line, &$lineReady): void {
                $line = $input;
                $lineReady = true;
            });

            // Install our SIGINT handler AFTER readline's setup — readline installs
            // its own handler during callback_handler_install, so ours must come last.
            if ($hasSignals) {
                pcntl_signal(SIGINT, static function () use (&$ctrlCPressed, &$lineReady): void {
                    $ctrlCPressed = true;
                    $lineReady = true; // break the inner wait loop
                });
            }

            while (!$lineReady) {
                $read = [STDIN];
                $write = $except = [];
                /** @var int|false $ready */
                $ready = @stream_select($read, $write, $except, 0, 200000); // 200ms

                if ($ready > 0) {
                    readline_callback_read_char();
                }
            }

            readline_callback_handler_remove();

            // Ctrl+C — count attempts; exit only on the second consecutive press.
            // A single Ctrl+C at the prompt shows a hint without quitting, matching
            // the behaviour during agent execution where first Ctrl+C = cancel, not exit.
            if ($ctrlCPressed) {
                $quitAttempts++;
                if ($quitAttempts >= 2) {
                    // When running under the launcher (COQUI_LAUNCHER=1), the launcher
                    // owns the shutdown UX — exit silently to avoid duplicate messages.
                    if (getenv('COQUI_LAUNCHER') !== '1') {
                        $io->newLine();
                        $io->info('Shutting down Coqui.');
                    }
                    return 0;
                }
                $io->writeln('<fg=yellow>(Press Ctrl+C again to quit.)</>');
                continue;
            }

            // Ctrl+D (EOF) with STDIN closed — exit cleanly
            if ($line === null && feof(STDIN)) {
                return 0;
            }

            if ($line === null || trim($line) === '') {
                continue;
            }

            $prompt = trim($line);
            readline_add_history($prompt);
            $quitAttempts = 0; // reset on successful input

            // Handle commands
            if (str_starts_with($prompt, '/')) {
                $result = $this->handleCommand($prompt, $io);
                if ($result !== true) {
                    return $result;
                }
                continue;
            }

            // Build execution policy and a fresh cancellation token for this turn
            $executionPolicy = $this->buildInteractiveExecutionPolicy($this->sessionId, $io);
            $cancellationToken = new ProcessCancellationToken();
            $this->escObserver->setToken($cancellationToken);
            $sigintCount = 0;

            // Two-stage Ctrl+C:
            //   First  → cooperative cancel (sets token; agent stops after current response)
            //   Second → restore SIG_DFL and re-raise to kill immediately (exit 130)
            //
            // Note: cooperative cancellation cannot interrupt blocking curl_exec() calls.
            // The token is checked between iterations and between tool calls. The second
            // Ctrl+C provides an immediate-kill escape hatch for truly stuck states.
            if ($hasSignals) {
                pcntl_signal(SIGINT, static function () use ($cancellationToken, &$sigintCount, $io): void {
                    $sigintCount++;
                    if ($sigintCount === 1) {
                        $cancellationToken->cancel();
                        $io->writeln(
                            "\n<fg=yellow>⚑ Stopping — completing current LLM response, then returning to prompt. Press Ctrl+C again to force quit.</>",
                        );
                    } else {
                        pcntl_signal(SIGINT, SIG_DFL);
                        posix_kill(posix_getpid(), SIGINT);
                    }
                });
            }

            // Enter raw stty mode so ESC is delivered byte-by-byte (no Enter required).
            // -echo suppresses echoing of keystrokes (ESC, Ctrl+C) so they don't
            // appear as ^[ or ^C in the middle of streamed agent output.
            // Save state first so we can restore it after the turn completes.
            $savedStty = $this->saveSttyState();
            $shutdownStty = $savedStty;
            $this->enterRawSttyMode();
            $this->escObserver->active = true;

            // Run agent — try/finally guarantees cleanup even if an exception escapes
            // (e.g. an uncaught error thrown from inside the signal handler).
            try {
                $result = $this->agentRunner->run($prompt, $this->sessionId, $executionPolicy, $cancellationToken);
            } finally {
                $this->escObserver->active = false;
                // Drain leftover ESC bytes BEFORE restoring the terminal.
                // In raw mode (set by enterRawSttyMode) bytes are available to
                // stream_select immediately. Once restoreSttyState switches back to
                // canonical mode the kernel buffers keystrokes until Enter, so
                // stream_select with timeout 0 would see nothing and the ESC bytes
                // would leak into the next readline cycle.
                $this->drainStdin();
                $this->restoreSttyState($savedStty);
                $shutdownStty = null;
            }

            // Render output
            $renderer = new TerminalRenderer($io);
            $renderer->render($result, contentStreamed: true);

            // Generate session title on first turn (best-effort, stored for API clients)
            $this->maybeGenerateTitle($prompt);

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

        $firstResult = match ($cmd) {
            '/quit', '/exit', '/q' => (function () use ($io) {
                // When running under the launcher, it owns the shutdown UX
                if (getenv('COQUI_LAUNCHER') !== '1') {
                    $io->newLine();
                    $io->info('Shutting down Coqui.');
                }
                return false;
            })(),

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
                return $this->handleConfigCommand($io, $arg);
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

            '/update' => (function () {
                return true;
            })(),

            '/toolkits' => (function () use ($io, $arg) {
                return $this->handleToolkitsCommand($io, $arg);
            })(),

            '/prompt' => (function () use ($io) {
                $preview = $this->agentRunner->buildPromptPreview();
                $io->section('System Prompt');
                $io->writeln($preview['prompt']);
                $io->newLine();
                $io->text([
                    '<fg=gray>Tool count:</> ' . $preview['tool_count'],
                    '<fg=gray>Toolkit count:</> ' . $preview['toolkit_count'],
                    '<fg=gray>Prompt tokens:</> ' . number_format($preview['prompt_tokens']),
                    '<fg=gray>Tool schema tokens:</> ' . number_format($preview['tool_tokens']),
                    '<fg=gray>Estimated total:</> ' . number_format($preview['total_tokens']),
                ]);
                return true;
            })(),

            '/summarize' => (function () use ($io, $arg) {
                return $this->handleSummarizeCommand($io, $arg);
            })(),

            '/space' => (function () use ($io, $arg) {
                return $this->handleSpaceCommand($io, $arg);
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
                        ['/config', 'Show config (use /config edit to reconfigure + restart)'],
                        ['/tasks [status]', 'List background tasks (optionally filter by status)'],
                        ['/task <id>', 'Show background task status and recent events'],
                        ['/task-cancel <id>', 'Cancel a pending or running background task'],
                        ['/toolkits [enable|stub|disable <pkg|tool:name>]', 'Manage toolkit visibility'],
                        ['/prompt', 'Show the full system prompt sent to the LLM'],
                        ['/space [search|install|remove|installed|skills|toolkits|update]', 'Coqui Space marketplace'],
                        ['/summarize [recent N] [focus "topic"]', 'Summarize conversation history to save tokens'],
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

        // If a command handler returned an exit code (e.g. /config edit → restart), propagate it
        if (is_int($firstResult)) {
            return $firstResult;
        }

        return match ($cmd) {
            '/quit', '/exit', '/q' => Command::SUCCESS,
            '/restart' => self::RESTART_EXIT_CODE,
            '/update' => $this->runUpdate($io),
            '/toolkits', '/prompt', '/summarize' => true,
            default => true,
        };
    }

    /**
     * Handle the /toolkits [enable|stub|disable <pkg|tool:<name>>] command.
     *
     * Without arguments: lists all packages with their current visibility.
     * With action + target: sets the visibility for a package or standalone tool.
     */
    private function handleToolkitsCommand(SymfonyStyle $io, string $arg): true
    {
        $registry = $this->boot->visibilityRegistry();
        $discovery = $this->boot->discovery();

        if (trim($arg) === '') {
            // Build token breakdown index by class FQCN
            $preview = $this->agentRunner->buildPromptPreview();
            $tokensByClass = [];
            foreach ($preview['toolkit_breakdown'] as $entry) {
                $tokensByClass[$entry['class']] = $entry;
            }

            // List all packages with visibility and token counts
            $rows = [];
            foreach ($discovery->allWithVisibility() as $entry) {
                $pkgTokens = 0;
                foreach ($entry['classes'] as $cls) {
                    if (isset($tokensByClass[$cls])) {
                        $pkgTokens += $tokensByClass[$cls]['total_tokens'];
                    }
                }
                $rows[] = [$entry['package'], $entry['visibility'], number_format($pkgTokens)];
            }

            $state = $registry->all();
            foreach ($state['tools'] as $toolName => $vis) {
                $rows[] = ['tool:' . $toolName, $vis, '-'];
            }

            if (empty($rows)) {
                $io->text('No toolkits registered. Install a toolkit package first.');
            } else {
                $io->table(['Package / Tool', 'Visibility', 'Tokens'], $rows);
                $io->text([
                    '<fg=gray>Prompt tokens:</> ' . number_format($preview['prompt_tokens'])
                        . '<fg=gray> • Tool schema tokens:</> ' . number_format($preview['tool_tokens'])
                        . '<fg=gray> • Total:</> ' . number_format($preview['total_tokens']),
                    '<fg=gray>Use /toolkits enable|stub|disable <pkg> or tool:<name></>',
                ]);
            }

            return true;
        }

        $parts = explode(' ', trim($arg), 2);
        $action = strtolower($parts[0]);
        $target = $parts[1] ?? '';

        if (!in_array($action, ['enable', 'stub', 'disable'], strict: true)) {
            $io->error("Unknown action: {$action}. Use enable, stub, or disable.");
            return true;
        }

        if ($target === '') {
            $io->error("Usage: /toolkits {$action} <package/name|tool:<name>>");
            return true;
        }

        $visibility = match ($action) {
            'enable'  => ToolkitVisibility::Enabled,
            'stub'    => ToolkitVisibility::Stub,
            'disable' => ToolkitVisibility::Disabled,
        };

        try {
            if (str_starts_with($target, 'tool:')) {
                $toolName = substr($target, 5);
                $registry->setToolVisibility($toolName, $visibility);
                $io->success("Tool \"{$toolName}\" set to {$visibility->value}. Restart to apply.");
            } else {
                $registry->setPackageVisibility($target, $visibility);
                $io->success("Package \"{$target}\" set to {$visibility->value}. Restart to apply.");
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
        }

        return true;
    }

    /**
     * Handle /summarize command to compress conversation history.
     *
     * Usage:
     *   /summarize              — summarize all but the 3 most recent turns
     *   /summarize recent N     — keep N most recent turns
     *   /summarize focus "topic" — emphasize a specific topic in the summary
     */
    private function handleSummarizeCommand(SymfonyStyle $io, string $arg): true
    {
        // Read configurable keepRecentTurns from config
        $config = $this->boot->config();
        $configKeepRecent = $config->get('agents.defaults.context.keepRecentTurns');
        $keepRecent = is_numeric($configKeepRecent) ? (int) $configKeepRecent : 3;
        $focus = null;

        // Parse arguments
        $arg = trim($arg);
        if ($arg !== '') {
            if (preg_match('/^recent\s+(\d+)/i', $arg, $matches)) {
                $keepRecent = max(1, min(20, (int) $matches[1]));
                $arg = trim(substr($arg, strlen($matches[0])));
            }
            if (preg_match('/focus\s+"([^"]+)"/i', $arg, $matches)) {
                $focus = $matches[1];
            } elseif (preg_match('/focus\s+(\S+)/i', $arg, $matches)) {
                $focus = $matches[1];
            }
        }

        $summarizer = new ConversationSummarizer(
            storage: $this->storage,
            memoryStore: $this->boot->memoryStore(),
        );

        // Resolve a cheap provider for summarization via utility model chain
        $factory = new ProviderFactory($config);
        $provider = null;

        try {
            $utilityModel = $this->boot->roleResolver()->resolveUtility();
            if ($utilityModel !== '') {
                $provider = $factory->create($utilityModel);
            }
        } catch (\Throwable) {
            // Fall through
        }

        if ($provider === null) {
            try {
                $orchestratorModel = $this->boot->roleResolver()->resolve('orchestrator');
                $provider = $factory->create($orchestratorModel);
            } catch (\Throwable) {
                $io->error('Could not resolve a provider for summarization.');
                return true;
            }
        }

        $io->text('<fg=gray>Summarizing conversation...</>');

        try {
            $result = $summarizer->summarizeAndPersist(
                sessionId: $this->sessionId,
                provider: $provider,
                keepRecentTurns: $keepRecent,
                focus: $focus,
            );
        } catch (\Throwable $e) {
            $io->error('Summarization failed: ' . $e->getMessage());
            return true;
        }

        if (!$result->wasSummarized()) {
            $io->info('Conversation is too short to summarize.');
            return true;
        }

        $io->success(sprintf(
            'Summarized %d messages — %s → %s tokens (saved %s)',
            $result->messagesSummarized,
            number_format($result->tokensBefore),
            number_format($result->tokensAfter),
            number_format($result->tokensSaved()),
        ));

        return true;
    }

    /**
     * Handle /space slash command for Coqui Space marketplace.
     *
     * Subcommands:
     *   /space               — show status (API health, auth, installed counts)
     *   /space search <q>    — unified search for skills and toolkits
     *   /space install <id>  — install a skill (owner/name) or toolkit (vendor/package)
     *   /space remove <id>   — remove a skill or toolkit
     *   /space installed     — list all installed skills and toolkits
     *   /space update <id>   — update a skill or toolkit
     */
    private function handleSpaceCommand(SymfonyStyle $io, string $arg): true
    {
        $spaceToolkit = $this->boot->spaceToolkit();

        if ($spaceToolkit === null) {
            $io->error('Coqui Space is not initialized. Check boot configuration.');
            return true;
        }

        $client = $spaceToolkit->client();
        $skillInstaller = $spaceToolkit->skillInstaller();
        $toolkitInstaller = $spaceToolkit->toolkitInstaller();

        $parts = explode(' ', trim($arg), 2);
        $action = strtolower($parts[0]);
        $target = $parts[1] ?? '';

        if ($action === '' || $action === 'status') {
            // Status overview
            try {
                $health = $client->healthCheck();
                $status = ($health['status'] ?? 'unknown') === 'ok' ? '<fg=green>connected</>' : '<fg=red>unreachable</>';
            } catch (\Throwable) {
                $status = '<fg=red>unreachable</>';
            }

            $authenticated = $client->isAuthenticated() ? '<fg=green>yes</>' : '<fg=yellow>no (set COQUI_SPACE_API_TOKEN)</>';

            $installedSkills = $skillInstaller->list();
            $installedToolkits = $toolkitInstaller->list();

            $io->text([
                '<fg=cyan>Coqui Space</>',
                "  API: {$status}",
                "  Authenticated: {$authenticated}",
                '  Installed skills: ' . count($installedSkills),
                '  Installed toolkits: ' . count($installedToolkits),
                '',
                '<fg=gray>Commands: /space search|install|remove|installed|update</>',
            ]);
            return true;
        }

        if ($action === 'search') {
            if ($target === '') {
                $io->error('Usage: /space search <query>');
                return true;
            }

            try {
                $results = $client->searchAll($target);
                $rows = [];

                // Response shape: {skills: {results: [...]}, toolkits: {results: [...], total: '1'}}
                foreach ((array) ($results['skills']['results'] ?? []) as $skill) {
                    if (!is_array($skill)) {
                        continue;
                    }
                    $owner = (string) ($skill['owner'] ?? '');
                    $name = (string) ($skill['urlName'] ?? $skill['name'] ?? '');
                    $desc = mb_substr((string) ($skill['description'] ?? $skill['shortDescription'] ?? ''), 0, 60);
                    $rows[] = ['skill', "{$owner}/{$name}", $desc];
                }

                foreach ((array) ($results['toolkits']['results'] ?? []) as $toolkit) {
                    if (!is_array($toolkit)) {
                        continue;
                    }
                    $pkg = (string) ($toolkit['name'] ?? '');
                    $desc = mb_substr((string) ($toolkit['description'] ?? $toolkit['shortDescription'] ?? ''), 0, 60);
                    $rows[] = ['toolkit', $pkg, $desc];
                }

                if (empty($rows)) {
                    $io->text("No results found for \"{$target}\".");
                } else {
                    $io->table(['Type', 'Identifier', 'Description'], $rows);
                }
            } catch (\Throwable $e) {
                $io->error('Search failed: ' . $e->getMessage());
            }
            return true;
        }

        if ($action === 'install') {
            if ($target === '') {
                $io->error('Usage: /space install <owner/name>');
                return true;
            }

            if (!str_contains($target, '/')) {
                $io->error('Invalid identifier. Use owner/name for skills or vendor/package for toolkits.');
                return true;
            }

            try {
                // Detect skill vs toolkit: toolkit packages start with known prefixes
                // or can be determined by checking the API
                $parts = explode('/', $target, 2);
                $firstPart = $parts[0];
                $secondPart = $parts[1];

                // Try as toolkit first if it looks like a Composer package
                if (str_starts_with($firstPart, 'coquibot') || str_contains($secondPart, 'toolkit')) {
                    $result = $toolkitInstaller->install($target);
                    $io->success($result['message']);
                } else {
                    // Try as skill
                    $result = $skillInstaller->install($firstPart, $secondPart);
                    $io->success($result['message']);
                }
            } catch (\Throwable $e) {
                $io->error('Install failed: ' . $e->getMessage());
            }
            return true;
        }

        if ($action === 'remove') {
            if ($target === '') {
                $io->error('Usage: /space remove <identifier>');
                return true;
            }

            try {
                if (str_contains($target, '/')) {
                    // Toolkit (vendor/package)
                    $msg = $toolkitInstaller->remove($target);
                    $io->success($msg);
                } else {
                    // Skill (name only)
                    $msg = $skillInstaller->remove($target, purge: true);
                    $io->success($msg);
                }
            } catch (\Throwable $e) {
                $io->error('Remove failed: ' . $e->getMessage());
            }
            return true;
        }

        if ($action === 'skills') {
            $skills = $skillInstaller->list();
            if (empty($skills)) {
                $io->text('No skills installed from Coqui Space.');
                return true;
            }
            $rows = [];
            foreach ($skills as $s) {
                $rows[] = [$s['name'], $s['version'], $s['status'], $s['source']];
            }
            $io->table(['Name', 'Version', 'Status', 'Source'], $rows);
            return true;
        }

        if ($action === 'toolkits') {
            $toolkits = $toolkitInstaller->list();
            if (empty($toolkits)) {
                $io->text('No toolkits installed from Coqui Space.');
                return true;
            }
            $rows = [];
            foreach ($toolkits as $t) {
                $rows[] = [$t['package'], $t['constraint'], $t['status']];
            }
            $io->table(['Package', 'Constraint', 'Status'], $rows);
            return true;
        }

        if ($action === 'installed') {
            $skills = $skillInstaller->list();
            $toolkits = $toolkitInstaller->list();

            if (empty($skills) && empty($toolkits)) {
                $io->text('No skills or toolkits installed from Coqui Space.');
                return true;
            }

            if (!empty($skills)) {
                $io->section('Skills');
                $rows = [];
                foreach ($skills as $s) {
                    $rows[] = [$s['name'], $s['version'], $s['status'], $s['source']];
                }
                $io->table(['Name', 'Version', 'Status', 'Source'], $rows);
            }

            if (!empty($toolkits)) {
                $io->section('Toolkits');
                $rows = [];
                foreach ($toolkits as $t) {
                    $rows[] = [$t['package'], $t['constraint'], $t['status']];
                }
                $io->table(['Package', 'Constraint', 'Status'], $rows);
            }

            return true;
        }

        if ($action === 'update') {
            if ($target === '') {
                $io->error('Usage: /space update <identifier>');
                return true;
            }

            try {
                if (str_contains($target, '/')) {
                    $result = $toolkitInstaller->update($target);
                    $io->success($result['message']);
                } else {
                    $result = $skillInstaller->update($target);
                    $io->success($result['message']);
                }
            } catch (\Throwable $e) {
                $io->error('Update failed: ' . $e->getMessage());
            }
            return true;
        }

        $io->error("Unknown /space subcommand: {$action}. Use: search, install, remove, installed, skills, toolkits, update");
        return true;
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
            $rows[] = [$r, $m['model'] ?? ''];
        }

        $io->table(['Role', 'Model'], $rows);
    }

    /**
     * Handle /config subcommands.
     *
     * @return int|true True to continue the REPL loop, or an exit code to terminate.
     */
    private function handleConfigCommand(SymfonyStyle $io, string $subCommand): int|true
    {
        return match (trim($subCommand)) {
            'edit' => $this->runConfigWizard($io),
            'show' => (function () use ($io) {
                $this->showConfigFile($io);
                return true;
            })(),
            default => (function () use ($io) {
                $this->showConfigSummary($io);
                return true;
            })(),
        };
    }

    private function runConfigWizard(SymfonyStyle $io): int|true
    {
        $outputPath = $this->boot->configManager()->path();
        $wizard = new SetupWizard($io, $this->boot->defaultsLoader(), $this->boot->credentialResolver());
        $saved = $wizard->runAndSave($outputPath);

        if ($saved && file_exists($outputPath)) {
            if ($io->confirm('Restart now to apply the new configuration?', true)) {
                $io->info('Restarting Coqui...');
                return self::RESTART_EXIT_CODE;
            }
            $io->success('Configuration saved. Use /restart when ready to apply changes.');
        }

        return true;
    }

    /**
     * Run the setup wizard directly from --wizard flag and exit.
     *
     * Uses the lightweight bootForWizard() path — no session, no REPL, no API.
     */
    private function runWizardAndExit(SymfonyStyle $io): int
    {
        $outputPath = $this->boot->configManager()->path();
        $wizard = new SetupWizard($io, $this->boot->defaultsLoader(), $this->boot->credentialResolver());
        $saved = $wizard->runAndSave($outputPath);

        return $saved ? Command::SUCCESS : Command::FAILURE;
    }

    private function showConfigFile(SymfonyStyle $io): void
    {
        $configPath = $this->boot->configManager()->path();

        if (!file_exists($configPath)) {
            $io->warning('No openclaw.json found. Run /config edit to create one.');
            return;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            $io->error('Unable to read openclaw.json.');
            return;
        }

        $io->section('openclaw.json (' . $configPath . ')');
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
                $rows[] = [$role, $model['model'] ?? ''];
            }
            $io->table(['Role', 'Model'], $rows);
        }

        // Workspace and config location
        $io->writeln('<fg=gray>Config:</> ' . $this->boot->configManager()->path());
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
                $stdinContent = stream_get_contents(STDIN);
                $prompt = $stdinContent !== false ? $stdinContent : null;
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
            memoryStore: $this->boot->memoryStore(),
            memorySummarizer: $this->boot->memorySummarizer(),
            mountManager: $this->boot->mountManager(),
            configManager: $this->boot->configManager(),
            configGuard: new ConfigGuard(),
            spaceToolkit: $this->boot->spaceToolkit(),
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
            gatedTools: $this->mergeGatedTools(),
            blacklist: $this->boot->blacklist(),
            storage: $this->storage,
            sessionId: $sessionId,
            turnId: $turnId,
        );
    }

    /**
     * Merge hardcoded gated tools with package-declared gated tools.
     *
     * Packages declare gated operations in composer.json via extra.php-agents.gated.
     * These are merged with the hardcoded GATED_TOOLS constant so that both
     * core and toolkit-declared gates are enforced.
     *
     * @return array<string, list<mixed>>
     */
    private function mergeGatedTools(): array
    {
        $discoveredGated = $this->boot->discovery()->collectAllGatedTools();

        if (empty($discoveredGated)) {
            return self::GATED_TOOLS;
        }

        $merged = self::GATED_TOOLS;

        foreach ($discoveredGated as $toolName => $rules) {
            if (!isset($merged[$toolName])) {
                $merged[$toolName] = $rules;
            } else {
                // If either set contains a wildcard, keep the wildcard
                if ($rules === ['*'] || $merged[$toolName] === ['*']) {
                    $merged[$toolName] = ['*'];
                } else {
                    $merged[$toolName] = array_merge($merged[$toolName], $rules);
                }
            }
        }

        return $merged;
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

    // --- Terminal / stty helpers for ESC detection ---

    /**
     * Returns true when STDIN is an interactive TTY.
     *
     * Used to gate all stty operations — piped input, Docker without PTY, and CI
     * environments will return false, causing ESC detection to degrade gracefully.
     */
    private function isInteractiveTty(): bool
    {
        // stream_isatty() is more reliable than posix_isatty() for PHP stream resources.
        // posix_isatty() can return false on macOS for php://stdin even in a real TTY.
        return stream_isatty(STDIN);
    }

    /**
     * Save the current terminal state via `stty -g` so it can be restored later.
     *
     * Returns null when not a TTY or when stty is unavailable.
     */
    private function saveSttyState(): ?string
    {
        if (!$this->isInteractiveTty()) {
            return null;
        }

        $state = shell_exec('stty -g 2>/dev/null');

        return is_string($state) ? trim($state) : null;
    }

    /**
     * Switch the terminal to raw, non-blocking mode so ESC is delivered
     * byte-by-byte without waiting for Enter.
     *
     * `min 0 time 0` causes `fread()` to return immediately even when no bytes
     * are available, which is required for the non-blocking STDIN check in
     * EscCancellationObserver::update().
     */
    private function enterRawSttyMode(): void
    {
        if (!$this->isInteractiveTty()) {
            return;
        }

        shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');
        // Also set PHP's stream layer to non-blocking so fread() returns immediately
        // when no bytes are available, regardless of the OS-level stty settings.
        stream_set_blocking(STDIN, false);
    }

    /**
     * Restore the terminal to a previously saved state.
     *
     * Called after each agent turn and from the shutdown function if the process
     * crashes while in raw mode.
     */
    private function restoreSttyState(?string $state): void
    {
        if ($state === null || $state === '' || !$this->isInteractiveTty()) {
            return;
        }

        shell_exec('stty ' . escapeshellarg($state) . ' 2>/dev/null');
        // Restore PHP stream to blocking so readline's stream_select loop works correctly.
        stream_set_blocking(STDIN, true);
    }

    /**
     * Drain any pending bytes from STDIN without blocking.
     *
     * Called after each agent turn to discard leftover ESC keypresses or other
     * stray bytes that accumulated during execution. Prevents them from being
     * misread as cancellation signals at the start of the next turn.
     */
    private function drainStdin(): void
    {
        if (!$this->isInteractiveTty()) {
            return;
        }

        $read = [STDIN];
        $write = $except = [];
        while (@stream_select($read, $write, $except, 0, 0) > 0) {
            @fread(STDIN, 128);
            $read = [STDIN];
            $write = $except = [];
        }
    }

    /**
     * Generate and store a session title if one doesn't exist yet.
     *
     * Best-effort — failures are logged but never surface to the user.
     * Only runs on the first turn of a session (skipped when title already set).
     */
    private function maybeGenerateTitle(string $prompt): void
    {
        try {
            $session = $this->storage->getSession($this->sessionId);
            if ($session === null || ($session['title'] ?? null) !== null) {
                return;
            }

            $titleGenerator = new TitleGenerator(
                roleResolver: $this->boot->roleResolver(),
                config: $this->boot->config(),
                roleDiscovery: $this->boot->roleDiscovery(),
            );

            $title = $titleGenerator->generate($prompt);
            if ($title === null) {
                return;
            }

            $this->storage->updateSessionTitle($this->sessionId, $title);
        } catch (\Throwable $e) {
            error_log(sprintf('[Coqui] REPL title generation failed: %s', $e->getMessage()));
        }
    }
}
