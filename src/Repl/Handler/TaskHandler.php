<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\JsonHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /tasks, /task, /task-cancel slash commands.
 */
final class TaskHandler
{
    public function __construct(
        private readonly SessionStorage $storage,
    ) {}

    public function listTasks(SymfonyStyle $io, string $statusFilter = ''): void
    {
        $status = trim($statusFilter) !== '' ? trim($statusFilter) : null;
        $tasks = $this->storage->listTasks($status, 20);

        if (empty($tasks)) {
            $io->info($status !== null ? "No tasks with status '{$status}'." : 'No background tasks found.');
            return;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $rows[] = [
                substr($task['id'], 0, 8) . '...',
                $task['status'],
                $task['title'] ?? '(untitled)',
                $task['role'],
                $task['created_at'],
            ];
        }

        $io->table(['ID', 'Status', 'Title', 'Role', 'Created'], $rows);

        $counts = $this->storage->getTaskCounts();
        $parts = [];
        foreach ($counts as $s => $c) {
            $parts[] = "{$s}: {$c}";
        }
        if (!empty($parts)) {
            $io->text('<fg=gray>' . implode(' | ', $parts) . '</>');
        }
    }

    public function taskStatus(SymfonyStyle $io, string $taskIdPrefix = ''): void
    {
        if ($taskIdPrefix === '') {
            $io->error('Usage: /task <task-id>');
            return;
        }

        $task = $this->resolveTaskByPrefix($taskIdPrefix);

        if ($task === null) {
            $io->error("Task not found: {$taskIdPrefix}");
            return;
        }

        $io->section('Task: ' . ($task['title'] ?? $task['id']));
        $io->definitionList(
            ['ID' => $task['id']],
            ['Status' => $task['status']],
            ['Role' => $task['role']],
            ['Max Iterations' => $task['max_iterations']],
            ['Created' => $task['created_at']],
            ['Started' => $task['started_at'] ?? '(not started)'],
            ['Completed' => $task['completed_at'] ?? '(not completed)'],
        );

        $metadata = JsonHelper::decodeJsonObject($task['metadata'] ?? null);
        if ($metadata !== null) {
            $io->newLine();
            $io->text('<fg=cyan>Structured Metadata:</>');
            $io->writeln(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        if ($task['result'] !== null) {
            $result = $task['result'];
            if (mb_strlen($result) > 500) {
                $result = mb_substr($result, 0, 500) . '... (' . mb_strlen($task['result']) . ' chars total)';
            }
            $io->text('<fg=green>Result:</> ' . $result);
        }

        if ($task['error'] !== null) {
            $io->text('<fg=red>Error:</> ' . $task['error']);
        }

        $events = $this->storage->getTaskEvents($task['id'], limit: 10);
        if (!empty($events)) {
            $io->newLine();
            $io->text('<fg=gray>Recent events:</>');
            foreach ($events as $event) {
                $data = json_decode($event['data'] ?? '{}', true);
                $detail = match ($event['event_type']) {
                    'tool_call' => $data['tool'] ?? '',
                    'tool_result' => mb_substr($data['content'] ?? '', 0, 80),
                    'iteration' => 'iteration ' . ($data['number'] ?? '?'),
                    default => json_encode($data, JSON_UNESCAPED_SLASHES) ?: '',
                };
                $io->writeln(sprintf(
                    '  <fg=gray>%s</> <fg=cyan>%s</> %s',
                    $event['created_at'],
                    $event['event_type'],
                    mb_strlen($detail) > 100 ? mb_substr($detail, 0, 100) . '...' : $detail,
                ));
            }
        }
    }

    public function taskCancel(SymfonyStyle $io, string $taskIdPrefix = ''): void
    {
        if ($taskIdPrefix === '') {
            $io->error('Usage: /task-cancel <task-id>');
            return;
        }

        $task = $this->resolveTaskByPrefix($taskIdPrefix);

        if ($task === null) {
            $io->error("Task not found: {$taskIdPrefix}");
            return;
        }

        if (in_array($task['status'], ['completed', 'failed', 'cancelled'], true)) {
            $io->warning(sprintf('Task is already in terminal state "%s".', $task['status']));
            return;
        }

        if ($task['status'] === 'pending') {
            $this->storage->updateTaskStatus($task['id'], 'cancelled');
            $this->storage->appendTaskEvent($task['id'], 'cancelled', [
                'message' => 'Cancelled from REPL',
            ]);
            $io->success('Task cancelled.');
            return;
        }

        $this->storage->updateTaskStatus($task['id'], 'cancelling');
        $this->storage->appendTaskEvent($task['id'], 'cancel_requested', [
            'message' => 'Cancellation requested from REPL',
        ]);
        $io->success('Cancellation requested. The task will stop after its current iteration.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTaskByPrefix(string $prefix): ?array
    {
        $task = $this->storage->getTask($prefix);
        if ($task !== null) {
            return $task;
        }

        $tasks = $this->storage->listTasks(limit: 100);
        $matches = array_filter($tasks, fn(array $t): bool => str_starts_with($t['id'], $prefix));

        if (count($matches) === 1) {
            $match = reset($matches);
            return $this->storage->getTask($match['id']);
        }

        return null;
    }

}
