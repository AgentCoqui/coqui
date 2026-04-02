<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Storage\TodoStore;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /todos and all subcommands (delete, complete, cancel, clear, status filter).
 */
final class TodoHandler
{
    public function __construct(
        private readonly ?TodoStore $todoStore,
    ) {}

    public function handle(SymfonyStyle $io, string $arg, string $sessionId): void
    {
        if ($this->todoStore === null) {
            $io->error('Todo system not initialized.');
            return;
        }

        $store = $this->todoStore;
        $trimmedArg = trim($arg);
        $argParts = $trimmedArg !== '' ? explode(' ', $trimmedArg, 2) : [];
        $action = strtolower($argParts[0] ?? '');
        $target = trim($argParts[1] ?? '');

        match ($action) {
            'delete' => $this->handleDelete($io, $store, $target, $sessionId),
            'complete' => $this->handleComplete($io, $store, $target, $sessionId),
            'cancel' => $this->handleCancel($io, $store, $target, $sessionId),
            'clear' => $this->handleClear($io, $store, $sessionId),
            default => $this->handleList($io, $store, $trimmedArg, $sessionId),
        };
    }

    private function handleList(SymfonyStyle $io, TodoStore $store, string $statusFilter, string $sessionId): void
    {
        $validStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];
        $status = $statusFilter !== '' && in_array($statusFilter, $validStatuses, true) ? $statusFilter : null;

        if ($statusFilter !== '' && $status === null) {
            $io->error("Unknown subcommand or status: '{$statusFilter}'. Use: delete, complete, cancel, clear, or a status (pending, in_progress, completed, cancelled).");
            return;
        }

        $todos = $store->list(
            sessionId: $sessionId,
            status: $status,
        );
        if (empty($todos)) {
            $io->info($status !== null ? "No todos with status '{$status}'." : 'No todos in this session.');
            return;
        }

        $stats = $store->getStats($sessionId);
        $total = $stats['total'];
        $completed = $stats['completed'];
        $pct = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $io->section("Todos ({$completed}/{$total} — {$pct}%)");

        $rows = [];
        foreach ($todos as $todo) {
            $icon = match ($todo['status']) {
                'completed' => '<fg=green>✅</>',
                'in_progress' => '<fg=yellow>🔲</>',
                'cancelled' => '<fg=red>❌</>',
                default => '☐',
            };
            $priority = match ($todo['priority']) {
                'high' => '<fg=red>high</>',
                'low' => '<fg=gray>low</>',
                default => 'med',
            };
            $rows[] = [
                $icon,
                $todo['id'],
                $todo['title'],
                $priority,
                $todo['created_by'] ?? '',
            ];
        }

        $io->table(['', 'ID', 'Title', 'Priority', 'Created By'], $rows);

        $parts = [];
        foreach (['pending', 'in_progress', 'completed', 'cancelled'] as $s) {
            if ($stats[$s] > 0) {
                $parts[] = "{$s}: {$stats[$s]}";
            }
        }
        if (!empty($parts)) {
            $io->text('<fg=gray>' . implode(' | ', $parts) . '</>');
        }
    }

    private function handleDelete(SymfonyStyle $io, TodoStore $store, string $target, string $sessionId): void
    {
        if ($target === '') {
            $io->error('Usage: /todos delete <id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $stats = $store->getStats($sessionId);
            if ($stats['total'] === 0) {
                $io->info('No todos to delete.');
                return;
            }
            if (!$io->confirm("Delete all {$stats['total']} todos in this session?", false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            $count = $store->deleteBySession($sessionId);
            $io->success("Deleted {$count} todo(s).");
            return;
        }

        $result = $store->findByPrefix($sessionId, $target);
        if ($result === null) {
            $io->error("No todo found matching '{$target}'.");
            return;
        }
        if ($result['ambiguous']) {
            $io->error("Ambiguous ID prefix '{$target}' — matches " . count($result['candidates']) . ' todos:');
            foreach ($result['candidates'] as $c) {
                $io->text("  {$c['id']}  {$c['title']}");
            }
            return;
        }

        $todo = $result['todo'];
        $store->delete($todo['id'], sessionId: $sessionId);
        $io->success("Deleted: {$todo['title']}");
    }

    private function handleComplete(SymfonyStyle $io, TodoStore $store, string $target, string $sessionId): void
    {
        if ($target === '') {
            $io->error('Usage: /todos complete <id|all>');
            return;
        }

        if (strtolower($target) === 'all') {
            $stats = $store->getStats($sessionId);
            $actionable = $stats['pending'] + $stats['in_progress'];
            if ($actionable === 0) {
                $io->info('No pending or in-progress todos to complete.');
                return;
            }
            if (!$io->confirm("Mark {$actionable} pending/in-progress todo(s) as completed?", false)) {
                $io->text('<fg=gray>Cancelled.</>');
                return;
            }
            $count = $store->completeAllBySession($sessionId, 'user');
            $io->success("Completed {$count} todo(s).");
            return;
        }

        $result = $store->findByPrefix($sessionId, $target);
        if ($result === null) {
            $io->error("No todo found matching '{$target}'.");
            return;
        }
        if ($result['ambiguous']) {
            $io->error("Ambiguous ID prefix '{$target}' — matches " . count($result['candidates']) . ' todos:');
            foreach ($result['candidates'] as $c) {
                $io->text("  {$c['id']}  {$c['title']}");
            }
            return;
        }

        $todo = $result['todo'];
        if ($todo['status'] === 'completed') {
            $io->info("Already completed: {$todo['title']}");
            return;
        }
        $store->complete($todo['id'], 'user', sessionId: $sessionId);
        $io->success("Completed: {$todo['title']}");
    }

    private function handleCancel(SymfonyStyle $io, TodoStore $store, string $target, string $sessionId): void
    {
        if ($target === '') {
            $io->error('Usage: /todos cancel <id>');
            return;
        }

        $result = $store->findByPrefix($sessionId, $target);
        if ($result === null) {
            $io->error("No todo found matching '{$target}'.");
            return;
        }
        if ($result['ambiguous']) {
            $io->error("Ambiguous ID prefix '{$target}' — matches " . count($result['candidates']) . ' todos:');
            foreach ($result['candidates'] as $c) {
                $io->text("  {$c['id']}  {$c['title']}");
            }
            return;
        }

        $todo = $result['todo'];
        if ($todo['status'] === 'cancelled') {
            $io->info("Already cancelled: {$todo['title']}");
            return;
        }
        $store->update($todo['id'], status: 'cancelled', sessionId: $sessionId);
        $io->success("Cancelled: {$todo['title']}");
    }

    private function handleClear(SymfonyStyle $io, TodoStore $store, string $sessionId): void
    {
        $stats = $store->getStats($sessionId);
        $clearable = $stats['completed'] + $stats['cancelled'];
        if ($clearable === 0) {
            $io->info('No completed or cancelled todos to clear.');
            return;
        }
        if (!$io->confirm("Remove {$clearable} completed/cancelled todo(s)?", false)) {
            $io->text('<fg=gray>Cancelled.</>');
            return;
        }
        $count = $store->deleteCompletedBySession($sessionId);
        $io->success("Cleared {$count} todo(s).");
    }
}
