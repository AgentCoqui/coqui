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
            new ReplCommandSpec('/new', '/new', 'Start a new session.', section: 'Core Commands'),
            new ReplCommandSpec('/history', '/history', 'Show conversation history for the current session.', section: 'Core Commands'),
            new ReplCommandSpec('/sessions', '/sessions', 'List saved sessions.', section: 'Core Commands'),
            new ReplCommandSpec('/resume', '/resume <session-id>', 'Resume a specific session.', section: 'Core Commands'),
            new ReplCommandSpec('/role', '/role [name|edit|reset]', 'Show or switch the active role, or edit a role file.', firstArguments: ['edit', 'reset'], section: 'Core Commands'),
            new ReplCommandSpec('/roles', '/roles [action]', 'List roles or run update [name], ignore <name>, or unignore <name>.', firstArguments: ['list', 'update', 'ignore', 'unignore'], section: 'Core Commands'),
            new ReplCommandSpec('/profile', '/profile [name|default|reset]', 'Show or switch the active profile, set a default with default <name|none>, or clear it.', firstArguments: ['default', 'reset', 'none'], section: 'Core Commands'),
            new ReplCommandSpec('/profiles', '/profiles', 'List available profiles.', section: 'Core Commands'),
            new ReplCommandSpec('/model', '/model [role]', 'Show resolved model configuration, optionally for one role.', section: 'Context & Inspection'),
            new ReplCommandSpec('/budget', '/budget [role]', 'Show prompt-budget and toolkit loading decisions.', section: 'Context & Inspection'),
            new ReplCommandSpec('/prompt', '/prompt [export]', 'Show the rendered system prompt, source breakdowns, or export it to the workspace.', firstArguments: ['export'], section: 'Context & Inspection'),
            new ReplCommandSpec('/backstory', '/backstory [generate|failed]', 'Show backstory generation status and source breakdowns for the active profile.', firstArguments: ['generate', 'failed'], section: 'Context & Inspection'),
            new ReplCommandSpec('/image', '/image [action]', 'Generate and manage workspace images through the image toolkit. Actions: generate, list, search, get, tag, delete, config.', firstArguments: ['generate', 'list', 'search', 'get', 'tag', 'delete', 'config', 'help'], section: 'Context & Inspection'),
            new ReplCommandSpec('/summarize', '/summarize [recent N|focus topic]', 'Summarize older conversation history to reclaim context.', firstArguments: ['recent', 'focus'], section: 'Context & Inspection'),
            new ReplCommandSpec('/tasks', '/tasks [status]', 'List background tasks, optionally filtered by status.', firstArguments: ['all', 'pending', 'running', 'cancelling', 'completed', 'failed', 'cancelled'], section: 'Work Tracking'),
            new ReplCommandSpec('/task', '/task <task-id>', 'Show task status, metadata, and recent events.', section: 'Work Tracking'),
            new ReplCommandSpec('/todos', '/todos [status|action]', 'List todos or run delete|complete <id|all>, cancel <id>, or clear.', firstArguments: ['pending', 'in_progress', 'completed', 'cancelled', 'delete', 'complete', 'cancel', 'clear'], section: 'Work Tracking'),
            new ReplCommandSpec('/projects', '/projects [status|slug|clear]', 'List projects, filter by status, switch the active project, or clear it.', firstArguments: ['active', 'completed', 'archived', 'clear'], section: 'Work Tracking'),
            new ReplCommandSpec('/sprints', '/sprints [project-slug]', 'List sprints for active projects or one project.', section: 'Work Tracking'),
            new ReplCommandSpec('/quality', '/quality', 'Show quality automation status.', section: 'Work Tracking'),
            new ReplCommandSpec('/evaluations', '/evaluations [grade]', 'List evaluation reports, optionally filtered by A, B, C, D, or F.', firstArguments: ['A', 'B', 'C', 'D', 'F'], section: 'Work Tracking'),
            new ReplCommandSpec('/toolkits', '/toolkits [action]', 'List toolkits or run enable|stub|disable <pkg|tool:name> and promote|demote|auto <ToolkitClass>. Advanced toolkit visibility and loading control.', firstArguments: ['enable', 'stub', 'disable', 'promote', 'demote', 'auto'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/schedules', '/schedules [action]', 'Inspect schedules or run status|enable|disable|delete|trigger for operator control.', firstArguments: ['status', 'enable', 'disable', 'delete', 'trigger'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/loops', '/loops [filter|action]', 'Inspect loops and definitions. Start|pause|resume|stop actions are advanced automation controls.', firstArguments: ['start', 'definitions', 'defs', 'status', 'pause', 'resume', 'stop', 'running', 'paused', 'completed', 'failed', 'cancelled'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/webhooks', '/webhooks [action]', 'Inspect webhook subscriptions or run status|deliveries|enable|disable|delete|rotate for operator control.', firstArguments: ['status', 'deliveries', 'enable', 'disable', 'delete', 'rotate'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/task-cancel', '/task-cancel <task-id>', 'Request cancellation for a pending or running task. Advanced operator control for background work.', section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/space', '/space [action]', 'Show Coqui Space status or run search <query>, install <id>, remove <id>, installed, skills, toolkits, or update <id>.', firstArguments: ['status', 'search', 'install', 'remove', 'installed', 'skills', 'toolkits', 'update'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/config', '/config [show|edit]', 'Show config summary or launch the config wizard; edit can restart.', firstArguments: ['show', 'edit'], section: 'System & Exit'),
            new ReplCommandSpec('/multiline', '/multiline [on|off]', 'Toggle multiline compose mode.', firstArguments: ['on', 'off'], section: 'System & Exit'),
            new ReplCommandSpec('/hints', '/hints', 'Toggle command hints in the input area.', section: 'System & Exit'),
            new ReplCommandSpec('/help', '/help', 'Show the command reference.', section: 'System & Exit'),
            new ReplCommandSpec('/update', '/update', 'Check for dependency updates and apply them.', section: 'System & Exit'),
            new ReplCommandSpec('/restart', '/restart', 'Restart Coqui.', section: 'System & Exit'),
            new ReplCommandSpec('/quit', '/quit', 'Exit Coqui.', aliases: ['/exit', '/q'], section: 'System & Exit'),
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

    /**
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function helpSections(): array
    {
        $sections = [];

        foreach (self::all() as $spec) {
            $sections[$spec->section] ??= [];
            $sections[$spec->section][] = [$spec->usage, $spec->helpDescription()];
        }

        return $sections;
    }
}