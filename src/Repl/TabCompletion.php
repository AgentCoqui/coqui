<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Registers readline tab-completion for REPL slash commands, toolkit names,
 * role names, schedule names, and todo IDs.
 */
final class TabCompletion
{
    private string $sessionId = '';

    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
    ) {}

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
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

        readline_completion_function(function (string $input, int $index): array {
            $raw  = function_exists('readline_info') ? readline_info('line_buffer') : $input;
            $line = trim(is_string($raw) ? $raw : $input);
            $parts = explode(' ', $line);
            $cmd = $parts[0];

            // Complete toolkit/tool names after /toolkits subcommand
            if (count($parts) >= 2 && in_array($cmd, ['/toolkits'], strict: true)) {
                return $this->completeToolkits($parts, $input);
            }

            // Complete /todos subcommands, statuses, and todo IDs
            if (count($parts) >= 2 && $cmd === '/todos') {
                return $this->completeTodos($parts, $input);
            }

            // Complete role names after /role
            if (count($parts) >= 2 && $cmd === '/role') {
                return $this->completeRole($parts);
            }

            // Complete /roles subcommands and role names
            if (count($parts) >= 2 && $cmd === '/roles') {
                return $this->completeRoles($parts);
            }

            // Complete /schedules subcommands and schedule names
            if (count($parts) >= 2 && $cmd === '/schedules') {
                return $this->completeSchedules($parts, $input);
            }

            // Complete /loops subcommands and loop IDs
            if (count($parts) >= 2 && $cmd === '/loops') {
                return $this->completeLoops($parts, $input);
            }

            // Complete /projects subcommands and project slugs
            if (count($parts) >= 2 && $cmd === '/projects') {
                return $this->completeProjects($parts, $input);
            }

            // Complete /prompt subcommands
            if (count($parts) >= 2 && $cmd === '/prompt') {
                return array_values(array_filter(
                    ['export'],
                    fn(string $s) => str_starts_with($s, $input),
                ));
            }

            // Complete top-level slash commands
            if (str_starts_with($input, '/') || $line === '' || $line === '/') {
                return $this->completeTopLevel($input);
            }

            return [];
        });
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeToolkits(array $parts, string $input): array
    {
        $sub = $parts[1];
        $toolkitSubCommands = ['enable', 'stub', 'disable'];

        if (count($parts) === 2) {
            return array_values(array_filter(
                $toolkitSubCommands,
                fn(string $s) => str_starts_with($s, $input),
            ));
        }

        if (count($parts) === 3 && in_array($sub, $toolkitSubCommands, strict: true)) {
            $prefix = $parts[2];
            $candidates = [];

            $allPackages = $this->boot->discovery()->allWithVisibility();
            foreach ($allPackages as $entry) {
                $candidates[] = $entry['package'];
            }

            $state = $this->boot->visibilityRegistry()->all();
            foreach (array_keys($state['tools']) as $toolName) {
                $candidates[] = 'tool:' . $toolName;
            }

            return array_values(array_filter(
                $candidates,
                fn(string $c) => str_starts_with($c, $prefix),
            ));
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeTodos(array $parts, string $input): array
    {
        $sub = $parts[1];
        $todoSubCommands = ['delete', 'complete', 'cancel', 'clear', 'pending', 'in_progress', 'completed', 'cancelled'];

        if (count($parts) === 2) {
            return array_values(array_filter(
                $todoSubCommands,
                fn(string $s) => str_starts_with($s, $input),
            ));
        }

        if (count($parts) === 3 && in_array($sub, ['delete', 'complete', 'cancel'], true)) {
            $prefix = $parts[2];
            $candidates = ['all'];
            $todoStore = $this->boot->todoStore();
            if ($todoStore !== null) {
                $todos = $todoStore->list(sessionId: $this->sessionId, limit: 50);
                foreach ($todos as $todo) {
                    $candidates[] = $todo['id'];
                }
            }
            return array_values(array_filter(
                $candidates,
                fn(string $c) => str_starts_with($c, $prefix),
            ));
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeRole(array $parts): array
    {
        $sub = $parts[1];

        if ($sub === 'edit' && count($parts) >= 3) {
            $prefix = $parts[2];
            $roles = $this->boot->roleDiscovery()->availableRoles();
            return array_values(array_filter(
                $roles,
                fn(string $r) => str_starts_with($r, $prefix),
            ));
        }

        $prefix = $parts[1];
        $roles = $this->boot->roleResolver()->selectableRoles();
        $roles[] = 'orchestrator';
        $roles[] = 'edit';
        $roles = array_unique($roles);
        return array_values(array_filter(
            $roles,
            fn(string $r) => str_starts_with($r, $prefix),
        ));
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeRoles(array $parts): array
    {
        $sub = $parts[1];

        if (in_array($sub, ['ignore', 'unignore', 'update'], true) && count($parts) >= 3) {
            $prefix = $parts[2];
            $roles = $this->boot->roleDiscovery()->availableRoles();
            return array_values(array_filter(
                $roles,
                fn(string $r) => str_starts_with($r, $prefix),
            ));
        }

        $prefix = $parts[1];
        $subcommands = ['update', 'ignore', 'unignore'];
        return array_values(array_filter(
            $subcommands,
            fn(string $s) => str_starts_with($s, $prefix),
        ));
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeSchedules(array $parts, string $input): array
    {
        $sub = $parts[1];
        $scheduleSubCommands = ['enable', 'disable', 'delete', 'trigger'];

        if (count($parts) === 2) {
            return array_values(array_filter(
                $scheduleSubCommands,
                fn(string $s) => str_starts_with($s, $input),
            ));
        }

        if (count($parts) === 3 && in_array($sub, $scheduleSubCommands, true)) {
            $prefix = $parts[2];
            $candidates = ['all'];
            $scheduleStore = new ScheduleStore($this->storage->getPdo());
            $schedules = $scheduleStore->list();
            foreach ($schedules as $s) {
                $candidates[] = $s['name'];
                $candidates[] = $s['id'];
            }
            return array_values(array_filter(
                $candidates,
                fn(string $c) => str_starts_with($c, $prefix),
            ));
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeLoops(array $parts, string $input): array
    {
        $sub = $parts[1];
        $loopSubCommands = ['definitions', 'status', 'pause', 'resume', 'stop', 'running', 'paused', 'completed', 'failed', 'cancelled'];

        if (count($parts) === 2) {
            return array_values(array_filter(
                $loopSubCommands,
                fn(string $s) => str_starts_with($s, $input),
            ));
        }

        if (count($parts) === 3 && in_array($sub, ['status', 'pause', 'resume', 'stop'], true)) {
            $prefix = $parts[2];
            $candidates = ['all'];
            $loopStore = new LoopStore($this->storage->getPdo());
            $loops = $loopStore->listLoops();
            foreach ($loops as $loop) {
                $candidates[] = $loop['id'];
            }
            return array_values(array_filter(
                $candidates,
                fn(string $c) => str_starts_with($c, $prefix),
            ));
        }

        return [];
    }

    /**
     * @param array<string> $parts
     * @return list<string>
     */
    private function completeProjects(array $parts, string $input): array
    {
        if (count($parts) !== 2) {
            return [];
        }

        $prefix = $parts[1];
        $candidates = ['clear', 'active', 'completed', 'archived'];
        $projectStore = $this->boot->projectStore();
        if ($projectStore !== null) {
            $projects = $projectStore->listProjects(limit: 50);
            foreach ($projects as $p) {
                $candidates[] = $p['slug'];
            }
        }
        return array_values(array_filter(
            $candidates,
            fn(string $c) => str_starts_with($c, $prefix),
        ));
    }

    /**
     * @return list<string>
     */
    private function completeTopLevel(string $input): array
    {
        $commands = [
            '/new', '/history', '/sessions', '/resume', '/model',
            '/config', '/tasks', '/task', '/task-cancel', '/todos', '/projects', '/sprints', '/toolkits', '/schedules', '/loops',
            '/prompt', '/role', '/roles', '/update', '/restart', '/space', '/space skills', '/space toolkits', '/evaluations', '/hints', '/help', '/quit',
        ];

        return array_values(array_filter($commands, fn(string $c) => str_starts_with($c, $input)));
    }
}
