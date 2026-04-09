<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

use CoquiBot\Coqui\Renderer\ProgressBar;
use CoquiBot\Coqui\Renderer\ProgressBarSegment;
use CoquiBot\Coqui\Renderer\Sparkline;
use CoquiBot\Coqui\Storage\LoopStore;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Detail screen for a single loop, shown when pressing Enter on the dashboard.
 *
 * Displays full loop metadata, configuration, iteration history with sparkline,
 * current stage details with progress bar, and stage result summaries.
 */
final class LoopDetailScreen implements ScreenInterface
{
    /** @var array{loop: array<string, mixed>, iteration: array<string, mixed>|null, stages: list<array<string, mixed>>}|null */
    private ?array $state = null;

    /** @var list<array<string, mixed>> */
    private array $allIterations = [];

    /** @var list<array{iteration: int, duration_seconds: float, stage_count: int, completed_stages: int}> */
    private array $timings = [];

    private int $scrollOffset = 0;
    private string $dataHash = '';

    private ?string $confirmAction = null;

    public function __construct(
        private readonly LoopStore $loopStore,
        private readonly string $loopId,
    ) {
        $this->refreshData();
    }

    public function title(): string
    {
        $defName = $this->state['loop']['definition_name'] ?? 'Loop';

        return sprintf('%s (%s)', $defName, substr($this->loopId, 0, 8));
    }

    public function render(OutputInterface $output, int $width, int $height): void
    {
        if ($this->state === null) {
            $output->writeln('');
            $output->writeln('  <fg=red>Loop not found.</>');
            $output->writeln('');
            $output->writeln('  <fg=gray>ESC</> Back');
            return;
        }

        $loop = $this->state['loop'];
        $lines = [];

        // Header
        $lines[] = '';
        $lines[] = sprintf(
            '  <fg=white;options=bold>Loop: %s</> <fg=gray>(%s)</>',
            $loop['definition_name'],
            $loop['id'],
        );
        $lines[] = '';

        // Status + metadata grid
        $statusIcon = match ($loop['status']) {
            'running' => '<fg=green>● running</>',
            'paused' => '<fg=yellow>◉ paused</>',
            'completed' => '<fg=cyan>✓ completed</>',
            'failed' => '<fg=red>✗ failed</>',
            'cancelled' => '<fg=gray>⊘ cancelled</>',
            default => $loop['status'],
        };

        $maxIter = $loop['max_iterations'] > 0 ? (string) $loop['max_iterations'] : '∞';

        $lines[] = sprintf('  <fg=gray>Status:</>  %s', $statusIcon);
        $lines[] = sprintf('  <fg=gray>Goal:</>    %s', $loop['goal']);
        $lines[] = sprintf(
            '  <fg=gray>Iteration:</> %d/%s                    <fg=gray>Started:</> %s',
            $loop['current_iteration'],
            $maxIter,
            $this->formatTimeSince($loop['started_at']),
        );
        if ($loop['completed_at'] !== null) {
            $lines[] = sprintf('  <fg=gray>Completed:</> %s', $this->formatTimeSince($loop['completed_at']));
        }
        $lines[] = '';

        // Configuration from loop snapshot
        $config = json_decode((string) ($loop['configuration'] ?? '{}'), true);
        if (is_array($config)) {
            $termType = 'unknown';
            if (isset($config['termination_condition']['type'])) {
                $termType = (string) $config['termination_condition']['type'];
            }
            $roles = [];
            if (is_array($config['roles'] ?? null)) {
                foreach ($config['roles'] as $role) {
                    if (is_array($role) && isset($role['role'])) {
                        $roles[] = (string) $role['role'];
                    }
                }
            }
            $roleChain = $roles !== [] ? implode(' → ', $roles) : '-';

            $lines[] = sprintf(
                '  <fg=gray>Config:</>  Max Iterations: <fg=white>%s</>  │  Termination: <fg=white>%s</>  │  Roles: <fg=white>%s</>',
                $maxIter,
                $termType,
                $roleChain,
            );
            $lines[] = '';
        }

        // Sparkline for iterations
        $sparkValues = array_map(
            static fn(array $t): float => $t['duration_seconds'],
            $this->timings,
        );
        $sparkline = Sparkline::render($sparkValues, 'fg=cyan', 16);

        $lines[] = sprintf('  <fg=white;options=bold>Iterations</>  %s', $sparkline);
        $lines[] = '';

        // Iteration table
        if ($this->allIterations !== []) {
            $lines[] = sprintf(
                '  <fg=gray>%-4s  %-12s  %-10s  %-40s  %-8s</>',
                '#',
                'Status',
                'Duration',
                'Outcome',
                'Stages',
            );
            $lines[] = '  ' . str_repeat('─', min($width - 4, 80));

            foreach ($this->allIterations as $iteration) {
                $iterStatus = match ($iteration['status']) {
                    'running' => '<fg=green>● running</>',
                    'completed' => '<fg=cyan>✓ complete</>',
                    'failed' => '<fg=red>✗ failed</>',
                    'needs_rework' => '<fg=yellow>⟳ rework</>',
                    'pending' => '<fg=gray>· pending</>',
                    default => $iteration['status'],
                };

                $duration = $this->formatIterationDuration($iteration);
                $outcome = $iteration['outcome_summary'] ?? '-';
                if (mb_strlen($outcome) > 40) {
                    $outcome = mb_substr($outcome, 0, 37) . '...';
                }

                // Get stage counts from timings
                $timing = $this->findTiming((int) $iteration['iteration_number']);
                $stageLabel = $timing !== null
                    ? sprintf('%d/%d', $timing['completed_stages'], $timing['stage_count'])
                    : '-';

                // Use fixed-width layout (status has ANSI — pad the raw text part)
                $lines[] = sprintf(
                    '  %-4s  %-12s  %-10s  %-40s  %-8s',
                    $iteration['iteration_number'],
                    $iterStatus,
                    $duration,
                    '<fg=gray>' . $outcome . '</>',
                    $stageLabel,
                );
            }
            $lines[] = '';
        }

        // Current stage details
        $stages = $this->state['stages'];
        if ($stages !== []) {
            $currentStage = null;
            foreach ($stages as $stage) {
                if ($stage['status'] === 'running') {
                    $currentStage = $stage;
                    break;
                }
            }

            // If no running stage, show the last completed one
            if ($currentStage === null) {
                $completed = array_filter($stages, static fn(array $s): bool => $s['status'] === 'completed');
                $currentStage = $completed !== [] ? end($completed) : null;
            }

            if ($currentStage !== null) {
                $totalStages = count($stages);
                $stageIdx = (int) $currentStage['stage_index'];

                $lines[] = sprintf(
                    '  <fg=white;options=bold>Current Stage (%d of %d): %s</>',
                    $stageIdx + 1,
                    $totalStages,
                    $currentStage['role'],
                );

                // Stage progress bar
                $completedCount = 0;
                foreach ($stages as $s) {
                    if ($s['status'] === 'completed') {
                        $completedCount++;
                    } elseif ($s['status'] === 'running') {
                        $completedCount += 0.5;
                    }
                }

                // Determine stage color — PHPStan narrows $currentStage['status']
                // to 'running'|'completed' from the filter logic above, but the match
                // is intentionally exhaustive for readability and future-proofing.
                $stageColor = match ($currentStage['status'] ?? 'pending') { // @phpstan-ignore nullCoalesce.offset
                    'completed' => 'fg=cyan',
                    'running' => 'fg=green', // @phpstan-ignore match.alwaysTrue
                    'failed' => 'fg=red',
                    default => 'fg=gray',
                };

                $bar = new ProgressBar(30);
                $lines[] = $bar->build(
                    total: $totalStages,
                    segments: [new ProgressBarSegment('Progress', $completedCount, $stageColor)],
                    emptyStyle: 'fg=#444444',
                    showPercent: true,
                );

                // Stage result summary
                $summary = $currentStage['result_summary'] ?? null;
                if (is_string($summary) && $summary !== '') {
                    if (mb_strlen($summary) > 100) {
                        $summary = mb_substr($summary, 0, 97) . '...';
                    }
                    $lines[] = sprintf('  <fg=gray>Result: %s</>', $summary);
                }

                // Stage metadata
                $metadata = null;
                if (is_string($currentStage['metadata'] ?? null) && $currentStage['metadata'] !== '') {
                    $metadata = json_decode($currentStage['metadata'], true);
                }
                if (is_array($metadata)) {
                    $toolName = $metadata['last_tool'] ?? $metadata['tool_name'] ?? null;
                    if (is_string($toolName)) {
                        $toolArgs = $metadata['tool_args'] ?? '';
                        if (is_string($toolArgs) && mb_strlen($toolArgs) > 60) {
                            $toolArgs = mb_substr($toolArgs, 0, 57) . '...';
                        }
                        $lines[] = sprintf('  <fg=gray>▸</> <fg=yellow>%s</>(<fg=gray>%s</>)', $toolName, $toolArgs);
                    }
                }

                $lines[] = '';
            }

            // All stages summary
            $lines[] = '  <fg=white;options=bold>Stages</>';
            foreach ($stages as $stage) {
                $icon = match ($stage['status']) {
                    'completed' => '<fg=cyan>✓</>',
                    'running' => '<fg=green>●</>',
                    'failed' => '<fg=red>✗</>',
                    'pending' => '<fg=gray>·</>',
                    default => ' ',
                };
                $stageSummary = $stage['result_summary'] ?? '-';
                if (mb_strlen($stageSummary) > 60) {
                    $stageSummary = mb_substr($stageSummary, 0, 57) . '...';
                }
                $lines[] = sprintf('  %s <fg=white>%d. %s</>  <fg=gray>%s</>', $icon, (int) $stage['stage_index'], $stage['role'], $stageSummary);
            }
            $lines[] = '';
        }

        // Confirmation prompt
        if ($this->confirmAction !== null) {
            $shortId = substr($this->loopId, 0, 8);
            $lines[] = sprintf(
                '  <fg=yellow;options=bold>Cancel loop %s? Press y to confirm, any other key to abort.</>',
                $shortId,
            );
        }

        // Footer
        $footerParts = ['<fg=gray>ESC</> Back', '<fg=gray>↑↓</> Scroll'];
        if (in_array($loop['status'], ['running', 'paused'], true)) {
            $footerParts[] = '<fg=gray>⌫</> Cancel';
        }
        $lines[] = '  ' . implode('  ', $footerParts);

        // Apply scroll offset and render visible lines
        $visibleHeight = $height - 1;
        $totalLines = count($lines);
        $maxScrollOffset = max(0, $totalLines - $visibleHeight);
        $this->scrollOffset = min($this->scrollOffset, $maxScrollOffset);

        $visibleLines = array_slice($lines, $this->scrollOffset, $visibleHeight);
        foreach ($visibleLines as $line) {
            $output->writeln($line);
        }
    }

    public function handleKey(KeyEvent $key): ?ScreenAction
    {
        // Handle inline confirmation
        if ($this->confirmAction !== null) {
            return $this->handleConfirmation($key);
        }

        return match ($key->type) {
            KeyEvent::ARROW_UP => $this->scroll(-3),
            KeyEvent::ARROW_DOWN => $this->scroll(3),
            KeyEvent::BACKSPACE => $this->requestCancel(),
            KeyEvent::ESC => ScreenAction::pop(),
            default => ScreenAction::refresh(),
        };
    }

    public function tick(): bool
    {
        $this->refreshData();
        $newHash = $this->computeHash();

        if ($newHash !== $this->dataHash) {
            $this->dataHash = $newHash;
            return true;
        }

        return false;
    }

    private function refreshData(): void
    {
        $this->state = $this->loopStore->getCurrentState($this->loopId);
        $this->allIterations = $this->loopStore->listIterations($this->loopId);
        $this->timings = $this->loopStore->getIterationTimings($this->loopId);
        $this->dataHash = $this->computeHash();
    }

    private function computeHash(): string
    {
        $loop = $this->state['loop'] ?? [];
        $parts = [
            $loop['status'] ?? '',
            (string) ($loop['current_iteration'] ?? 0),
            (string) ($loop['current_stage'] ?? 0),
            $loop['last_activity_at'] ?? '',
            (string) count($this->allIterations),
        ];

        // Include stage statuses
        foreach ($this->state['stages'] ?? [] as $stage) {
            $parts[] = $stage['status'] . ':' . ($stage['result_summary'] ?? '');
        }

        return md5(implode('|', $parts));
    }

    private function scroll(int $delta): ?ScreenAction
    {
        $newOffset = max(0, $this->scrollOffset + $delta);
        if ($newOffset === $this->scrollOffset) {
            return ScreenAction::refresh();
        }

        $this->scrollOffset = $newOffset;

        return null; // re-render
    }

    private function requestCancel(): ?ScreenAction
    {
        if ($this->state === null) {
            return ScreenAction::refresh();
        }

        $loop = $this->state['loop'];
        if (!in_array($loop['status'], ['running', 'paused'], true)) {
            return ScreenAction::refresh();
        }

        $this->confirmAction = 'cancel';

        return null; // re-render to show confirmation
    }

    /**
     * @return null
     */
    private function handleConfirmation(KeyEvent $key): mixed
    {
        $this->confirmAction = null;

        if ($key->type !== KeyEvent::CHAR || $key->char !== 'y') {
            return null; // re-render without confirmation
        }

        $this->loopStore->updateLoopStatus($this->loopId, 'cancelled');
        $this->refreshData();

        return null;
    }

    private function formatTimeSince(string $datetime): string
    {
        try {
            $then = new \DateTimeImmutable($datetime);
            $now = new \DateTimeImmutable('now');
            $seconds = max(0, $now->getTimestamp() - $then->getTimestamp());

            if ($seconds < 60) {
                return 'just now';
            }
            if ($seconds < 3600) {
                $m = intdiv($seconds, 60);
                return $m . 'm ago';
            }
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            return sprintf('%dh %dm ago', $h, $m);
        } catch (\Throwable) {
            return $datetime;
        }
    }

    /**
     * @param array<string, mixed> $iteration
     */
    private function formatIterationDuration(array $iteration): string
    {
        $startedAt = $iteration['started_at'] ?? null;
        if (!is_string($startedAt) || $startedAt === '') {
            return '-';
        }

        try {
            $start = new \DateTimeImmutable($startedAt);
            $end = $iteration['completed_at'] !== null
                ? new \DateTimeImmutable($iteration['completed_at'])
                : new \DateTimeImmutable('now');
            $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());

            if ($seconds < 60) {
                return $seconds . 's';
            }
            if ($seconds < 3600) {
                $m = intdiv($seconds, 60);
                $s = $seconds % 60;
                return sprintf('%dm %02ds', $m, $s);
            }
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            return sprintf('%dh %02dm', $h, $m);
        } catch (\Throwable) {
            return '-';
        }
    }

    /**
     * @return array{iteration: int, duration_seconds: float, stage_count: int, completed_stages: int}|null
     */
    private function findTiming(int $iterationNumber): ?array
    {
        foreach ($this->timings as $timing) {
            if ($timing['iteration'] === $iterationNumber) {
                return $timing;
            }
        }

        return null;
    }
}
