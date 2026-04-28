<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Contract\ToolkitReplContext;
use CoquiBot\Coqui\Renderer\MarkdownRenderer;
use CoquiBot\Coqui\Renderer\PromptUsageBar;
use CoquiBot\Coqui\Repl\Handler\BackstoryHandler;
use CoquiBot\Coqui\Repl\Handler\BudgetHandler;
use CoquiBot\Coqui\Repl\Handler\ChannelHandler;
use CoquiBot\Coqui\Repl\Handler\ConfigHandler;
use CoquiBot\Coqui\Repl\Handler\ConversationHandler;
use CoquiBot\Coqui\Repl\Handler\EvaluationHandler;
use CoquiBot\Coqui\Repl\Handler\GroupHandler;
use CoquiBot\Coqui\Repl\Handler\LoopHandler;
use CoquiBot\Coqui\Repl\Handler\ProfileHandler;
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
use CoquiBot\Coqui\Support\ImagePreviewService;
use CoquiBot\Coqui\Support\ImagePreviewState;
use CoquiBot\Coqui\Support\PromptInspectionService;
use CoquiBot\Coqui\Support\ToolkitDatabaseFactory;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Repl\ToolkitScreenHost;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Routes slash commands to the appropriate handler.
 *
 * Mutable state ($activeRole, $sessionId) belongs to the caller (RunCommand).
 * Handlers that change state return values that RunCommand uses to update itself.
 */
final class SlashCommandRouter
{
    /** @var array<string, ToolkitCommandHandler> Command name => handler */
    private readonly array $toolkitHandlers;
    private readonly ToolkitCommandHelpRenderer $toolkitHelpRenderer;

    /**
     * @param list<ToolkitCommandHandler> $toolkitCommandHandlers Handlers discovered from toolkits
     */
    public function __construct(
        private readonly SessionHandler $session,
        private readonly TaskHandler $task,
        private readonly TodoHandler $todo,
        private readonly ScheduleHandler $schedule,
        private readonly BudgetHandler $budget,
        private readonly ChannelHandler $channel,
        private readonly QualityHandler $quality,
        private readonly ProjectHandler $project,
        private readonly RoleHandler $role,
        private readonly GroupHandler $group,
        private readonly ProfileHandler $profile,
        private readonly ToolkitVisibilityHandler $toolkitVisibility,
        private readonly SpaceHandler $space,
        private readonly ConfigHandler $config,
        private readonly ConversationHandler $conversation,
        private readonly WebhookHandler $webhook,
        private readonly EvaluationHandler $evaluation,
        private readonly LoopHandler $loop,
        private readonly BackstoryHandler $backstory,
        private readonly AgentRunner $agentRunner,
        private readonly PromptInspectionService $promptInspection,
        private readonly OutputInterface $output,
        private readonly string $workspacePath,
        private readonly ?ImagePreviewService $imagePreviewService,
        private readonly \Closure $onHintsToggle,
        private readonly \Closure $onMultilineToggle,
        array $toolkitCommandHandlers = [],
    ) {
        $handlers = [];
        foreach ($toolkitCommandHandlers as $handler) {
            $handlers[$handler->commandName()] = $handler;
        }
        $this->toolkitHandlers = $handlers;
        $this->toolkitHelpRenderer = new ToolkitCommandHelpRenderer();
    }

    /**
     * Route a slash command.
     *
     * @param string $command Full command string (e.g. "/role coder")
     * @param string $activeRole Current active role (may be updated by returned RouteResult)
     * @param string $sessionId Current session ID (may be updated by returned RouteResult)
     * @param ?string $activeProjectId Current active project ID (may be updated by returned RouteResult)
     *
     * @return RouteResult Result containing exit code or state changes.
     */
    public function route(string $command, string $activeRole, string $sessionId, SymfonyStyle $io, ?string $activeProjectId = null, ?string $activeProfile = null): RouteResult
    {
        $parts = explode(' ', $command, 2);
        $cmd = $parts[0];
        $arg = $parts[1] ?? '';

        $result = match ($cmd) {
            '/quit', '/exit', '/q' => $this->handleQuit($io),
            '/restart' => RouteResult::exit(ConfigHandler::RESTART_EXIT_CODE),
            '/new' => $this->handleNew($io, $sessionId, $activeProfile),
            '/history' => $this->handleHistory($io, $sessionId),
            '/sessions' => $this->handleSessions($io, $sessionId),
            '/resume' => $this->handleResume($io, $arg),
            '/model' => $this->handleModel($io, $arg),
            '/config' => $this->handleConfig($io, $arg),
            '/tasks' => $this->handleTasks($io, $arg),
            '/todos' => $this->handleTodos($io, $arg, $sessionId),
            '/projects' => $this->handleProjects($io, $arg, $sessionId, $activeProjectId),
            '/sprints' => $this->handleSprints($io, $arg, $sessionId),
            '/task' => $this->handleTask($io, $arg),
            '/task-cancel' => $this->handleTaskCancel($io, $arg),
            '/update' => $this->handleUpdate($io),
            '/toolkits' => $this->handleToolkits($io, $arg),
            '/budget' => $this->handleBudget($io, $arg, $activeRole, $activeProfile, $sessionId),
            '/channels' => $this->handleChannels($io, $arg),
            '/prompt' => $this->handlePrompt($io, $arg, $activeRole, $activeProfile, $sessionId),
            '/summarize' => $this->handleSummarize($io, $arg, $sessionId),
            '/role' => $this->handleRole($io, $arg, $activeRole, $sessionId, $activeProfile),
            '/roles' => $this->handleRoles($io, $arg, $activeRole),
            '/group' => $this->handleGroup($io, $arg, $sessionId),
            '/profile' => $this->handleProfile($io, $arg, $activeRole, $activeProfile),
            '/profiles' => $this->handleProfiles($io, $activeProfile),
            '/backstory' => $this->handleBackstory($io, $arg, $activeProfile),
            '/space' => $this->handleSpace($io, $arg),
            '/schedules' => $this->handleSchedules($io, $arg),
            '/quality' => $this->handleQuality($io),
            '/webhooks' => $this->handleWebhooks($io, $arg),
            '/evaluations' => $this->handleEvaluations($io, $arg),
            '/loops' => $this->handleLoops($io, $arg, $sessionId),
            '/multiline' => $this->handleMultiline($io, $arg),
            '/hints' => $this->handleHints($io),
            '/help' => $this->handleHelp($io),
            default => $this->dispatchToolkitOrUnknown($io, $cmd, $arg, $activeProfile, $sessionId),
        };

        return $result;
    }

    private function handleQuit(SymfonyStyle $io): RouteResult
    {
        if (getenv('COQUI_LAUNCHER') !== '1') {
            $io->newLine();
            $io->info('Shutting down Coqui.');
        }
        return RouteResult::exit(Command::SUCCESS);
    }

    private function handleNew(SymfonyStyle $io, string $sessionId, ?string $activeProfile): RouteResult
    {
        $newSessionId = $this->session->startFreshSession($io, $sessionId, $activeProfile);
        if ($newSessionId === null) {
            return RouteResult::continue();
        }

        $io->success('New session started: ' . $newSessionId);

        return RouteResult::stateChange(newSessionId: $newSessionId, newActiveRole: SystemRole::Orchestrator->value);
    }

    private function handleHistory(SymfonyStyle $io, string $sessionId): RouteResult
    {
        $this->session->showHistory($io, $sessionId);
        return RouteResult::continue();
    }

    private function handleSessions(SymfonyStyle $io, string $sessionId): RouteResult
    {
        $this->session->listSessions($io, $sessionId);
        return RouteResult::continue();
    }

    private function handleResume(SymfonyStyle $io, string $arg): RouteResult
    {
        $newSessionId = $this->session->resume($io, $arg);
        if ($newSessionId !== null) {
            return RouteResult::stateChange(newSessionId: $newSessionId);
        }
        return RouteResult::continue();
    }

    private function handleModel(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->session->showModelInfo($io, $arg);
        return RouteResult::continue();
    }

    private function handleConfig(SymfonyStyle $io, string $arg): RouteResult
    {
        $result = $this->config->handle($io, $arg);
        if (is_int($result)) {
            return RouteResult::exit($result);
        }
        return RouteResult::continue();
    }

    private function handleTasks(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->task->listTasks($io, $arg);
        return RouteResult::continue();
    }

    private function handleTodos(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        $this->todo->handle($io, $arg, $sessionId);
        return RouteResult::continue();
    }

    private function handleProjects(SymfonyStyle $io, string $arg, string $sessionId, ?string $activeProjectId): RouteResult
    {
        [$projectId, $projectSlug] = $this->project->handleProjects($io, $arg, $sessionId, $activeProjectId);

        if ($projectId === '__clear__') {
            return RouteResult::stateChange(newActiveProjectId: '');
        }

        if ($projectId !== null) {
            return RouteResult::stateChange(newActiveProjectId: $projectId);
        }

        return RouteResult::continue();
    }

    private function handleSprints(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        $this->project->handleSprints($io, $arg, $sessionId);
        return RouteResult::continue();
    }

    private function handleTask(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->task->taskStatus($io, $arg);
        return RouteResult::continue();
    }

    private function handleTaskCancel(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->task->taskCancel($io, $arg);
        return RouteResult::continue();
    }

    private function handleUpdate(SymfonyStyle $io): RouteResult
    {
        $result = $this->config->runUpdate($io);
        if (is_int($result)) {
            return RouteResult::exit($result);
        }
        return RouteResult::continue();
    }

    private function handleToolkits(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->toolkitVisibility->handle($io, $arg);
        return RouteResult::continue();
    }

    private function handleChannels(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->channel->handle($io, $arg);
        return RouteResult::continue();
    }

    private function handlePrompt(SymfonyStyle $io, string $arg, string $activeRole, ?string $activeProfile, string $sessionId): RouteResult
    {
        $role = $activeRole !== SystemRole::Orchestrator->value ? $activeRole : null;

        if (trim($arg) === 'export') {
            $filePath = $this->agentRunner->exportPromptToFile($role, $activeProfile, $sessionId);
            $io->success('Prompt exported to: ' . $filePath);
            return RouteResult::continue();
        }

        $preview = $this->promptInspection->inspect($role, $activeProfile, $sessionId);
        $io->section('System Prompt');
        $this->renderMarkdown($io, $preview['prompt']);
        $io->newLine();
        $io->text([
            '<fg=gray>Tool count:</> ' . $preview['tool_count'],
            '<fg=gray>Toolkit count:</> ' . $preview['toolkit_count'],
            '<fg=gray>Prompt tokens:</> ' . number_format($preview['prompt_tokens']),
            '<fg=gray>Tool schema tokens:</> ' . number_format($preview['tool_tokens']),
            '<fg=gray>Estimated total:</> ' . number_format($preview['total_tokens']),
        ]);

        // Show backstory summary if generated from source files
        if ($activeProfile !== null) {
            $summary = $this->backstory->getManifestSummary($activeProfile);
            if ($summary !== null) {
                $issueSuffix = '';
                $issueCount = $summary['failed_files'] + $summary['unsupported_files'];
                if ($issueCount > 0) {
                    $issueSuffix = sprintf(', %d issue(s)', $issueCount);
                }

                $io->text(sprintf(
                    '<fg=gray>Backstory:</> %d file(s), ~%s tokens%s (use /backstory for details)',
                    $summary['total_files'],
                    number_format($summary['total_tokens']),
                    $issueSuffix,
                ));
            }
        }

        $this->renderPromptSourceTables($io, $preview['prompt_sources']);

        $io->newLine();
        PromptUsageBar::renderSectionBreakdown($io, $preview['budget']);
        $io->newLine();
        PromptUsageBar::renderContextImpact($io, $preview['budget']);
        return RouteResult::continue();
    }

    /**
     * @param array<string, mixed> $promptSources
     */
    private function renderPromptSourceTables(SymfonyStyle $io, array $promptSources): void
    {
        $fileRows = [];
        foreach (array_slice($promptSources['files'] ?? [], 0, 8) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $fileRows[] = [
                ($entry['scope'] ?? 'unknown') . ':' . ($entry['path'] ?? ''),
                number_format((int) ($entry['tokens'] ?? 0)),
                (string) ($entry['section_count'] ?? 0),
                BackstoryHandler::formatNullableTimestamp(is_string($entry['last_modified_at'] ?? null) ? $entry['last_modified_at'] : null),
            ];
        }

        $folderRows = [];
        foreach (array_slice($promptSources['folders'] ?? [], 0, 6) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $path = (string) ($entry['path'] ?? '');
            $folderRows[] = [
                ($entry['scope'] ?? 'unknown') . ':' . ($path !== '' ? $path : '.'),
                number_format((int) ($entry['tokens'] ?? 0)),
                (string) ($entry['file_count'] ?? 0),
                BackstoryHandler::formatNullableTimestamp(is_string($entry['last_modified_at'] ?? null) ? $entry['last_modified_at'] : null),
            ];
        }

        $syntheticRows = [];
        foreach (array_slice($promptSources['synthetic'] ?? [], 0, 6) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $syntheticRows[] = [
                (string) ($entry['label'] ?? 'Generated'),
                number_format((int) ($entry['tokens'] ?? 0)),
                (string) ($entry['section_count'] ?? 0),
            ];
        }

        if ($fileRows === [] && $folderRows === [] && $syntheticRows === []) {
            return;
        }

        $io->newLine();
        if ($folderRows !== []) {
            $io->table(['Folder', 'Tokens', 'Files', 'Modified'], $folderRows);
        }

        if ($fileRows !== []) {
            $io->newLine();
            $io->table(['Prompt File', 'Tokens', 'Sections', 'Modified'], $fileRows);
        }

        if ($syntheticRows !== []) {
            $io->newLine();
            $io->table(['Generated Source', 'Tokens', 'Sections'], $syntheticRows);
        }
    }

    private function handleBudget(SymfonyStyle $io, string $arg, string $activeRole, ?string $activeProfile, string $sessionId): RouteResult
    {
        $requestedRole = trim($arg);
        $role = $requestedRole !== ''
            ? $requestedRole
            : ($activeRole !== SystemRole::Orchestrator->value ? $activeRole : null);

        $this->budget->handle($io, $role, $activeProfile, $sessionId);

        return RouteResult::continue();
    }

    private function handleSummarize(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        $this->conversation->handleSummarize($io, $arg, $sessionId);
        return RouteResult::continue();
    }

    private function handleRole(SymfonyStyle $io, string $arg, string $activeRole, string $sessionId, ?string $activeProfile): RouteResult
    {
        $newRole = $this->role->handleRole($io, $arg, $activeRole, $sessionId, $activeProfile);
        if ($newRole !== null) {
            return RouteResult::stateChange(newActiveRole: $newRole);
        }
        return RouteResult::continue();
    }

    private function handleRoles(SymfonyStyle $io, string $arg, string $activeRole): RouteResult
    {
        $this->role->handleRoles($io, $arg, $activeRole);
        return RouteResult::continue();
    }

    private function handleGroup(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        return $this->group->handle($io, $arg, $sessionId);
    }

    private function handleProfile(SymfonyStyle $io, string $arg, string $activeRole, ?string $activeProfile): RouteResult
    {
        return $this->profile->handleProfile($io, $arg, $activeRole, $activeProfile);
    }

    private function handleProfiles(SymfonyStyle $io, ?string $activeProfile): RouteResult
    {
        return $this->profile->handleProfiles($io, $activeProfile);
    }

    private function handleBackstory(SymfonyStyle $io, string $arg, ?string $activeProfile): RouteResult
    {
        return $this->backstory->handle($io, $arg, $activeProfile);
    }

    private function handleSpace(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->space->handle($io, $arg);
        return RouteResult::continue();
    }

    private function handleSchedules(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->schedule->handle($io, $arg);
        return RouteResult::continue();
    }

    private function handleQuality(SymfonyStyle $io): RouteResult
    {
        $this->quality->handle($io);
        return RouteResult::continue();
    }

    private function handleWebhooks(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->webhook->handle($io, $arg);
        return RouteResult::continue();
    }

    private function handleEvaluations(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->evaluation->handle($io, $arg);
        return RouteResult::continue();
    }

    private function handleLoops(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        $this->loop->handle($io, $arg, $sessionId);
        return RouteResult::continue();
    }

    private function handleMultiline(SymfonyStyle $io, string $arg): RouteResult
    {
        $arg = strtolower(trim($arg));

        return match ($arg) {
            'on' => $this->applyMultilineMode($io, true),
            'off' => $this->applyMultilineMode($io, false),
            '' => $this->applyMultilineMode($io, null), // toggle
            default => $this->showMultilineHelp($io),
        };
    }

    private function applyMultilineMode(SymfonyStyle $io, ?bool $enable): RouteResult
    {
        ($this->onMultilineToggle)($enable);
        return RouteResult::continue();
    }

    private function showMultilineHelp(SymfonyStyle $io): RouteResult
    {
        $io->text([
            '<fg=cyan>Multiline mode</> — compose multi-line prompts before sending.',
            '',
            '  /multiline        Toggle multiline mode',
            '  /multiline on     Enable multiline mode',
            '  /multiline off    Disable multiline mode',
            '',
            'In multiline mode, single Enter adds a new line.',
            'Press Enter twice on an empty line (or Ctrl+D) to submit.',
            'Pasted content is automatically buffered when bracketed paste is available.',
        ]);
        return RouteResult::continue();
    }

    private function handleHints(SymfonyStyle $io): RouteResult
    {
        ($this->onHintsToggle)();
        return RouteResult::continue();
    }

    private function handleHelp(SymfonyStyle $io): RouteResult
    {
        foreach (ReplCommandCatalog::helpSections() as $section => $rows) {
            $io->section($section);
            $io->table(['Command', 'Description'], $rows);
            $io->newLine();
        }

        $io->text('<fg=gray>Advanced automation commands remain available, but they are intended for operator workflows, monitoring, or agent-assisted orchestration rather than routine chat interaction.</>');
        return RouteResult::continue();
    }

    private function renderMarkdown(SymfonyStyle $io, string $markdown): void
    {
        $io->write(MarkdownRenderer::render(
            $markdown,
            $this->imagePreviewService,
            new ImagePreviewState(),
        ));
    }

    /**
     * Dispatch to a toolkit-provided command handler, or show unknown command error.
     */
    private function dispatchToolkitOrUnknown(SymfonyStyle $io, string $cmd, string $arg, ?string $activeProfile, string $sessionId): RouteResult
    {
        // Strip leading slash for handler lookup
        $name = ltrim($cmd, '/');

        if (isset($this->toolkitHandlers[$name])) {
            $normalizedArg = strtolower(trim($arg));
            if ($normalizedArg === '' || $normalizedArg === 'help') {
                $this->toolkitHelpRenderer->render($io, $this->toolkitHandlers[$name]);

                return RouteResult::continue();
            }

            $context = new ToolkitReplContext(
                io: $io,
                prompt: new InterruptiblePrompt($io),
                workspacePath: $this->workspacePath,
                activeProfile: $activeProfile,
                sessionId: $sessionId,
                output: $this->output,
                databaseFactory: new ToolkitDatabaseFactory($this->workspacePath),
                screenHost: new ToolkitScreenHost($this->output),
            );
            $this->toolkitHandlers[$name]->handle($context, $arg);

            return RouteResult::continue();
        }

        $io->error("Unknown command: {$cmd}. Type /help for available commands.");

        return RouteResult::continue();
    }
}
