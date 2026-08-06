<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;


/**
 * Canonical slash-command metadata shared by help output and tab completion.
 *
 * Supports both static (core) commands and dynamically registered toolkit commands.
 */
final class ReplCommandCatalog
{
    /** @var list<ReplCommandSpec>|null */
    private static ?array $commands = null;

    /** @var list<ReplCommandSpec> */
    private static array $toolkitSpecs = [];

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
            new ReplCommandSpec('/group', '/group [action]', 'Inspect or manage session-based group chats.', firstArguments: ['status', 'start', 'add', 'remove', 'replace', 'rounds', 'help'], section: 'Core Commands'),
            new ReplCommandSpec('/persona', '/persona [name|default|reset]', 'Show or switch the active persona, set a default with default <name|none>, or clear it.', firstArguments: ['default', 'reset', 'none'], section: 'Core Commands'),
            new ReplCommandSpec('/personas', '/personas', 'List available personas.', section: 'Core Commands'),
            new ReplCommandSpec('/model', '/model [role]', 'Show resolved model configuration, optionally for one role.', section: 'Context & Inspection'),
            new ReplCommandSpec('/thinking', '/thinking [off|low|medium|high|clear]', 'Show or set reasoning effort for the active model (off disables thinking).', firstArguments: ['off', 'low', 'medium', 'high', 'clear'], section: 'Context & Inspection'),
            new ReplCommandSpec('/budget', '/budget [role]', 'Show prompt-budget and toolkit loading decisions.', section: 'Context & Inspection'),
            new ReplCommandSpec('/prompt', '/prompt [export]', 'Show the rendered system prompt, source breakdowns, or export it to the workspace.', firstArguments: ['export'], section: 'Context & Inspection'),
            new ReplCommandSpec('/summarize', '/summarize [recent N|focus topic]', 'Summarize older conversation history to reclaim context.', firstArguments: ['recent', 'focus'], section: 'Context & Inspection'),
            new ReplCommandSpec('/audit', '/audit [tool|session|action|limit] <value>', 'Browse the audit log of approval decisions and questions.', firstArguments: ['tool', 'session', 'action', 'limit'], section: 'Context & Inspection'),
            new ReplCommandSpec('/tasks', '/tasks [status]', 'List background tasks, optionally filtered by status.', firstArguments: ['all', 'pending', 'running', 'cancelling', 'completed', 'failed', 'cancelled'], section: 'Work Tracking'),
            new ReplCommandSpec('/task', '/task <task-id>', 'Show task status, metadata, and recent events.', section: 'Work Tracking'),
            new ReplCommandSpec('/projects', '/projects [status|slug|clear]', 'List projects, filter by status, switch the active project, or clear it.', firstArguments: ['active', 'completed', 'archived', 'clear'], section: 'Work Tracking'),
            new ReplCommandSpec('/toolkits','/toolkits [action]', 'List toolkits or run enable|stub|disable <pkg|tool:name> and promote|demote|auto <ToolkitClass>. Advanced toolkit visibility and loading control.', firstArguments: ['enable', 'stub', 'disable', 'promote', 'demote', 'auto'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/schedules', '/schedules [action]', 'Inspect schedules or run status|enable|disable|delete|trigger for operator control.', firstArguments: ['status', 'enable', 'disable', 'delete', 'trigger'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/loops', '/loops [filter|action]', 'Inspect loops and definitions. Start|pause|resume|stop actions are advanced automation controls.', firstArguments: ['start', 'definitions', 'defs', 'status', 'pause', 'resume', 'stop', 'running', 'paused', 'completed', 'failed', 'cancelled'], section: 'Advanced Automation & Operator Controls'),
            new ReplCommandSpec('/task-cancel', '/task-cancel <task-id>', 'Request cancellation for a pending or running task. Advanced operator control for background work.', section: 'Advanced Automation & Operator Controls'),
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

        foreach (self::$toolkitSpecs as $spec) {
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

        foreach (self::$toolkitSpecs as $spec) {
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
        $all = array_merge(self::all(), self::$toolkitSpecs);

        return array_map(
            static fn(ReplCommandSpec $spec): array => [$spec->usage, $spec->helpDescription()],
            $all,
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

        foreach (self::$toolkitSpecs as $spec) {
            $sections[$spec->section] ??= [];
            $sections[$spec->section][] = [$spec->usage, $spec->helpDescription()];
        }

        return $sections;
    }

    /**
     * Register REPL command specs from toolkit-provided command handlers.
     *
     * Called during boot to merge toolkit commands into the catalog.
     * Toolkit commands appear under the "Toolkit Commands" section in help output.
     * Core commands always take precedence. When two toolkits register the same
     * command name, first-discovered wins and the later handler is skipped.
     *
     * @param list<ToolkitCommandCandidate> $candidates
     */
    public static function registerToolkitHandlers(array $candidates): ToolkitCommandRegistrationReport
    {
        self::$toolkitSpecs = [];
        $coreSpecs = [];
        foreach (self::all() as $spec) {
            foreach ($spec->allNames() as $name) {
                $coreSpecs[$name] = $spec;
            }
        }

        $acceptedHandlers = [];
        $acceptedSpecs = [];
        $acceptedByCommand = [];
        $collisions = [];

        foreach ($candidates as $candidate) {
            $handler = $candidate->handler;
            $command = '/' . $handler->commandName();

            // Core commands take precedence
            if (isset($coreSpecs[$command])) {
                $coreSpec = $coreSpecs[$command];
                $collisions[] = new ToolkitCommandCollision(
                    $command,
                    'core',
                    'coquibot/coqui',
                    $coreSpec->name,
                    $coreSpec->usage,
                    $candidate->package,
                    $handler::class,
                    $handler->usage(),
                );
                continue;
            }

            if (isset($acceptedByCommand[$command])) {
                $winner = $acceptedByCommand[$command];
                $collisions[] = new ToolkitCommandCollision(
                    $command,
                    'toolkit',
                    $winner->package,
                    $winner->handler::class,
                    $winner->handler->usage(),
                    $candidate->package,
                    $handler::class,
                    $handler->usage(),
                );
                continue;
            }

            $spec = new ReplCommandSpec(
                $command,
                $handler->usage(),
                $handler->description(),
                firstArguments: array_values(array_unique([...$handler->subcommands(), 'help'])),
                section: 'Toolkit Commands',
            );

            $acceptedByCommand[$command] = $candidate;
            $acceptedHandlers[] = $handler;
            $acceptedSpecs[] = $spec;
            self::$toolkitSpecs[] = $spec;
        }

        return new ToolkitCommandRegistrationReport($acceptedHandlers, $acceptedSpecs, $collisions);
    }

    /**
     * Clear toolkit command registrations.
     *
     * Used during restart or testing to reset dynamic state.
     */
    public static function clearToolkitHandlers(): void
    {
        self::$toolkitSpecs = [];
    }
}
