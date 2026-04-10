<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /schedules and all subcommands (enable, disable, delete, trigger).
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
            'enable' => $this->handleEnable($io, $scheduleStore, $target),
            'disable' => $this->handleDisable($io, $scheduleStore, $target),
            'delete' => $this->handleDelete($io, $scheduleStore, $target),
            'trigger' => $this->handleTrigger($io, $scheduleStore, $target),
            default => $this->handleList($io, $scheduleStore),
        };
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
            $now = gmdate('Y-m-d\TH:i:s\Z');
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

        $now = gmdate('Y-m-d\TH:i:s\Z');
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
