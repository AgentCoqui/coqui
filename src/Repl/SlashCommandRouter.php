<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Renderer\MarkdownRenderer;
use CoquiBot\Coqui\Repl\Handler\ConfigHandler;
use CoquiBot\Coqui\Repl\Handler\ConversationHandler;
use CoquiBot\Coqui\Repl\Handler\EvaluationHandler;
use CoquiBot\Coqui\Repl\Handler\ProjectHandler;
use CoquiBot\Coqui\Repl\Handler\RoleHandler;
use CoquiBot\Coqui\Repl\Handler\ScheduleHandler;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Repl\Handler\SpaceHandler;
use CoquiBot\Coqui\Repl\Handler\TaskHandler;
use CoquiBot\Coqui\Repl\Handler\TodoHandler;
use CoquiBot\Coqui\Repl\Handler\ToolkitVisibilityHandler;
use CoquiBot\Coqui\Repl\Handler\WebhookHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Routes slash commands to the appropriate handler.
 *
 * Mutable state ($activeRole, $sessionId) belongs to the caller (RunCommand).
 * Handlers that change state return values that RunCommand uses to update itself.
 */
final class SlashCommandRouter
{
    public function __construct(
        private readonly SessionHandler $session,
        private readonly TaskHandler $task,
        private readonly TodoHandler $todo,
        private readonly ScheduleHandler $schedule,
        private readonly ProjectHandler $project,
        private readonly RoleHandler $role,
        private readonly ToolkitVisibilityHandler $toolkitVisibility,
        private readonly SpaceHandler $space,
        private readonly ConfigHandler $config,
        private readonly ConversationHandler $conversation,
        private readonly WebhookHandler $webhook,
        private readonly EvaluationHandler $evaluation,
        private readonly AgentRunner $agentRunner,
        private readonly \Closure $onHintsToggle,
    ) {}

    /**
     * Route a slash command.
     *
     * @param string $command Full command string (e.g. "/role coder")
     * @param string $activeRole Current active role (may be updated by returned RouteResult)
     * @param string $sessionId Current session ID (may be updated by returned RouteResult)
     *
     * @return RouteResult Result containing exit code or state changes.
     */
    public function route(string $command, string $activeRole, string $sessionId, SymfonyStyle $io): RouteResult
    {
        $parts = explode(' ', $command, 2);
        $cmd = $parts[0];
        $arg = $parts[1] ?? '';

        $result = match ($cmd) {
            '/quit', '/exit', '/q' => $this->handleQuit($io),
            '/restart' => RouteResult::exit(ConfigHandler::RESTART_EXIT_CODE),
            '/new' => $this->handleNew($io),
            '/history' => $this->handleHistory($io, $sessionId),
            '/sessions' => $this->handleSessions($io, $sessionId),
            '/resume' => $this->handleResume($io, $arg),
            '/model' => $this->handleModel($io, $arg),
            '/config' => $this->handleConfig($io, $arg),
            '/tasks' => $this->handleTasks($io, $arg),
            '/todos' => $this->handleTodos($io, $arg, $sessionId),
            '/projects' => $this->handleProjects($io, $arg),
            '/sprints' => $this->handleSprints($io, $arg, $sessionId),
            '/task' => $this->handleTask($io, $arg),
            '/task-cancel' => $this->handleTaskCancel($io, $arg),
            '/update' => $this->handleUpdate($io),
            '/toolkits' => $this->handleToolkits($io, $arg),
            '/prompt' => $this->handlePrompt($io, $activeRole),
            '/summarize' => $this->handleSummarize($io, $arg, $sessionId),
            '/role' => $this->handleRole($io, $arg, $activeRole, $sessionId),
            '/roles' => $this->handleRoles($io, $arg, $activeRole),
            '/space' => $this->handleSpace($io, $arg),
            '/schedules' => $this->handleSchedules($io, $arg),
            '/webhooks' => $this->handleWebhooks($io, $arg),
            '/evaluations' => $this->handleEvaluations($io, $arg),
            '/hints' => $this->handleHints($io),
            '/help' => $this->handleHelp($io),
            default => $this->handleUnknown($io, $cmd),
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

    private function handleNew(SymfonyStyle $io): RouteResult
    {
        $sessionId = $this->session->createNewSession();
        $io->success('New session started: ' . $sessionId);
        return RouteResult::stateChange(newSessionId: $sessionId, newActiveRole: 'orchestrator');
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

    private function handleProjects(SymfonyStyle $io, string $arg): RouteResult
    {
        $this->project->handleProjects($io, $arg);
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

    private function handlePrompt(SymfonyStyle $io, string $activeRole): RouteResult
    {
        $role = $activeRole !== 'orchestrator' ? $activeRole : null;
        $preview = $this->agentRunner->buildPromptPreview($role);
        $io->section('System Prompt');
        $io->write(MarkdownRenderer::render($preview['prompt']));
        $io->newLine();
        $io->text([
            '<fg=gray>Tool count:</> ' . $preview['tool_count'],
            '<fg=gray>Toolkit count:</> ' . $preview['toolkit_count'],
            '<fg=gray>Prompt tokens:</> ' . number_format($preview['prompt_tokens']),
            '<fg=gray>Tool schema tokens:</> ' . number_format($preview['tool_tokens']),
            '<fg=gray>Estimated total:</> ' . number_format($preview['total_tokens']),
        ]);
        return RouteResult::continue();
    }

    private function handleSummarize(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        $this->conversation->handleSummarize($io, $arg, $sessionId);
        return RouteResult::continue();
    }

    private function handleRole(SymfonyStyle $io, string $arg, string $activeRole, string $sessionId): RouteResult
    {
        $newRole = $this->role->handleRole($io, $arg, $activeRole, $sessionId);
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

    private function handleHints(SymfonyStyle $io): RouteResult
    {
        ($this->onHintsToggle)();
        return RouteResult::continue();
    }

    private function handleHelp(SymfonyStyle $io): RouteResult
    {
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
                ['/todos [status]', 'Show session todos (optionally filter by pending/in_progress/completed/cancelled)'],
                ['/projects [status]', 'List projects (optionally filter by active/completed/archived)'],
                ['/sprints [project_slug]', 'List sprints for a project (all projects if no slug given)'],
                ['/toolkits [enable|stub|disable <pkg|tool:name>]', 'Manage toolkit visibility'],
                ['/prompt', 'Show the full system prompt sent to the LLM'],
                ['/role [name]', 'Switch active role (e.g. /role coder). No argument shows current role'],
                ['/role edit <name>', 'Open a role file in your editor'],
                ['/roles', 'List all roles with visibility and update status'],
                ['/roles update [name]', 'Apply pending built-in role updates'],
                ['/roles ignore <name>', 'Ignore future updates for a role'],
                ['/roles unignore <name>', 'Resume receiving updates for a role'],
                ['/space [search|install|remove|installed|skills|toolkits|update]', 'Coqui Space marketplace'],
                ['/schedules', 'List scheduled tasks with status and next run time'],
                ['/webhooks', 'List webhook subscriptions with status and trigger counts'],
                ['/evaluations', 'List session evaluation reports with grades and scores'],
                ['/summarize [recent N] [focus "topic"]', 'Summarize conversation history to save tokens'],
                ['/hints', 'Toggle command hints in the input area'],
                ['/update', 'Check for and apply dependency updates'],
                ['/restart', 'Restart Coqui (re-reads config, re-discovers toolkits)'],
                ['/quit', 'Exit Coqui'],
            ],
        );
        return RouteResult::continue();
    }

    private function handleUnknown(SymfonyStyle $io, string $cmd): RouteResult
    {
        $io->error("Unknown command: {$cmd}. Type /help for available commands.");
        return RouteResult::continue();
    }
}
