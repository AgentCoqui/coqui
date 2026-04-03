<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /loops and all subcommands (start, status, pause, resume, stop, definitions).
 */
final class LoopHandler
{
    public function __construct(
        private readonly SessionStorage $storage,
        private readonly ?LoopDiscovery $loopDiscovery,
        private readonly ?LoopExecutor $loopExecutor = null,
    ) {}

    public function handle(SymfonyStyle $io, string $arg, string $sessionId = ''): void
    {
        $loopStore = new LoopStore($this->storage->getPdo());

        $trimmedArg = trim($arg);
        $argParts = $trimmedArg !== '' ? explode(' ', $trimmedArg, 2) : [];
        $action = strtolower($argParts[0] ?? '');
        $target = trim($argParts[1] ?? '');

        match ($action) {
            'start' => $this->handleStart($io, $target, $sessionId),
            'definitions', 'defs' => $this->handleDefinitions($io),
            'status' => $this->handleStatus($io, $loopStore, $target),
            'pause' => $this->handlePause($io, $loopStore, $target),
            'resume' => $this->handleResume($io, $loopStore, $target),
            'stop' => $this->handleStop($io, $loopStore, $target),
            default => $this->handleList($io, $loopStore, $action),
        };
    }

    private function handleStart(SymfonyStyle $io, string $target, string $sessionId): void
    {
        if ($this->loopExecutor === null || $this->loopDiscovery === null) {
            $io->error('Loop execution is not available — required stores were not initialized.');
            return;
        }

        if ($target === '') {
            $io->error('Usage: /loops start <definition> <goal>');
            $io->text('<fg=gray>Example: /loops start harness "Build a REST API for user management"</>');
            return;
        }

        // Parse: first word is definition name, rest is goal
        $parts = explode(' ', $target, 2);
        $defName = $parts[0];
        $goal = trim($parts[1] ?? '');

        if ($goal === '') {
            $io->error('A goal is required. Usage: /loops start <definition> <goal>');
            return;
        }

        // Strip surrounding quotes from goal if present
        if (
            (str_starts_with($goal, '"') && str_ends_with($goal, '"'))
            || (str_starts_with($goal, "'") && str_ends_with($goal, "'"))
        ) {
            $goal = substr($goal, 1, -1);
        }

        if (!$this->loopDiscovery->exists($defName)) {
            $available = implode(', ', $this->loopDiscovery->availableLoops());
            $io->error(sprintf('Loop definition "%s" not found. Available: %s', $defName, $available));
            return;
        }

        $rawDefinition = $this->loopDiscovery->getRawDefinition($defName);
        $definition = $this->loopDiscovery->get($defName);

        $io->section(sprintf('Starting loop: %s', $defName));
        $io->text([
            sprintf('<fg=gray>Definition:</> %s', $definition->name),
            sprintf('<fg=gray>Roles:</> %s', implode(' → ', array_map(fn($r) => $r->role, $definition->roles))),
            sprintf('<fg=gray>Termination:</> %s', $definition->terminationCondition->type->value),
            sprintf('<fg=gray>Goal:</> %s', $goal),
        ]);
        $io->newLine();

        try {
            $loopId = $this->loopExecutor->startLoop(
                rawDefinition: $rawDefinition,
                goal: $goal,
                sessionId: $sessionId !== '' ? $sessionId : null,
            );

            $io->success(sprintf(
                'Loop "%s" started with ID %s. Stages will execute as background tasks via the API server. Use /loops status %s to monitor.',
                $defName,
                $loopId,
                substr($loopId, 0, 8),
            ));
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to start loop: %s', $e->getMessage()));
        }
    }

    private function handleList(SymfonyStyle $io, LoopStore $store, string $statusFilter): void
    {
        $filter = in_array($statusFilter, ['running', 'paused', 'completed', 'failed', 'cancelled'], true)
            ? $statusFilter
            : null;

        $loops = $store->listLoops($filter);

        if ($loops === []) {
            $io->info('No loops found' . ($filter !== null ? " with status \"{$filter}\"" : '') . '. Start one via the agent (loop_start tool) or the API.');
            return;
        }

        $activeCount = $store->countActive();
        $io->section(sprintf('Loops (%d active / %d total)', $activeCount, count($loops)));

        $rows = [];
        foreach ($loops as $loop) {
            $status = match ($loop['status']) {
                'running' => '<fg=green>●</>',
                'paused' => '<fg=yellow>◉</>',
                'completed' => '<fg=cyan>✓</>',
                'failed' => '<fg=red>✗</>',
                'cancelled' => '<fg=gray>⊘</>',
                default => $loop['status'],
            };
            $started = TimeFormatter::timeSince($loop['started_at']);
            $goal = mb_strlen($loop['goal']) > 60
                ? mb_substr($loop['goal'], 0, 57) . '...'
                : $loop['goal'];

            $rows[] = [
                $status,
                substr($loop['id'], 0, 8) . '...',
                $loop['definition_name'],
                sprintf('%d/%s', $loop['current_iteration'], $loop['max_iterations'] > 0 ? (string) $loop['max_iterations'] : '∞'),
                $loop['current_stage'],
                $goal,
                $started,
            ];
        }

        $io->table(['', 'ID', 'Definition', 'Iteration', 'Stage', 'Goal', 'Started'], $rows);
    }

    private function handleDefinitions(SymfonyStyle $io): void
    {
        if ($this->loopDiscovery === null) {
            $io->error('Loop discovery not available.');
            return;
        }

        $definitions = $this->loopDiscovery->discoverAll();

        if ($definitions === []) {
            $io->info('No loop definitions found. Add JSON files to workspace/loops/');
            return;
        }

        $io->section(sprintf('Loop Definitions (%d)', count($definitions)));

        $rows = [];
        foreach ($definitions as $def) {
            $roles = implode(' → ', array_map(fn($r) => $r->role, $def->roles));
            $rows[] = [
                $def->name,
                mb_strlen($def->description) > 50 ? mb_substr($def->description, 0, 47) . '...' : $def->description,
                $roles,
                $def->terminationCondition->type->value,
            ];
        }

        $io->table(['Name', 'Description', 'Roles', 'Termination'], $rows);
    }

    private function handleStatus(SymfonyStyle $io, LoopStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /loops status <id>');
            return;
        }

        $state = $store->getCurrentState($target);
        if ($state === null) {
            $io->error("Loop \"{$target}\" not found.");
            return;
        }

        $loop = $state['loop'];
        $iteration = $state['iteration'];
        $stages = $state['stages'];

        $io->section(sprintf('Loop: %s (%s)', $loop['definition_name'], $loop['id']));
        $io->definitionList(
            ['Status' => $loop['status']],
            ['Goal' => $loop['goal']],
            ['Iteration' => sprintf('%d/%s', $loop['current_iteration'], $loop['max_iterations'] > 0 ? (string) $loop['max_iterations'] : '∞')],
            ['Started' => $loop['started_at']],
            ['Completed' => $loop['completed_at'] ?? '-'],
        );

        if ($iteration !== null && $stages !== []) {
            $io->text('<fg=cyan>Current Iteration Stages:</>');
            $stageRows = [];
            foreach ($stages as $s) {
                $stageStatus = match ($s['status']) {
                    'running' => '<fg=green>running</>',
                    'completed' => '<fg=cyan>done</>',
                    'failed' => '<fg=red>failed</>',
                    'pending' => '<fg=gray>pending</>',
                    default => $s['status'],
                };
                $summary = $s['result_summary'] !== null
                    ? (mb_strlen($s['result_summary']) > 80 ? mb_substr($s['result_summary'], 0, 77) . '...' : $s['result_summary'])
                    : '-';
                $stageRows[] = [
                    $s['stage_index'],
                    $s['role'],
                    $stageStatus,
                    $summary,
                ];
            }
            $io->table(['#', 'Role', 'Status', 'Summary'], $stageRows);
        }
    }

    private function handlePause(SymfonyStyle $io, LoopStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /loops pause <id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $running = $store->listLoops('running');
            if ($running === []) {
                $io->info('No running loops to pause.');
                return;
            }
            if (!$io->confirm(sprintf('Pause all %d running loop(s)?', count($running)), false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            foreach ($running as $loop) {
                $store->updateLoopStatus($loop['id'], 'paused');
            }
            $io->success(sprintf('Paused %d loop(s).', count($running)));
            return;
        }

        $loop = $store->getLoop($target);
        if ($loop === null) {
            $io->error("Loop \"{$target}\" not found.");
            return;
        }

        if ($loop['status'] !== 'running') {
            $io->error("Cannot pause loop — current status is \"{$loop['status']}\".");
            return;
        }

        $store->updateLoopStatus($target, 'paused');
        $io->success("Paused loop {$target}.");
    }

    private function handleResume(SymfonyStyle $io, LoopStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /loops resume <id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $paused = $store->listLoops('paused');
            if ($paused === []) {
                $io->info('No paused loops to resume.');
                return;
            }
            if (!$io->confirm(sprintf('Resume all %d paused loop(s)?', count($paused)), false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            foreach ($paused as $loop) {
                $store->updateLoopStatus($loop['id'], 'running');
            }
            $io->success(sprintf('Resumed %d loop(s).', count($paused)));
            return;
        }

        $loop = $store->getLoop($target);
        if ($loop === null) {
            $io->error("Loop \"{$target}\" not found.");
            return;
        }

        if ($loop['status'] !== 'paused') {
            $io->error("Cannot resume loop — current status is \"{$loop['status']}\".");
            return;
        }

        $store->updateLoopStatus($target, 'running');
        $io->success("Resumed loop {$target}.");
    }

    private function handleStop(SymfonyStyle $io, LoopStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /loops stop <id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $active = array_merge($store->listLoops('running'), $store->listLoops('paused'));
            if ($active === []) {
                $io->info('No active loops to stop.');
                return;
            }
            if (!$io->confirm(sprintf('Cancel all %d active loop(s)? This cannot be undone.', count($active)), false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            foreach ($active as $loop) {
                $store->updateLoopStatus($loop['id'], 'cancelled');
            }
            $io->success(sprintf('Cancelled %d loop(s).', count($active)));
            return;
        }

        $loop = $store->getLoop($target);
        if ($loop === null) {
            $io->error("Loop \"{$target}\" not found.");
            return;
        }

        if (!in_array($loop['status'], ['running', 'paused'], true)) {
            $io->error("Cannot stop loop — current status is \"{$loop['status']}\".");
            return;
        }

        if (!$io->confirm("Cancel loop {$target}? This cannot be undone.", false)) {
            $io->text('<fg=gray>Cancelled.</>');
            return;
        }

        $store->updateLoopStatus($target, 'cancelled');
        $io->success("Cancelled loop {$target}.");
    }
}
