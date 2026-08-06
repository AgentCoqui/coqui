<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Contract\ToolkitTabCompletionProvider;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Registers readline tab-completion for REPL slash commands.
 */
final class TabCompletion
{
    private string $sessionId = '';

    /** @var array<string, ToolkitCommandHandler> */
    private array $toolkitHandlers = [];

    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
    ) {}

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Register toolkit command handlers for tab completion.
     *
     * @param list<ToolkitCommandHandler> $handlers
     */
    public function setToolkitCommandHandlers(array $handlers): void
    {
        $this->toolkitHandlers = [];
        foreach ($handlers as $handler) {
            $this->toolkitHandlers[$handler->commandName()] = $handler;
        }
    }

    /**
     * Install the readline_completion_function callback.
     *
     * No-op when readline is not available (e.g. Docker without libedit).
     */
    public function register(): void
    {
        if (!function_exists('readline_completion_function')) {
            return;
        }

        readline_completion_function(
            fn(string $input, int $index): array => $this->complete($this->currentBuffer($input)),
        );
    }

    /**
     * @return list<string>
     */
    public function complete(string $buffer): array
    {
        $parts = $this->tokenize($buffer);
        if ($parts === []) {
            return $this->completeTopLevel('');
        }

        if (count($parts) === 1 && !str_ends_with($buffer, ' ')) {
            return $this->completeTopLevel($parts[0]);
        }

        $spec = ReplCommandCatalog::find($parts[0]);
        if ($spec === null) {
            return str_starts_with($parts[0], '/') ? $this->completeTopLevel($parts[0]) : [];
        }

        return match ($spec->name) {
            '/resume' => $this->completeResume($parts),
            '/model', '/budget' => $this->completeRoleChoiceCommand($parts),
            '/config', '/tasks', '/prompt', '/evaluations', '/multiline', '/audit' => $this->completeStaticArguments($spec, $parts),
            '/task' => $this->completeTask($parts),
            '/task-cancel' => $this->completeTaskCancel($parts),
            '/projects' => $this->completeProjects($parts),
            '/toolkits' => $this->completeToolkits($parts),
            '/schedules' => $this->completeSchedules($parts),
            '/loops' => $this->completeLoops($parts),
            '/summarize' => $this->completeSummarize($parts),
            '/role' => $this->completeRole($parts),
            '/roles' => $this->completeRoles($parts),
            '/group' => $this->completeGroup($parts),
            '/persona' => $this->completePersona($parts),
            default => $this->completeToolkitCommand($spec, $parts),
        };
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeToolkits(array $parts): array
    {
        $sub = $parts[1];
        $visibilitySubCommands = ['enable', 'stub', 'disable'];
        $loadingSubCommands = ['promote', 'demote', 'auto'];

        if (count($parts) === 2) {
            return $this->completeChoices($this->commandSpec('/toolkits')->firstArguments, $parts[1]);
        }

        if (count($parts) === 3 && in_array($sub, $visibilitySubCommands, true)) {
            $candidates = [];

            foreach ($this->boot->discovery()->allWithVisibility() as $entry) {
                $candidates[] = $entry['package'];
            }

            foreach (array_keys($this->boot->visibilityRegistry()->all()['tools']) as $toolName) {
                $candidates[] = 'tool:' . $toolName;
            }

            return $this->completeChoices($candidates, $parts[2]);
        }

        if (count($parts) === 3 && in_array($sub, $loadingSubCommands, true)) {
            $candidates = [];

            foreach ($this->boot->discovery()->allWithVisibility() as $entry) {
                foreach ($entry['classes'] as $className) {
                    $classParts = explode('\\', $className);
                    $candidates[] = (string) end($classParts);
                }
            }

            return $this->completeChoices(array_values(array_unique($candidates)), $parts[2]);
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeRole(array $parts): array
    {
        if ($parts[1] === 'edit' && count($parts) === 3) {
            return $this->completeChoices(array_values($this->boot->roleDiscovery()->availableRoles()), $parts[2]);
        }

        $choices = $this->boot->roleResolver()->selectableRoles();
        $choices[] = SystemRole::Orchestrator->value;
        $choices[] = 'reset';
        $choices[] = 'edit';

        return $this->completeChoices(array_values(array_unique($choices)), $parts[1]);
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeRoles(array $parts): array
    {
        if (count($parts) >= 3 && in_array($parts[1], ['ignore', 'unignore', 'update'], true)) {
            return $this->completeChoices(array_values($this->boot->roleDiscovery()->availableRoles()), $parts[2]);
        }

        return $this->completeChoices($this->commandSpec('/roles')->firstArguments, $parts[1]);
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeSchedules(array $parts): array
    {
        $subcommands = $this->commandSpec('/schedules')->firstArguments;

        if (count($parts) === 2) {
            return $this->completeChoices($subcommands, $parts[1]);
        }

        if (count($parts) === 3 && in_array($parts[1], $subcommands, true)) {
            $candidates = in_array($parts[1], ['enable', 'disable', 'delete', 'trigger'], true) ? ['all'] : [];
            $scheduleStore = new ScheduleStore($this->storage->getPdo());

            foreach ($scheduleStore->list() as $schedule) {
                $candidates[] = $schedule['name'];
                $candidates[] = $schedule['id'];
            }

            return $this->completeChoices($candidates, $parts[2]);
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeLoops(array $parts): array
    {
        if (count($parts) === 2) {
            return $this->completeChoices($this->commandSpec('/loops')->firstArguments, $parts[1]);
        }

        if (count($parts) === 3 && $parts[1] === 'start') {
            $loopDiscovery = $this->boot->loopDiscovery();
            if ($loopDiscovery === null) {
                return [];
            }

            return $this->completeChoices($loopDiscovery->availableLoops(), $parts[2]);
        }

        if (count($parts) === 3 && in_array($parts[1], ['status', 'pause', 'resume', 'stop'], true)) {
            $candidates = ['all'];
            $loopStore = new LoopStore($this->storage->getPdo());

            foreach ($loopStore->listLoops() as $loop) {
                $candidates[] = $loop['id'];
            }

            return $this->completeChoices($candidates, $parts[2]);
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeProjects(array $parts): array
    {
        if (count($parts) !== 2) {
            return [];
        }

        $candidates = $this->commandSpec('/projects')->firstArguments;
        $projectStore = $this->boot->projectStore();

        if ($projectStore !== null) {
            foreach ($projectStore->listProjects(limit: 50) as $project) {
                $candidates[] = $project['slug'];
            }
        }

        return $this->completeChoices($candidates, $parts[1]);
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeResume(array $parts): array
    {
        if (count($parts) !== 2) {
            return [];
        }

        return $this->completeChoices(
            array_values(array_map(static fn(array $session): string => (string) $session['id'], $this->storage->listSessions(20))),
            $parts[1],
        );
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeRoleChoiceCommand(array $parts): array
    {
        if (count($parts) !== 2) {
            return [];
        }

        return $this->completeChoices(array_keys($this->boot->roleResolver()->toArray()), $parts[1]);
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeTask(array $parts): array
    {
        if (count($parts) !== 2) {
            return [];
        }

        return $this->completeChoices(
            array_values(array_map(static fn(array $task): string => (string) $task['id'], $this->storage->listTasks(limit: 20))),
            $parts[1],
        );
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeTaskCancel(array $parts): array
    {
        if (count($parts) !== 2) {
            return [];
        }

        $tasks = array_filter(
            $this->storage->listTasks(limit: 50),
            static fn(array $task): bool => !in_array((string) $task['status'], ['completed', 'failed', 'cancelled'], true),
        );

        return $this->completeChoices(
            array_values(array_map(static fn(array $task): string => (string) $task['id'], $tasks)),
            $parts[1],
        );
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completePersona(array $parts): array
    {
        if (count($parts) === 2) {
            $candidates = [
                ...$this->commandSpec('/persona')->firstArguments,
                ...$this->boot->personaDiscovery()->availablePersonas(),
            ];

            return $this->completeChoices($candidates, $parts[1]);
        }

        if (count($parts) === 3 && $parts[1] === 'default') {
            $candidates = ['none', 'reset', 'clear', ...$this->boot->personaDiscovery()->availablePersonas()];

            return $this->completeChoices($candidates, $parts[2]);
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeGroup(array $parts): array
    {
        if (count($parts) === 2) {
            return $this->completeChoices($this->commandSpec('/group')->firstArguments, $parts[1]);
        }

        $action = $parts[1] ?? '';

        if (count($parts) === 3 && in_array($action, ['start', 'replace'], true)) {
            return $this->completeChoices([
                ...$this->boot->personaDiscovery()->availablePersonas(),
                '--rounds=3',
            ], $parts[2]);
        }

        if (count($parts) === 3 && $action === 'add') {
            $candidates = $this->boot->personaDiscovery()->availablePersonas();

            if ($this->storage->isGroupSession($this->sessionId)) {
                $existing = array_fill_keys($this->storage->listSessionGroupMemberNames($this->sessionId), true);
                $candidates = array_values(array_filter(
                    $candidates,
                    static fn(string $candidate): bool => !isset($existing[$candidate]),
                ));
            }

            return $this->completeChoices($candidates, $parts[2]);
        }

        if (count($parts) === 3 && $action === 'remove') {
            $candidates = $this->storage->isGroupSession($this->sessionId)
                ? $this->storage->listSessionGroupMemberNames($this->sessionId)
                : [];

            return $this->completeChoices($candidates, $parts[2]);
        }

        if (count($parts) === 3 && $action === 'rounds') {
            return $this->completeChoices(['1', '2', '3', '4', '5'], $parts[2]);
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeSummarize(array $parts): array
    {
        if (count($parts) === 2) {
            return $this->completeChoices($this->commandSpec('/summarize')->firstArguments, $parts[1]);
        }

        if (count($parts) === 3 && $parts[1] === 'recent') {
            return $this->completeChoices(['1', '3', '5', '10', '20'], $parts[2]);
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeStaticArguments(ReplCommandSpec $spec, array $parts): array
    {
        if (count($parts) !== 2) {
            return [];
        }

        return $this->completeChoices($spec->firstArguments, $parts[1]);
    }

    private function commandSpec(string $command): ReplCommandSpec
    {
        $spec = ReplCommandCatalog::find($command);
        if ($spec === null) {
            throw new \LogicException(sprintf('Unknown REPL command spec: %s', $command));
        }

        return $spec;
    }

    private function currentBuffer(string $input): string
    {
        $raw = function_exists('readline_info') ? readline_info('line_buffer') : $input;

        return is_string($raw) ? $raw : $input;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $buffer): array
    {
        $normalized = ltrim($buffer);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/', trim($normalized));
        if ($parts === false) {
            return [];
        }

        $tokens = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        if ($tokens === []) {
            return [];
        }

        if (preg_match('/\s$/', $normalized) === 1) {
            $tokens[] = '';
        }

        return $tokens;
    }

    /**
     * @param list<string> $choices
     * @return list<string>
     */
    private function completeChoices(array $choices, string $prefix): array
    {
        $matches = [];
        $seen = [];

        foreach ($choices as $choice) {
            if ($choice === '') {
                continue;
            }

            $key = strtolower($choice);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            if (str_starts_with(strtolower($choice), strtolower($prefix))) {
                $matches[] = $choice;
            }
        }

        return $matches;
    }

    /**
     * Complete arguments for a toolkit-provided command.
     *
     * First tries dynamic completion via ToolkitTabCompletionProvider,
     * then falls back to static subcommands() from the handler.
     *
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeToolkitCommand(ReplCommandSpec $spec, array $parts): array
    {
        $name = ltrim($spec->name, '/');
        $handler = $this->toolkitHandlers[$name] ?? null;

        if ($handler === null) {
            // Fallback to static arguments from the catalog spec
            return $this->completeStaticArguments($spec, $parts);
        }

        // Try dynamic completion first
        if ($handler instanceof ToolkitTabCompletionProvider) {
            $argParts = array_values(array_slice($parts, 1));
            $candidates = $handler->completeArguments($name, $argParts);
            if ($candidates !== []) {
                $prefix = end($argParts) ?: '';
                if (count($argParts) <= 1) {
                    $candidates = array_values(array_unique([...$candidates, 'help']));
                }

                return $this->completeChoices($candidates, $prefix);
            }
        }

        // Fall back to static subcommands for first argument
        if (count($parts) === 2) {
            return $this->completeChoices(array_values(array_unique([...$handler->subcommands(), 'help'])), $parts[1]);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function completeTopLevel(string $input): array
    {
        return $this->completeChoices(ReplCommandCatalog::topLevelCommands(), $input);
    }
}
