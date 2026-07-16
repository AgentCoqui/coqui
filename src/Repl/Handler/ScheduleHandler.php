<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\Clock;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /schedules and all subcommands (status, enable, disable, delete, trigger).
 */
final class ScheduleHandler
{
    public function __construct(
        private readonly SessionStorage $storage,
    ) {}

    public function handle(SymfonyStyle $io, string $arg): void
    {
        $scheduleStore = new ScheduleStore($this->storage->getPdo());

        $trimmedArg = trim($arg);
        $argParts = $trimmedArg !== '' ? explode(' ', $trimmedArg, 2) : [];
        $action = strtolower($argParts[0] ?? '');
        $target = trim($argParts[1] ?? '');

        match ($action) {
            'status' => $this->handleStatus($io, $scheduleStore, $target),
            'enable' => $this->handleEnable($io, $scheduleStore, $target),
            'disable' => $this->handleDisable($io, $scheduleStore, $target),
            'delete' => $this->handleDelete($io, $scheduleStore, $target),
            'trigger' => $this->handleTrigger($io, $scheduleStore, $target),
            default => $this->handleList($io, $scheduleStore),
        };
    }

    private function handleStatus(SymfonyStyle $io, ScheduleStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /schedules status <name|id>');
            return;
        }

        $schedule = $this->resolveByIdOrName($store, $target);
        if ($schedule === null) {
            $io->error("No schedule found matching '{$target}'.");
            return;
        }

        $io->section(sprintf('Schedule: %s', $schedule['name']));
        $io->definitionList(
            ['ID' => (string) $schedule['id']],
            ['Enabled' => ((int) $schedule['enabled']) === 1 ? 'yes' : 'no'],
            ['Source' => (string) ($schedule['source'] ?? ScheduleStore::SOURCE_SYSTEM)],
            ['Expression' => (string) $schedule['schedule_expression']],
            ['Timezone' => (string) ($schedule['timezone'] ?? 'UTC')],
            ['Role' => (string) ($schedule['role'] ?? 'orchestrator')],
            ['Max iterations' => (string) ($schedule['max_iterations'] ?? 48)],
            ['Max failures' => (string) ($schedule['max_failures'] ?? 3)],
            ['Next run' => (string) ($schedule['next_run_at'] ?? 'never')],
            ['Last run' => (string) ($schedule['last_run_at'] ?? 'never')],
            ['Last task' => (string) ($schedule['last_task_id'] ?? '-')],
            ['Last status' => (string) ($schedule['last_status'] ?? '-')],
            ['Run count' => (string) ($schedule['run_count'] ?? 0)],
            ['Failure count' => (string) ($schedule['failure_count'] ?? 0)],
        );

        if (($schedule['description'] ?? null) !== null && trim((string) $schedule['description']) !== '') {
            $io->text('<fg=cyan>Description:</>');
            $io->writeln((string) $schedule['description']);
        }

        if ($this->isFilesystemSchedule($schedule) && ($schedule['source_path'] ?? null) !== null) {
            $io->text(sprintf('<fg=cyan>Source file:</> %s', (string) $schedule['source_path']));
        }

        $io->text('<fg=cyan>Prompt:</>');
        $io->writeln((string) $schedule['prompt']);
    }

    private function handleList(SymfonyStyle $io, ScheduleStore $store): void
    {
        $schedules = $store->list();

        if (empty($schedules)) {
            $io->info('No scheduled tasks. Create schedules via the agent (schedule_create tool) or the API.');
            return;
        }

        $stats = $store->getStats();
        $io->section(sprintf('Schedules (%d active / %d total)', $stats['enabled'], $stats['total']));

        $rows = [];
        foreach ($schedules as $s) {
            $status = ((int) $s['enabled']) ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $source = ($s['source'] ?? 'system') === 'filesystem' ? '<fg=cyan>file</>' : 'system';
            $lastRun = $s['last_run_at'] !== null ? TimeFormatter::timeSince($s['last_run_at']) : '-';
            $rows[] = [
                $status,
                substr($s['id'], 0, 8) . '...',
                $s['name'],
                $source,
                $s['schedule_expression'],
                $s['next_run_at'] ?? 'N/A',
                $lastRun,
                $s['last_status'] ?? '-',
                $s['run_count'],
                $s['failure_count'],
            ];
        }

        $io->table(['', 'ID', 'Name', 'Source', 'Expression', 'Next Run', 'Last Run', 'Last Status', 'Runs', 'Fails'], $rows);
    }

    private function handleEnable(SymfonyStyle $io, ScheduleStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /schedules enable <name|id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $stats = $store->getStats();
            if ($stats['disabled'] === 0) {
                $io->info('No disabled schedules to enable.');
                return;
            }
            if (!$io->confirm("Enable all {$stats['disabled']} disabled schedule(s)?", false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            $count = $store->enableAll();
            $io->success("Enabled {$count} schedule(s). Failure counters reset.");
            return;
        }

        $schedule = $this->resolveByIdOrName($store, $target);
        if ($schedule === null) {
            $io->error("No schedule found matching '{$target}'.");
            return;
        }

        if ($this->isFilesystemSchedule($schedule)) {
            $io->warning(sprintf(
                "Schedule '%s' is defined by a filesystem file (%s). Set \"enabled\": true in the JSON file to enable it.",
                $schedule['name'],
                basename((string) $schedule['source_path']),
            ));
            return;
        }

        $store->enable((string) $schedule['id']);
        $io->success("Enabled: {$schedule['name']}");
    }

    private function handleDisable(SymfonyStyle $io, ScheduleStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /schedules disable <name|id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $stats = $store->getStats();
            if ($stats['enabled'] === 0) {
                $io->info('No enabled schedules to disable.');
                return;
            }
            if (!$io->confirm("Disable all {$stats['enabled']} enabled schedule(s)?", false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            $count = $store->disableAll();
            $io->success("Disabled {$count} schedule(s).");
            return;
        }

        $schedule = $this->resolveByIdOrName($store, $target);
        if ($schedule === null) {
            $io->error("No schedule found matching '{$target}'.");
            return;
        }

        if ($this->isFilesystemSchedule($schedule)) {
            $io->warning(sprintf(
                "Schedule '%s' is defined by a filesystem file (%s). Set \"enabled\": false in the JSON file to disable it.",
                $schedule['name'],
                basename((string) $schedule['source_path']),
            ));
            return;
        }

        $store->disable((string) $schedule['id']);
        $io->success("Disabled: {$schedule['name']}");
    }

    private function handleDelete(SymfonyStyle $io, ScheduleStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /schedules delete <name|id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $stats = $store->getStats();
            if ($stats['total'] === 0) {
                $io->info('No schedules to delete.');
                return;
            }
            if (!$io->confirm("Permanently delete all {$stats['total']} schedule(s)? This cannot be undone.", false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            $count = $store->deleteAll();
            $io->success("Deleted {$count} schedule(s).");
            return;
        }

        $schedule = $this->resolveByIdOrName($store, $target);
        if ($schedule === null) {
            $io->error("No schedule found matching '{$target}'.");
            return;
        }

        if ($this->isFilesystemSchedule($schedule)) {
            $io->warning(sprintf(
                "Schedule '%s' is defined by a filesystem file (%s). Delete the JSON file to remove it.",
                $schedule['name'],
                basename((string) $schedule['source_path']),
            ));
            return;
        }

        $store->delete((string) $schedule['id']);
        $io->success("Deleted: {$schedule['name']}");
    }

    private function handleTrigger(SymfonyStyle $io, ScheduleStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /schedules trigger <name|id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $enabled = $store->list(enabled: true);
            if ($enabled === []) {
                $io->info('No enabled schedules to trigger.');
                return;
            }
            if (!$io->confirm(sprintf('Force-trigger all %d enabled schedule(s) on next API tick?', count($enabled)), false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            $now = Clock::nowUtc();
            $count = 0;
            foreach ($enabled as $s) {
                $store->forceNextRun((string) $s['id'], $now);
                $count++;
            }
            $io->success("{$count} schedule(s) will fire on the next API scheduler tick (within 60 seconds).");
            return;
        }

        $schedule = $this->resolveByIdOrName($store, $target);
        if ($schedule === null) {
            $io->error("No schedule found matching '{$target}'.");
            return;
        }

        if ($this->isFilesystemSchedule($schedule)) {
            $io->warning(sprintf(
                "Schedule '%s' is defined by a filesystem file (%s). Trigger it by changing the source definition or using the HTTP API's discovery surface to inspect it.",
                $schedule['name'],
                basename((string) $schedule['source_path']),
            ));
            return;
        }

        $now = Clock::nowUtc();
        $store->forceNextRun((string) $schedule['id'], $now);
        $io->success("Schedule '{$schedule['name']}' will fire on the next API scheduler tick (within 60 seconds).");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveByIdOrName(ScheduleStore $store, string $idOrName): ?array
    {
        $schedule = $store->get($idOrName);
        if ($schedule !== null) {
            return $schedule;
        }

        return $store->getByName($idOrName);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function isFilesystemSchedule(array $schedule): bool
    {
        return ($schedule['source'] ?? 'system') === ScheduleStore::SOURCE_FILESYSTEM;
    }
}
