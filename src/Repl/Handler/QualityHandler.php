<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Agent\QualityAutomationStatusService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /quality status reporting for the autonomous quality loop.
 */
final readonly class QualityHandler
{
    public function __construct(
        private QualityAutomationStatusService $status,
    ) {}

    public function handle(SymfonyStyle $io): void
    {
        $summary = $this->status->summary();
        $counts = $summary['follow_ups']['counts'];

        $io->section('Quality Automation');
        $io->definitionList(
            ['Enabled' => $summary['enabled'] ? 'yes' : 'no'],
            ['Bootstrap Schedules' => $summary['bootstrap_schedules'] ? 'yes' : 'no'],
            ['Auto-trigger Learner' => $summary['auto_trigger_learner'] ? 'yes' : 'no'],
            ['Poor Score Threshold' => sprintf('%.3f', $summary['poor_evaluation_threshold'])],
            ['Timezone' => (string) $summary['timezone']],
        );

        $scheduleRows = [];
        foreach ($summary['schedules'] as $schedule) {
            $scheduleRows[] = [
                $schedule['kind'],
                $schedule['name'],
                $schedule['exists'] ? ($schedule['enabled'] ? 'enabled' : 'disabled') : 'missing',
                $schedule['next_run_at'] ?? '-',
                $schedule['last_status'] ?? '-',
                $schedule['last_task']['status'] ?? '-',
            ];
        }

        if ($scheduleRows !== []) {
            $io->table(['Kind', 'Schedule', 'State', 'Next Run', 'Last Status', 'Last Task'], $scheduleRows);
        } else {
            $io->text('<fg=gray>No quality schedules are available in this context.</>');
        }

        $io->text(sprintf(
            '<fg=gray>Linked follow-ups:</> %d | pending: %d | running: %d | cancelling: %d | completed: %d | failed: %d | cancelled: %d | missing: %d',
            $counts['linked'],
            $counts['pending'],
            $counts['running'],
            $counts['cancelling'],
            $counts['completed'],
            $counts['failed'],
            $counts['cancelled'],
            $counts['missing'],
        ));

        $activeRows = [];
        foreach ($summary['follow_ups']['active'] as $followUp) {
            $activeRows[] = [
                substr((string) $followUp['evaluation_id'], 0, 8) . '...',
                mb_substr((string) $followUp['session_title'], 0, 30),
                (string) $followUp['overall_grade'],
                sprintf('%.2f', $followUp['overall_score']),
                substr((string) $followUp['task_id'], 0, 8) . '...',
                (string) $followUp['task_status'],
                $followUp['task_created_at'] ?? '-',
            ];
        }

        if ($activeRows !== []) {
            $io->newLine();
            $io->table(['Eval', 'Session', 'Grade', 'Score', 'Task', 'Status', 'Queued'], $activeRows);
            return;
        }

        $recentRows = [];
        foreach ($summary['follow_ups']['recent'] as $followUp) {
            $recentRows[] = [
                substr((string) $followUp['evaluation_id'], 0, 8) . '...',
                mb_substr((string) $followUp['session_title'], 0, 30),
                (string) $followUp['overall_grade'],
                sprintf('%.2f', $followUp['overall_score']),
                substr((string) $followUp['task_id'], 0, 8) . '...',
                (string) $followUp['task_status'],
            ];
        }

        if ($recentRows === []) {
            $io->text('<fg=gray>No learner follow-up tasks have been linked yet.</>');
            return;
        }

        $io->newLine();
        $io->table(['Eval', 'Session', 'Grade', 'Score', 'Task', 'Status'], $recentRows);
    }
}
