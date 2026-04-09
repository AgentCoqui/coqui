<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\ConcurrentToolExecutor;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Agent\QualityAutomationStatusService;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\RoleUpdateInfo;
use CoquiBot\Coqui\Command\WorkspaceOverrideResolver;
use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Renderer\JsonRenderer;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
use CoquiBot\Coqui\Repl\AgentTurnExecutor;
use CoquiBot\Coqui\Repl\ExecutionPolicyFactory;
use CoquiBot\Coqui\Repl\NotificationPresenter;
use CoquiBot\Coqui\Repl\Handler\BudgetHandler;
use CoquiBot\Coqui\Repl\Handler\ConfigHandler;
use CoquiBot\Coqui\Repl\Handler\ConversationHandler;
use CoquiBot\Coqui\Repl\Handler\EvaluationHandler;
use CoquiBot\Coqui\Repl\Handler\LoopHandler;
use CoquiBot\Coqui\Repl\Handler\ProjectHandler;
use CoquiBot\Coqui\Repl\Handler\QualityHandler;
use CoquiBot\Coqui\Repl\Handler\RoleHandler;
use CoquiBot\Coqui\Repl\Handler\ScheduleHandler;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Repl\Handler\SpaceHandler;
use CoquiBot\Coqui\Repl\Handler\TaskHandler;
use CoquiBot\Coqui\Repl\Handler\TodoHandler;
use CoquiBot\Coqui\Repl\Handler\ToolkitVisibilityHandler;
use CoquiBot\Coqui\Repl\Handler\WebhookHandler;
use CoquiBot\Coqui\Repl\SlashCommandRouter;
use CoquiBot\Coqui\Repl\TabCompletion;
use CoquiBot\Coqui\Repl\TerminalStateManager;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
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
    private const float NOTIFICATION_IDLE_POLL_INTERVAL_SECONDS = 0.5;
    private const string DEFAULT_READLINE_PROMPT = ' › ';

    /**
     * Exit code that signals the launcher script to restart the process.
     * Chosen to avoid collision with Symfony Console reserved codes (0, 1, 2)
     * and common signal-based codes (128+N).
     */
    public const RESTART_EXIT_CODE = 10;

    private BootManager $boot;
    private AgentRunner $agentRunner;
    private ?LoopExecutor $loopExecutor = null;
    private EscCancellationObserver $escObserver;
    private ?AnimatedTickCallback $animatedTickCallback = null;
    private SessionStorage $storage;
    private string $sessionId;
    private string $workDir;
    private bool $unsafeMode = false;
    private bool $autoApprove = false;
    private bool $restartRequested = false;
    private bool $continueMode = false;
    private bool $hintsEnabled = true;
    private string $activeRole = 'orchestrator';
    private ?string $activeProjectId = null;
    private ?string $activeProjectSlug = null;

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
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format for --no-terminal mode (text or json)', 'text')
            ->addOption('continue', null, InputOption::VALUE_NONE, 'Resume the last session and automatically send "Continue." as the first prompt');
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
        $this->continueMode = (bool) $input->getOption('continue');

        // Validate --continue + --no-terminal combination
        if ($this->continueMode && $noTerminal) {
            $io->error('Cannot combine --continue with --no-terminal. The --continue flag starts the REPL.');
            return Command::FAILURE;
        }

        // Boot sequence: config, workspace, credentials, toolkit discovery
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $workspaceOverride = WorkspaceOverrideResolver::resolve($input);

        // Handle --wizard: lightweight boot + setup wizard, then exit
        if ((bool) $input->getOption('wizard')) {
            $this->boot = new BootManager($this->workDir, $workspaceOverride);
            $this->boot->bootForWizard($io, $configPath);
            $configHandler = new ConfigHandler($this->boot, $this->workDir);
            return $configHandler->runWizardAndExit($io);
        }

        $this->boot = new BootManager($this->workDir, $workspaceOverride);
        $this->boot->boot($noTerminal ? null : $io, $configPath);

        // Handle --update: apply updates and restart
        if ((bool) $input->getOption('update')) {
            $configHandler = new ConfigHandler($this->boot, $this->workDir);
            $updateResult = $configHandler->runUpdate($io);
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
        $configHandler = new ConfigHandler($this->boot, $this->workDir);
        $this->restartRequested = $configHandler->checkForUpdatesOnStartup($io);

        // If auto-update triggered a restart, exit now
        if ($this->restartRequested) {
            return self::RESTART_EXIT_CODE;
        }

        // Choose observer for terminal mode
        $terminalObserver = new TerminalObserver($output);

        // Animated tick callback for spinner during tool execution
        $this->animatedTickCallback = new AnimatedTickCallback($output);
        $terminalObserver->setTickCallback($this->animatedTickCallback);

        $this->escObserver = new EscCancellationObserver(
            $terminalObserver,
            new ProcessCancellationToken(),
            $output,
        );

        // Initialize agent runner
        $this->agentRunner = AgentRunnerFactory::create(
            boot: $this->boot,
            projectRoot: $this->workDir,
            storage: $this->storage,
            observer: $this->escObserver,
            unsafeMode: $this->unsafeMode,
            backgroundTasksEnabled: true,
            includeConfigManager: true,
            includeVisibilityRegistry: true,
            includeLoadingData: true,
            tickCallback: $this->animatedTickCallback,
            toolExecutor: new ConcurrentToolExecutor(),
        );

        // Initialize loop execution pipeline (requires stores from boot)
        $loopStore = $this->boot->loopStore();
        $projectStore = $this->boot->projectStore();
        $artifactStore = $this->boot->artifactStore();
        $loopDiscovery = $this->boot->loopDiscovery();

        if ($loopStore !== null && $projectStore !== null && $artifactStore !== null && $loopDiscovery !== null) {
            $this->loopExecutor = new LoopExecutor(
                loopStore: $loopStore,
                projectStore: $projectStore,
                sessionStorage: $this->storage,
                todoStore: $this->boot->todoStore(),
                artifactStore: $artifactStore,
            );
        }

        // Handle session
        $sessionHandler = new SessionHandler($this->boot, $this->storage);
        if ($this->continueMode) {
            // --continue: always resume the last session (from .coqui-session or most recent in DB)
            $this->sessionId = $sessionHandler->loadOrCreateSession($io);
            $restored = $sessionHandler->restoreActiveRoleFromSession($this->sessionId);
            if ($restored !== null) {
                $this->activeRole = $restored;
            }
        } elseif ($input->getOption('new')) {
            $this->sessionId = $sessionHandler->createNewSession();
        } elseif ($input->getOption('session')) {
            $this->sessionId = $input->getOption('session');
            if ($this->storage->getSession($this->sessionId) === null) {
                $io->error("Session not found: {$this->sessionId}");
                return Command::FAILURE;
            }
            $restored = $sessionHandler->restoreActiveRoleFromSession($this->sessionId);
            if ($restored !== null) {
                $this->activeRole = $restored;
            }
            $io->info("Resumed session: {$this->sessionId}");
        } else {
            $this->sessionId = $sessionHandler->loadOrCreateSession($io);
            $restored = $sessionHandler->restoreActiveRoleFromSession($this->sessionId);
            if ($restored !== null) {
                $this->activeRole = $restored;
            }
        }

        // Load hints preference from config (default: enabled)
        $hintsConfig = $this->boot->config()->get('agents.defaults.hints', true);
        $this->hintsEnabled = (bool) $hintsConfig;

        // Restore active project from session
        $this->restoreActiveProject();

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

        $bannerLines = [
            '<fg=gray>Session:</> ' . substr($this->sessionId, 0, 8) . '...',
            '<fg=gray>Model:</> ' . $this->boot->roleResolver()->resolve('orchestrator'),
            '<fg=gray>Project root:</> ' . $this->workDir,
            '<fg=gray>Workspace:</> ' . $this->boot->workspacePath(),
        ];

        if ($this->hintsEnabled) {
            $io->section('REPL');
            $bannerLines[] = '';
            $bannerLines[] = '<fg=gray>Commands: /config, /new, /sessions, /roles, /tasks, /help, /update, /restart, /quit</>';
        }

        $io->text($bannerLines);
        $io->newLine();

        // Notify about pending role updates from boot
        $pendingUpdates = $this->boot->pendingRoleUpdates();
        if ($pendingUpdates !== []) {
            $names = array_map(fn(RoleUpdateInfo $u) => $u->roleName, $pendingUpdates);
            $io->note(sprintf(
                'Built-in updates available for %d role(s): %s. Use /roles update to review.',
                count($names),
                implode(', ', $names),
            ));
        }

        // REPL loop
        return $this->runRepl($io);
    }

    /**
     * Interactive REPL loop — reads user input, dispatches commands and agent turns.
     */
    private function runRepl(SymfonyStyle $io): int
    {
        $hasSignals = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');

        if ($hasSignals) {
            pcntl_async_signals(true);
        }

        // Tab autocomplete for REPL slash commands
        $tabCompletion = new TabCompletion($this->boot, $this->storage);
        $tabCompletion->setSessionId($this->sessionId);
        $tabCompletion->register();

        // Terminal state manager for ESC detection
        $terminalState = new TerminalStateManager();

        // Stty state shutdown guard
        $shutdownStty = null;
        $shutdownGuard = $terminalState->registerShutdownGuard();

        // Build handlers
        $sessionHandler = new SessionHandler($this->boot, $this->storage);
        $policyFactory = new ExecutionPolicyFactory(
            $this->boot->blacklist(),
            $this->storage,
            $this->boot->discovery(),
        );

        $turnExecutor = new AgentTurnExecutor(
            $this->agentRunner,
            $this->boot,
            $this->storage,
            $this->escObserver,
            $terminalState,
            $policyFactory,
            $this->animatedTickCallback,
        );

        $notificationConfig = $this->boot->config()->getNotificationConfig();
        $notificationsEnabled = $notificationConfig['enabled'];
        $notificationStore = $notificationsEnabled ? $this->boot->notificationStore() : null;
        $notificationPresenter = new NotificationPresenter();
        $notificationLimit = $notificationConfig['replDisplayLimit'];

        $router = new SlashCommandRouter(
            session: $sessionHandler,
            task: new TaskHandler($this->storage),
            todo: new TodoHandler($this->boot->todoStore()),
            schedule: new ScheduleHandler($this->storage),
            budget: new BudgetHandler($this->agentRunner),
            quality: new QualityHandler(
                new QualityAutomationStatusService(
                    config: $this->boot->config(),
                    storage: $this->storage,
                    evaluationStore: new EvaluationStore($this->storage->getPdo()),
                    scheduleStore: new ScheduleStore($this->storage->getPdo()),
                ),
            ),
            project: new ProjectHandler($this->boot, $this->storage),
            role: new RoleHandler($this->boot, $this->storage),
            toolkitVisibility: new ToolkitVisibilityHandler($this->boot, $this->agentRunner),
            space: new SpaceHandler($this->boot),
            config: new ConfigHandler($this->boot, $this->workDir),
            conversation: new ConversationHandler($this->boot, $this->storage),
            webhook: new WebhookHandler($this->storage),
            evaluation: new EvaluationHandler($this->storage),
            loop: new LoopHandler(
                $this->storage,
                $this->boot->loopDiscovery(),
                $this->loopExecutor,
                $terminalState,
                [
                    'maxIterations' => $this->boot->config()->get('agents.defaults.maxIterations'),
                    'budgetExitThreshold' => $this->boot->config()->get('agents.defaults.context.budgetExitThreshold'),
                    'autoSummarizeThreshold' => $this->boot->config()->get('agents.defaults.context.autoSummarizeThreshold'),
                    'autoSummarizeKeepRecent' => $this->boot->config()->get('agents.defaults.context.autoSummarizeKeepRecent'),
                ],
            ),
            agentRunner: $this->agentRunner,
            onHintsToggle: function () use ($io): void {
                $this->hintsEnabled = !$this->hintsEnabled;
                $this->boot->configManager()->set('agents.defaults.hints', $this->hintsEnabled);
                $io->success($this->hintsEnabled ? 'Hints enabled' : 'Hints disabled');
            },
        );

        // --continue: auto-send "Continue." as the first prompt without displaying it
        $pendingPrompt = $this->continueMode ? 'Continue.' : null;

        while (true) {
            // If there's a pending prompt (from --continue), skip user input
            if ($pendingPrompt !== null) {
                $prompt = $pendingPrompt;
                $pendingPrompt = null;
            } else {
            $io->writeln('');
            if ($this->hintsEnabled) {
                $projectTag = $this->activeProjectSlug !== null
                    ? sprintf(' <fg=magenta>[%s]</>', $this->activeProjectSlug)
                    : '';
                if ($this->activeRole !== 'orchestrator') {
                    $io->writeln(sprintf(' <fg=cyan>You</> <fg=gray>(%s)</>%s:', $this->activeRole, $projectTag));
                } else {
                    $io->writeln(sprintf(' <fg=cyan>You</>%s:', $projectTag));
                }
            }

            $initialNotifications = $this->getIdleNotifications($notificationStore, $notificationLimit);
            $initialActionableSummary = $this->getActionableSummary($notificationStore);
            if ($initialNotifications !== []) {
                $this->renderIdleNotifications($io, $notificationPresenter, $initialNotifications);
            }
            $this->renderActionableSummary($io, $notificationPresenter, $initialActionableSummary);

            $readlinePrompt = $this->buildReadlinePrompt($notificationPresenter, $notificationStore);

            // Read input using readline's callback API for non-blocking signal handling.
            $line = null;
            $lineReady = false;
            $ctrlCPressed = false;

            $hasReadline = function_exists('readline_callback_handler_install');

            if ($hasReadline) {
                $readlineCallback = static function (?string $input) use (&$line, &$lineReady): void {
                    $line = $input;
                    $lineReady = true;
                };
                $this->installReadlineHandler($readlinePrompt, $readlineCallback);

                if ($hasSignals) {
                    pcntl_signal(SIGINT, static function () use (&$ctrlCPressed, &$lineReady): void {
                        $ctrlCPressed = true;
                        $lineReady = true;
                    });
                }

                $typingStarted = false;
                $lastNotificationSignature = $this->notificationSignature($initialNotifications);
                $lastActionableSummarySignature = $this->actionableSummarySignature($initialActionableSummary);
                $nextNotificationPollAt = microtime(true) + self::NOTIFICATION_IDLE_POLL_INTERVAL_SECONDS;
                $lastNotificationLineCount = 0;

                // Count initial notification lines so the first update can clear them
                if ($initialNotifications !== []) {
                    $lastNotificationLineCount += count($notificationPresenter->formatIdleNotifications($initialNotifications));
                }
                if ($initialActionableSummary['pending'] > 0 || $initialActionableSummary['claimed'] > 0) {
                    $lastNotificationLineCount++;
                }

                while (!$lineReady) {
                    $read = [STDIN];
                    $write = $except = [];
                    /** @var int|false $ready */
                    $ready = @stream_select($read, $write, $except, 0, 200000);

                    if ($ready > 0) {
                        $typingStarted = true;
                        $this->readReadlineChar();
                        continue;
                    }

                    if (
                        $ready === 0
                        && !$typingStarted
                        && $notificationStore !== null
                        && microtime(true) >= $nextNotificationPollAt
                    ) {
                        $nextNotificationPollAt = microtime(true) + self::NOTIFICATION_IDLE_POLL_INTERVAL_SECONDS;
                        $notifications = $this->getIdleNotifications($notificationStore, $notificationLimit);
                        $actionableSummary = $this->getActionableSummary($notificationStore);
                        $signature = $this->notificationSignature($notifications);
                        $actionableSignature = $this->actionableSummarySignature($actionableSummary);

                        if ($signature !== $lastNotificationSignature || $actionableSignature !== $lastActionableSummarySignature) {
                            $this->removeReadlineHandler();

                            // Clear the current readline prompt line to prevent stacking `›` symbols
                            $io->write("\r\033[K");

                            // Erase the previous notification block so it doesn't stack
                            if ($lastNotificationLineCount > 0) {
                                // Move up N lines, clearing each one
                                for ($i = 0; $i < $lastNotificationLineCount; $i++) {
                                    $io->write("\033[A\033[K");
                                }
                                $io->write("\r");
                            }

                            $newLineCount = 0;

                            if ($notifications !== []) {
                                $formattedLines = $notificationPresenter->formatIdleNotifications($notifications);
                                $newLineCount += count($formattedLines);
                                $this->renderIdleNotifications($io, $notificationPresenter, $notifications);
                            }

                            $actionableLine = $notificationPresenter->formatActionableSummary($actionableSummary['pending'], $actionableSummary['claimed']);
                            if ($actionableLine !== '') {
                                $newLineCount++;
                            }
                            $this->renderActionableSummary($io, $notificationPresenter, $actionableSummary);

                            $lastNotificationLineCount = $newLineCount;

                            $readlinePrompt = $this->buildReadlinePrompt($notificationPresenter, $notificationStore);
                            $this->installReadlineHandler($readlinePrompt, $readlineCallback);
                            $lastNotificationSignature = $signature;
                            $lastActionableSummarySignature = $actionableSignature;
                        }
                    }
                }

                $this->removeReadlineHandler();
            } else {
                $io->write($readlinePrompt);
                $raw = fgets(STDIN);
                if ($raw === false) {
                    $line = null;
                    $lineReady = true;
                } else {
                    $line = rtrim($raw, "\r\n");
                    $lineReady = true;
                }
            }

            // Ctrl+C at the prompt → graceful shutdown
            if ($ctrlCPressed) {
                if (getenv('COQUI_LAUNCHER') !== '1') {
                    $io->newLine();
                    $io->info('Shutting down Coqui.');
                }
                return 0;
            }

            // Ctrl+D (EOF) with STDIN closed — exit cleanly
            if ($line === null && feof(STDIN)) {
                return 0;
            }

            if ($line === null || trim($line) === '') {
                continue;
            }

            $prompt = trim($line);
            if (function_exists('readline_add_history')) {
                readline_add_history($prompt);
            }
            } // end else (user input)

            // Handle slash commands
            if (str_starts_with($prompt, '/')) {
                $routeResult = $router->route($prompt, $this->activeRole, $this->sessionId, $io, $this->activeProjectId);

                if (!$routeResult->shouldContinue) {
                    return $routeResult->exitCode ?? Command::SUCCESS;
                }

                // Apply state changes from the route result
                if ($routeResult->newActiveRole !== null) {
                    $this->activeRole = $routeResult->newActiveRole;
                }
                if ($routeResult->newSessionId !== null) {
                    $this->sessionId = $routeResult->newSessionId;
                    $sessionHandler->saveSessionFile($this->sessionId);
                    $tabCompletion->setSessionId($this->sessionId);
                    // Restore project context for resumed session
                    $this->restoreActiveProject();
                }
                if ($routeResult->newActiveProjectId !== null) {
                    $this->applyProjectChange($routeResult->newActiveProjectId);
                }

                continue;
            }

            // Execute agent turn
            $turnResult = $turnExecutor->execute(
                $prompt,
                $this->sessionId,
                $this->activeRole,
                $io,
                $this->autoApprove,
                $hasSignals,
                $shutdownStty,
            );
            $shutdownGuard($shutdownStty);
            $this->restoreActiveProject();

            if ($turnResult->shouldExit()) {
                return $turnResult->exitCode ?? Command::SUCCESS;
            }

            // Sprint continuation
            if ($turnResult->continuationPrompt !== null) {
                $prompt = $turnResult->continuationPrompt;
                // Re-enter the agent turn with the continuation prompt
                $turnResult = $turnExecutor->execute(
                    $prompt,
                    $this->sessionId,
                    $this->activeRole,
                    $io,
                    $this->autoApprove,
                    $hasSignals,
                    $shutdownStty,
                );
                $shutdownGuard($shutdownStty);
                $this->restoreActiveProject();

                if ($turnResult->shouldExit()) {
                    return $turnResult->exitCode ?? Command::SUCCESS;
                }
            }
        }
    }

    /**
     * Run a single prompt without the REPL (--no-terminal mode).
     */
    private function runHeadless(InputInterface $input, OutputInterface $output): int
    {
        $prompt = $input->getOption('prompt');
        if (!is_string($prompt) || trim($prompt) === '') {
            // Try reading from stdin
            if (!stream_isatty(STDIN)) {
                $stdin = stream_get_contents(STDIN);
                if (is_string($stdin) && trim($stdin) !== '') {
                    $prompt = trim($stdin);
                }
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
        $this->agentRunner = AgentRunnerFactory::create(
            boot: $this->boot,
            projectRoot: $this->workDir,
            storage: $this->storage,
            observer: new NullObserver(),
            unsafeMode: $this->unsafeMode,
            includeConfigManager: true,
            includeLoadingData: true,
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
        $policyFactory = new ExecutionPolicyFactory(
            $this->boot->blacklist(),
            $this->storage,
            $this->boot->discovery(),
        );
        $executionPolicy = $policyFactory->build($this->sessionId, $this->autoApprove);
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
     * Restore active project state from the current session's database record.
     */
    private function restoreActiveProject(): void
    {
        $projectId = $this->storage->getActiveProjectId($this->sessionId);
        if ($projectId === null) {
            $this->activeProjectId = null;
            $this->activeProjectSlug = null;
            return;
        }

        $projectStore = $this->boot->projectStore();
        if ($projectStore === null) {
            return;
        }

        $project = $projectStore->getProject($projectId);
        if ($project === null) {
            // Project was deleted — clear stale reference
            $this->storage->setActiveProject($this->sessionId, null);
            $this->activeProjectId = null;
            $this->activeProjectSlug = null;
            return;
        }

        $this->activeProjectId = $project['id'];
        $this->activeProjectSlug = $project['slug'];
    }

    private function buildReadlinePrompt(NotificationPresenter $presenter, ?NotificationStore $notificationStore): string
    {
        if ($notificationStore === null) {
            return self::DEFAULT_READLINE_PROMPT;
        }

        try {
            $badge = $presenter->formatBadge($notificationStore->countUnread($this->sessionId));
        } catch (\Throwable) {
            return self::DEFAULT_READLINE_PROMPT;
        }

        return ' ›' . $badge . ' ';
    }

    private function installReadlineHandler(string $prompt, callable $callback): void
    {
        if (!function_exists('readline_callback_handler_install')) {
            return;
        }

        readline_callback_handler_install($prompt, $callback);
    }

    private function readReadlineChar(): void
    {
        if (!function_exists('readline_callback_read_char')) {
            return;
        }

        readline_callback_read_char();
    }

    private function removeReadlineHandler(): void
    {
        if (!function_exists('readline_callback_handler_remove')) {
            return;
        }

        readline_callback_handler_remove();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getIdleNotifications(?NotificationStore $notificationStore, int $limit): array
    {
        if ($notificationStore === null) {
            return [];
        }

        try {
            return $notificationStore->getUnreadInformational($this->sessionId, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{pending: int, claimed: int}
     */
    private function getActionableSummary(?NotificationStore $notificationStore): array
    {
        if ($notificationStore === null) {
            return ['pending' => 0, 'claimed' => 0];
        }

        try {
            return $notificationStore->getOpenActionableSummary($this->sessionId);
        } catch (\Throwable) {
            return ['pending' => 0, 'claimed' => 0];
        }
    }

    /**
     * @param list<array<string, mixed>> $notifications
     */
    private function renderIdleNotifications(SymfonyStyle $io, NotificationPresenter $presenter, array $notifications): void
    {
        foreach ($presenter->formatIdleNotifications($notifications) as $line) {
            $io->writeln($line);
        }
    }

    /**
     * @param array{pending: int, claimed: int} $summary
     */
    private function renderActionableSummary(SymfonyStyle $io, NotificationPresenter $presenter, array $summary): void
    {
        $line = $presenter->formatActionableSummary($summary['pending'], $summary['claimed']);
        if ($line !== '') {
            $io->writeln($line);
        }
    }

    /**
     * @param list<array<string, mixed>> $notifications
     */
    private function notificationSignature(array $notifications): string
    {
        if ($notifications === []) {
            return '';
        }

        return implode(
            '|',
            array_map(
                static fn(array $notification): string => implode(':', [
                    (string) ($notification['id'] ?? ''),
                    (string) ($notification['priority'] ?? ''),
                    (string) ($notification['created_at'] ?? ''),
                ]),
                $notifications,
            ),
        );
    }

    /**
     * @param array{pending: int, claimed: int} $summary
     */
    private function actionableSummarySignature(array $summary): string
    {
        return sprintf('%d:%d', $summary['pending'], $summary['claimed']);
    }

    /**
     * Apply a project state change from a RouteResult.
     *
     * @param string $projectId Project ID, or empty string to clear.
     */
    private function applyProjectChange(string $projectId): void
    {
        if ($projectId === '') {
            $this->activeProjectId = null;
            $this->activeProjectSlug = null;
            return;
        }

        $projectStore = $this->boot->projectStore();
        if ($projectStore === null) {
            return;
        }

        $project = $projectStore->getProject($projectId);
        if ($project !== null) {
            $this->activeProjectId = $project['id'];
            $this->activeProjectSlug = $project['slug'];
        }
    }
}
