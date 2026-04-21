<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Renderer\ProgressBar;
use CoquiBot\Coqui\Renderer\ProgressBarSegment;
use CoquiBot\Coqui\Renderer\Sparkline;
use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\LoopStore;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive dashboard screen for /loops.
 *
 * Displays all loops with navigable selection, sparkline charts per iteration,
 * progress bars, current activity, and config summary. Supports inline cancel
 * and delete with keyboard shortcuts.
 */
final class LoopDashboardScreen implements ScreenInterface
{
    private const int LINES_PER_LOOP = 4;

    /** @var list<array<string, mixed>> */
    private array $loops = [];

    /** @var array<string, array{loop: array<string, mixed>, iteration: array<string, mixed>|null, stages: list<array<string, mixed>>}> */
    private array $loopStates = [];

    /** @var array<string, list<array{iteration: int, duration_seconds: float, stage_count: int, completed_stages: int}>> */
    private array $iterationTimings = [];

    private int $selectedIndex = 0;
    private int $scrollOffset = 0;
    private string $dataHash = '';

    private ?string $confirmAction = null;
    private ?string $confirmLoopId = null;

    /**
     * @param array<string, mixed> $config Runtime config values for display.
     */
    public function __construct(
        private readonly LoopStore $loopStore,
        private readonly array $config = [],
    ) {
        $this->refreshData();
    }

    public function title(): string
    {
        return 'Loops';
    }

    public function render(OutputInterface $output, int $width, int $height): void
    {
        $activeCount = 0;
        foreach ($this->loops as $loop) {
            if (in_array($loop['status'], ['running', 'paused'], true)) {
                $activeCount++;
            }
        }
        $totalCount = count($this->loops);

        $headerLines = $this->buildHeaderLines($width, $activeCount, $totalCount);
        $footerLines = $this->buildFooterLines($width);
        $contentHeight = max(1, $height - count($headerLines) - count($footerLines));
        $contentWidth = max(24, $width);
        $contentLines = $this->buildContentLines($contentWidth, $contentHeight);

        $shell = new ScreenShell(
            contentLines: $contentLines,
            header: new ShellRegion($headerLines, collapsePriority: 10),
            footer: new ShellRegion($footerLines, collapsePriority: 40),
            contentMinWidth: 28,
            contentMinHeight: 4,
        );

        foreach ($shell->render($width, $height) as $line) {
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
            KeyEvent::ARROW_UP => $this->moveSelection(-1),
            KeyEvent::ARROW_DOWN => $this->moveSelection(1),
            KeyEvent::ENTER => $this->enterDetail(),
            KeyEvent::BACKSPACE => $this->requestCancel(),
            KeyEvent::DELETE => $this->requestDelete(),
            KeyEvent::ESC => ScreenAction::exit(),
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
        $this->loops = $this->loopStore->listLoops();

        // Refresh state and timings for active loops + selected loop
        $this->loopStates = [];
        $this->iterationTimings = [];

        foreach ($this->loops as $loop) {
            $id = $loop['id'];
            if (in_array($loop['status'], ['running', 'paused'], true)) {
                $state = $this->loopStore->getCurrentState($id);
                if ($state !== null) {
                    $this->loopStates[$id] = $state;
                }
            }
            $this->iterationTimings[$id] = $this->loopStore->getIterationTimings($id);
        }

        // Clamp selection if loops were removed
        if ($this->loops !== []) {
            $this->selectedIndex = min($this->selectedIndex, count($this->loops) - 1);
        } else {
            $this->selectedIndex = 0;
        }

        $this->dataHash = $this->computeHash();
    }

    private function computeHash(): string
    {
        $parts = [];
        foreach ($this->loops as $loop) {
            $parts[] = $loop['id'] . ':' . $loop['status'] . ':' . $loop['current_iteration'] . ':' . $loop['current_stage'] . ':' . ($loop['last_activity_at'] ?? '');
        }

        return md5(implode('|', $parts));
    }

    /**
     * @return list<string>
     */
    private function buildHeaderLines(int $width, int $activeCount, int $totalCount): array
    {
        $title = sprintf('  <fg=white;options=bold>Loops</> <fg=gray>(%d active / %d total)</>', $activeCount, $totalCount);

        return [
            '',
            $title,
            '',
            $this->buildConfigLine($width),
        ];
    }

    private function buildConfigLine(int $width): string
    {
        $maxIter = $this->config['maxIterations'] ?? CoquiDefaults::MAX_ITERATIONS;
        $budgetExit = $this->config['budgetExitThreshold'] ?? CoquiDefaults::BUDGET_EXIT_THRESHOLD;
        $autoSummarize = $this->config['autoSummarizeThreshold'] ?? CoquiDefaults::AUTO_SUMMARIZE_THRESHOLD;
        $keepRecent = $this->config['autoSummarizeKeepRecent'] ?? CoquiDefaults::AUTO_SUMMARIZE_KEEP_RECENT;

        if ($width < 88) {
            return sprintf(
                '  <fg=gray>Config:</> Max <fg=white>%d</> │ Exit <fg=white>%s%%</> │ Sum <fg=white>%s%%</> │ Keep <fg=white>%d</>',
                $maxIter,
                number_format((float) $budgetExit, 0),
                number_format((float) $autoSummarize, 0),
                $keepRecent,
            );
        }

        return sprintf(
            '  <fg=gray>Config:</> Max Iterations <fg=white>%d</> │ Budget Exit <fg=white>%s%%</> │ Auto-Summarize <fg=white>%s%%</> │ Keep Recent <fg=white>%d</>',
            $maxIter,
            number_format((float) $budgetExit, 0),
            number_format((float) $autoSummarize, 0),
            $keepRecent,
        );
    }

    /**
     * @return list<string>
     */
    private function buildContentLines(int $width, int $contentHeight): array
    {
        if ($this->loops === []) {
            return [
                '  <fg=gray>No loops found. Start one via the agent (loop_start) or /loops start.</>',
            ];
        }

        $reservedLines = 2;
        $maxVisible = max(1, intdiv(max(1, $contentHeight - $reservedLines), self::LINES_PER_LOOP));
        $this->adjustScrollWindow($maxVisible);

        $lines = [];
        if ($this->scrollOffset > 0) {
            $lines[] = sprintf('  <fg=gray>↑ %d more above</>', $this->scrollOffset);
        }

        $visibleEnd = min($this->scrollOffset + $maxVisible, count($this->loops));
        for ($i = $this->scrollOffset; $i < $visibleEnd; $i++) {
            foreach ($this->renderLoopRowLines($this->loops[$i], $i === $this->selectedIndex, $width) as $line) {
                $lines[] = $line;
            }
        }

        $remaining = count($this->loops) - $visibleEnd;
        if ($remaining > 0) {
            $lines[] = sprintf('  <fg=gray>↓ %d more below</>', $remaining);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $loop
     * @return list<string>
     */
    private function renderLoopRowLines(array $loop, bool $selected, int $width): array
    {
        $id = $loop['id'];
        $prefix = $selected ? '<fg=white;options=bold>></> ' : '  ';

        // Status icon
        $statusIcon = match ($loop['status']) {
            'running' => '<fg=green>●</>',
            'paused' => '<fg=yellow>◉</>',
            'completed' => '<fg=cyan>✓</>',
            'failed' => '<fg=red>✗</>',
            'cancelled' => '<fg=gray>⊘</>',
            default => '<fg=gray>?</>',
        };

        // Iteration progress
        $maxIter = $loop['max_iterations'] > 0 ? (string) $loop['max_iterations'] : '∞';
        $iterLabel = sprintf('%d/%s', $loop['current_iteration'], $maxIter);

        // Sparkline from iteration timings
        $timings = $this->iterationTimings[$id] ?? [];
        $sparkValues = array_map(
            static fn(array $t): float => $t['duration_seconds'],
            $timings,
        );
        $sparkWidth = $width >= 78 ? 8 : 0;
        $sparkline = $sparkWidth > 0 ? Sparkline::render($sparkValues, 'fg=cyan', $sparkWidth) : '';

        // Progress bar — completed stages / total estimated stages
        $barWidth = match (true) {
            $width >= 96 => 20,
            $width >= 80 => 14,
            $width >= 68 => 10,
            default => 0,
        };
        $progressBar = $this->buildProgressBar($loop, $timings, $barWidth);

        // Line 1: status + definition + iteration + sparkline + progress bar
        $defWidth = $width >= 80 ? 12 : ($width >= 64 ? 10 : 8);
        $defName = str_pad($this->truncatePlain((string) $loop['definition_name'], $defWidth), $defWidth);
        $line1Parts = [$prefix, $statusIcon, ' ', $defName, ' ', str_pad($iterLabel, 6)];
        if ($sparkline !== '') {
            $line1Parts[] = ' ' . $sparkline;
        }
        if ($progressBar !== '') {
            $line1Parts[] = '  ' . $progressBar;
        }
        $line1 = implode('', $line1Parts);

        // Line 2: goal (truncated)
        $goalMaxWidth = max(12, $width - 8);
        $goal = $this->truncatePlain((string) $loop['goal'], $goalMaxWidth);
        $goalColor = $selected ? 'fg=white' : 'fg=gray';
        $line2 = sprintf('    <' . $goalColor . '>%s</>', $goal);

        // Line 3: current activity + elapsed time
        $activity = $this->buildActivityLine($loop);
        $elapsed = $this->formatElapsed($loop);
        $elapsedStr = $elapsed !== '' ? sprintf('  <fg=gray>⏱ %s</>', $elapsed) : '';
        $line3 = sprintf('    %s%s', $activity, $elapsedStr);

        return [$line1, $line2, $line3, ''];
    }

    /**
     * @param array<string, mixed> $loop
     * @param list<array{iteration: int, duration_seconds: float, stage_count: int, completed_stages: int}> $timings
     */
    private function buildProgressBar(array $loop, array $timings, int $barWidth): string
    {
        if ($barWidth <= 0) {
            return '';
        }

        // Calculate total possible stages and completed stages
        $maxIterations = $loop['max_iterations'] > 0 ? (int) $loop['max_iterations'] : 10;

        // Get stages per iteration from configuration
        $config = json_decode((string) ($loop['configuration'] ?? '{}'), true);
        $stagesPerIteration = is_array($config) ? count($config['roles'] ?? []) : 3;
        if ($stagesPerIteration < 1) {
            $stagesPerIteration = 3;
        }

        $totalStages = $maxIterations * $stagesPerIteration;
        $completedStages = 0;
        foreach ($timings as $t) {
            $completedStages += $t['completed_stages'];
        }

        // Add running stage progress from current state
        $state = $this->loopStates[$loop['id']] ?? null;
        if ($state !== null && $state['stages'] !== []) {
            foreach ($state['stages'] as $stage) {
                if ($stage['status'] === 'running') {
                    // Count running as 0.5 for visual progress
                    $completedStages += 0.5;
                }
            }
        }

        $completedColor = match ($loop['status']) {
            'completed' => 'fg=cyan',
            'failed' => 'fg=red',
            'cancelled' => 'fg=gray',
            'paused' => 'fg=yellow',
            default => 'fg=green',
        };

        $bar = new ProgressBar($barWidth);

        return $bar->build(
            total: $totalStages,
            segments: [new ProgressBarSegment('Done', $completedStages, $completedColor)],
            emptyStyle: 'fg=#444444',
            showPercent: true,
            label: '',
        );
    }

    /**
     * @param array<string, mixed> $loop
     */
    private function buildActivityLine(array $loop): string
    {
        $id = $loop['id'];
        $state = $this->loopStates[$id] ?? null;

        if ($loop['status'] === 'completed') {
            return '<fg=cyan>Done</>';
        }

        if ($loop['status'] === 'failed') {
            return '<fg=red>Failed</>';
        }

        if ($loop['status'] === 'cancelled') {
            return '<fg=gray>Cancelled</>';
        }

        if ($loop['status'] === 'paused') {
            return '<fg=yellow>Paused</>';
        }

        if ($state === null || $state['stages'] === []) {
            return '<fg=gray>Initializing...</>';
        }

        // Find the running or most recent stage
        $currentStage = null;
        foreach ($state['stages'] as $stage) {
            if ($stage['status'] === 'running') {
                $currentStage = $stage;
                break;
            }
        }
        if ($currentStage === null) {
            // Use the last stage
            $currentStage = end($state['stages']) ?: null;
        }

        if ($currentStage === null) {
            return '<fg=gray>Waiting...</>';
        }

        $role = $currentStage['role'];
        $metadata = null;
        if (is_string($currentStage['metadata'] ?? null) && $currentStage['metadata'] !== '') {
            $metadata = json_decode($currentStage['metadata'], true);
        }

        // Try to extract tool call info from metadata
        $toolInfo = '';
        if (is_array($metadata)) {
            $toolName = $metadata['last_tool'] ?? $metadata['tool_name'] ?? null;
            if (is_string($toolName)) {
                $toolArgs = $metadata['tool_args'] ?? '';
                if (is_string($toolArgs) && $toolArgs !== '') {
                    $toolArgs = mb_strlen($toolArgs) > 40 ? mb_substr($toolArgs, 0, 37) . '...' : $toolArgs;
                    $toolInfo = sprintf(': <fg=yellow>%s</>(<fg=gray>%s</>)', $toolName, $toolArgs);
                } else {
                    $toolInfo = sprintf(': <fg=yellow>%s</>', $toolName);
                }
            }
        }

        $statusIcon = $currentStage['status'] === 'running' ? '▸' : '·';

        return sprintf('<fg=gray>%s</> <fg=white>%s</>%s', $statusIcon, $role, $toolInfo);
    }

    /**
     * @param array<string, mixed> $loop
     */
    private function formatElapsed(array $loop): string
    {
        $startedAt = $loop['started_at'] ?? null;
        if (!is_string($startedAt) || $startedAt === '') {
            return '';
        }

        try {
            $start = new \DateTimeImmutable($startedAt);
            $now = new \DateTimeImmutable('now');
            $seconds = max(0, $now->getTimestamp() - $start->getTimestamp());

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
            return '';
        }
    }

    /**
     * @return list<string>
     */
    private function buildFooterLines(int $width): array
    {
        $lines = [];

        if ($this->confirmAction !== null) {
            $label = $this->confirmAction === 'cancel' ? 'Cancel' : 'Delete';
            $shortId = $this->confirmLoopId !== null ? substr($this->confirmLoopId, 0, 8) : '?';
            $lines[] = sprintf(
                '  <fg=yellow;options=bold>%s loop %s? Press y to confirm, any other key to abort.</>',
                $label,
                $shortId,
            );
        }

        $lines[] = $width < 88
            ? '  <fg=gray>↑↓</> Move  <fg=gray>⏎</> Open  <fg=gray>⌫</> Cancel  <fg=gray>ESC</> Exit'
            : '  <fg=gray>↑↓</> Navigate  <fg=gray>⏎</> Details  <fg=gray>⌫</> Cancel  <fg=gray>DEL</> Delete  <fg=gray>ESC</> Exit';

        return $lines;
    }

    private function truncatePlain(string $value, int $maxWidth): string
    {
        if ($maxWidth <= 0 || mb_strwidth($value) <= $maxWidth) {
            return $value;
        }

        if ($maxWidth <= 3) {
            return mb_strimwidth($value, 0, $maxWidth, '');
        }

        return mb_strimwidth($value, 0, $maxWidth, '...');
    }

    private function moveSelection(int $delta): ?ScreenAction
    {
        if ($this->loops === []) {
            return ScreenAction::refresh();
        }

        $newIndex = $this->selectedIndex + $delta;
        $newIndex = max(0, min(count($this->loops) - 1, $newIndex));

        if ($newIndex === $this->selectedIndex) {
            return ScreenAction::refresh();
        }

        $this->selectedIndex = $newIndex;

        return null; // local state changed → re-render
    }

    private function adjustScrollWindow(int $maxVisible): void
    {
        // Ensure selected index is within the visible window
        if ($this->selectedIndex < $this->scrollOffset) {
            $this->scrollOffset = $this->selectedIndex;
        } elseif ($this->selectedIndex >= $this->scrollOffset + $maxVisible) {
            $this->scrollOffset = $this->selectedIndex - $maxVisible + 1;
        }
        $this->scrollOffset = max(0, $this->scrollOffset);
    }

    private function enterDetail(): ScreenAction
    {
        if ($this->loops === [] || !isset($this->loops[$this->selectedIndex])) {
            return ScreenAction::refresh();
        }

        $loop = $this->loops[$this->selectedIndex];
        $detailScreen = new LoopDetailScreen($this->loopStore, $loop['id']);

        return ScreenAction::push($detailScreen);
    }

    private function requestCancel(): ?ScreenAction
    {
        if ($this->loops === [] || !isset($this->loops[$this->selectedIndex])) {
            return ScreenAction::refresh();
        }

        $loop = $this->loops[$this->selectedIndex];

        if (!in_array($loop['status'], ['running', 'paused'], true)) {
            return ScreenAction::refresh();
        }

        $this->confirmAction = 'cancel';
        $this->confirmLoopId = $loop['id'];

        return null; // re-render to show confirmation
    }

    private function requestDelete(): ?ScreenAction
    {
        if ($this->loops === [] || !isset($this->loops[$this->selectedIndex])) {
            return ScreenAction::refresh();
        }

        $loop = $this->loops[$this->selectedIndex];

        // Only allow delete on terminal states
        if (!in_array($loop['status'], ['completed', 'failed', 'cancelled'], true)) {
            return ScreenAction::refresh();
        }

        $this->confirmAction = 'delete';
        $this->confirmLoopId = $loop['id'];

        return null; // re-render to show confirmation
    }

    /**
     * @return null
     */
    private function handleConfirmation(KeyEvent $key): mixed
    {
        $action = $this->confirmAction;
        $loopId = $this->confirmLoopId;

        // Clear confirmation state regardless
        $this->confirmAction = null;
        $this->confirmLoopId = null;

        if ($key->type !== KeyEvent::CHAR || $key->char !== 'y') {
            return null; // re-render without confirmation
        }

        if ($loopId === null) {
            return null;
        }

        if ($action === 'cancel') {
            $this->loopStore->updateLoopStatus($loopId, 'cancelled');
        } elseif ($action === 'delete') {
            $this->loopStore->deleteLoop($loopId);
        }

        $this->refreshData();

        return null; // re-render with updated data
    }
}
