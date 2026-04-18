<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Canonical slash-command metadata shared by help output and tab completion.
 */
final class ReplCommandCatalog
{
    /** @var list<ReplCommandSpec>|null */
    private static ?array $commands = null;

    /**
     * @return list<ReplCommandSpec>
     */
    public static function all(): array
    {
        return self::$commands ??= [
            new ReplCommandSpec('/new', '/new', 'Start a new session.'),
            new ReplCommandSpec('/history', '/history', 'Show conversation history for the current session.'),
            new ReplCommandSpec('/sessions', '/sessions', 'List saved sessions.'),
            new ReplCommandSpec('/resume', '/resume <session-id>', 'Resume a specific session.'),
            new ReplCommandSpec('/model', '/model [role]', 'Show resolved model configuration, optionally for one role.'),
            new ReplCommandSpec('/config', '/config [show|edit]', 'Show config summary or launch the config wizard; edit can restart.', firstArguments: ['show', 'edit']),
            new ReplCommandSpec('/tasks', '/tasks [status]', 'List background tasks, optionally filtered by status.', firstArguments: ['all', 'pending', 'running', 'cancelling', 'completed', 'failed', 'cancelled']),
            new ReplCommandSpec('/task', '/task <task-id>', 'Show task status, metadata, and recent events.'),
            new ReplCommandSpec('/task-cancel', '/task-cancel <task-id>', 'Request cancellation for a pending or running task.'),
            new ReplCommandSpec('/todos', '/todos [status|action]', 'List todos or run delete|complete <id|all>, cancel <id>, or clear.', firstArguments: ['pending', 'in_progress', 'completed', 'cancelled', 'delete', 'complete', 'cancel', 'clear']),
            new ReplCommandSpec('/projects', '/projects [status|slug|clear]', 'List projects, filter by status, switch the active project, or clear it.', firstArguments: ['active', 'completed', 'archived', 'clear']),
            new ReplCommandSpec('/sprints', '/sprints [project-slug]', 'List sprints for active projects or one project.'),
            new ReplCommandSpec('/toolkits', '/toolkits [action]', 'List toolkits or run enable|stub|disable <pkg|tool:name> and promote|demote|auto <ToolkitClass>.', firstArguments: ['enable', 'stub', 'disable', 'promote', 'demote', 'auto']),
            new ReplCommandSpec('/schedules', '/schedules [action]', 'List schedules or run enable|disable|delete|trigger <name|id|all>.', firstArguments: ['enable', 'disable', 'delete', 'trigger']),
            new ReplCommandSpec('/loops', '/loops [filter|action]', 'List loops, filter by status, or run start <definition> <goal>, definitions, status|pause|resume|stop <id|all>.', firstArguments: ['start', 'definitions', 'defs', 'status', 'pause', 'resume', 'stop', 'running', 'paused', 'completed', 'failed', 'cancelled']),
            new ReplCommandSpec('/budget', '/budget [role]', 'Show prompt-budget and toolkit loading decisions.'),
            new ReplCommandSpec('/prompt', '/prompt [export]', 'Show the rendered system prompt or export it to the workspace.', firstArguments: ['export']),
            new ReplCommandSpec('/summarize', '/summarize [recent N|focus topic]', 'Summarize older conversation history to reclaim context.', firstArguments: ['recent', 'focus']),
            new ReplCommandSpec('/role', '/role [name|edit|reset]', 'Show or switch the active role, or edit a role file.', firstArguments: ['edit', 'reset']),
            new ReplCommandSpec('/roles', '/roles [action]', 'List roles or run update [name], ignore <name>, or unignore <name>.', firstArguments: ['list', 'update', 'ignore', 'unignore']),
            new ReplCommandSpec('/profile', '/profile [name|default|reset]', 'Show or switch the active profile, set a default with default <name|none>, or clear it.', firstArguments: ['default', 'reset', 'none']),
            new ReplCommandSpec('/profiles', '/profiles', 'List available profiles.'),
            new ReplCommandSpec('/backstory', '/backstory [generate|failed]', 'Show backstory status or regenerate and inspect failures for the active profile.', firstArguments: ['generate', 'failed']),
            new ReplCommandSpec('/space', '/space [action]', 'Show Coqui Space status or run search <query>, install <id>, remove <id>, installed, skills, toolkits, or update <id>.', firstArguments: ['status', 'search', 'install', 'remove', 'installed', 'skills', 'toolkits', 'update']),
            new ReplCommandSpec('/quality', '/quality', 'Show quality automation status.'),
            new ReplCommandSpec('/webhooks', '/webhooks', 'List webhook subscriptions.'),
            new ReplCommandSpec('/evaluations', '/evaluations [grade]', 'List evaluation reports, optionally filtered by A, B, C, D, or F.', firstArguments: ['A', 'B', 'C', 'D', 'F']),
            new ReplCommandSpec('/multiline', '/multiline [on|off]', 'Toggle multiline compose mode.', firstArguments: ['on', 'off']),
            new ReplCommandSpec('/hints', '/hints', 'Toggle command hints in the input area.'),
            new ReplCommandSpec('/help', '/help', 'Show the command reference.'),
            new ReplCommandSpec('/update', '/update', 'Check for dependency updates and apply them.'),
            new ReplCommandSpec('/restart', '/restart', 'Restart Coqui.'),
            new ReplCommandSpec('/quit', '/quit', 'Exit Coqui.', aliases: ['/exit', '/q']),
        ];
    }

    public static function find(string $command): ?ReplCommandSpec
    {
        foreach (self::all() as $spec) {
            if (in_array($command, $spec->allNames(), true)) {
                return $spec;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function topLevelCommands(): array
    {
        $commands = [];

        foreach (self::all() as $spec) {
            foreach ($spec->allNames() as $name) {
                $commands[] = $name;
            }
        }

        return $commands;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function helpRows(): array
    {
        return array_map(
            static fn(ReplCommandSpec $spec): array => [$spec->usage, $spec->helpDescription()],
            self::all(),
        );
    }
}