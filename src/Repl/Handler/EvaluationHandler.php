<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /evaluations command.
 */
final class EvaluationHandler
{
    public function __construct(
        private readonly SessionStorage $storage,
    ) {}

    public function handle(SymfonyStyle $io, string $arg): void
    {
        $evaluationStore = new EvaluationStore($this->storage->getPdo());
        $grade = $arg !== '' ? strtoupper($arg) : null;

        if ($grade !== null && !in_array($grade, ['A', 'B', 'C', 'D', 'F'], true)) {
            $io->error("Invalid grade filter: {$arg}. Use A, B, C, D, or F.");
            return;
        }

        $evaluations = $evaluationStore->list(grade: $grade, limit: 20);

        if (empty($evaluations)) {
            $io->info('No evaluation reports found.' . ($grade !== null ? " (filtered by grade: {$grade})" : ''));
            return;
        }

        $stats = $evaluationStore->getStats();
        $dist = $stats['grade_distribution'];
        $io->section(sprintf(
            'Evaluations (%d total — A:%d B:%d C:%d D:%d F:%d — avg: %.2f)',
            $stats['total'],
            $dist['A'],
            $dist['B'],
            $dist['C'],
            $dist['D'],
            $dist['F'],
            $stats['avg_overall'],
        ));

        $rows = [];
        foreach ($evaluations as $e) {
            $gradeColor = match ($e['overall_grade']) {
                'A' => 'green',
                'B' => 'cyan',
                'C' => 'yellow',
                'D' => 'red',
                'F' => 'red',
                default => 'white',
            };
            $rows[] = [
                substr($e['session_id'], 0, 8) . '...',
                mb_substr($e['session_title'] ?? '(untitled)', 0, 30),
                "<fg={$gradeColor}>{$e['overall_grade']}</>",
                sprintf('%.2f', $e['score_completion']),
                sprintf('%.2f', $e['score_hallucination']),
                sprintf('%.2f', $e['score_efficiency']),
                sprintf('%.2f', $e['overall_score']),
                $e['created_at'],
            ];
        }

        $io->table(['Session', 'Title', 'Grade', 'Compl.', 'Halluc.', 'Effic.', 'Overall', 'Evaluated'], $rows);
    }
}
