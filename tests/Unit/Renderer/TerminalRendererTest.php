<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\BackgroundTaskSummary;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createBufferedIo(): array
{
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $output = new BufferedOutput();
    $io = new SymfonyStyle($input, $output);
    return [$io, $output];
}

function createResult(
    ?BackgroundTaskSummary $backgroundTasks = null,
    int $iterations = 3,
    int $durationMs = 5000,
    int $promptTokens = 100,
    int $completionTokens = 50,
    array $toolsUsed = [],
): AgentTurnResult {
    return new AgentTurnResult(
        content: 'Test response',
        iterations: $iterations,
        promptTokens: $promptTokens,
        completionTokens: $completionTokens,
        totalTokens: $promptTokens + $completionTokens,
        durationMs: $durationMs,
        toolsUsed: $toolsUsed,
        childAgentCount: 0,
        restartRequested: false,
        backgroundTasks: $backgroundTasks,
    );
}

test('renders background agents line when agents are active', function () {
    [$io, $output] = createBufferedIo();

    $started = (new DateTimeImmutable())->modify('-120 seconds')->format('c');
    $summary = BackgroundTaskSummary::fromRows([
        [
            'id' => 'aaa',
            'status' => 'running',
            'title' => 'Refactor auth',
            'role' => 'coder',
            'tool_name' => null,
            'started_at' => $started,
            'created_at' => $started,
        ],
    ]);

    $renderer = new TerminalRenderer($io);
    $renderer->render(createResult(backgroundTasks: $summary), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->toContain('Background Agent');
    expect($text)->toContain('coder');
    expect($text)->toContain('Refactor auth');
});

test('renders background tools line when tools are active', function () {
    [$io, $output] = createBufferedIo();

    $started = (new DateTimeImmutable())->modify('-45 seconds')->format('c');
    $summary = BackgroundTaskSummary::fromRows([
        [
            'id' => 'bbb',
            'status' => 'running',
            'title' => 'Scrape docs',
            'role' => 'orchestrator',
            'tool_name' => 'web_scrape',
            'started_at' => $started,
            'created_at' => $started,
        ],
    ]);

    $renderer = new TerminalRenderer($io);
    $renderer->render(createResult(backgroundTasks: $summary), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->toContain('Background Tool');
    expect($text)->toContain('web_scrape');
    expect($text)->toContain('Scrape docs');
});

test('renders both agents and tools when both exist', function () {
    [$io, $output] = createBufferedIo();

    $started = (new DateTimeImmutable())->modify('-60 seconds')->format('c');
    $summary = BackgroundTaskSummary::fromRows([
        ['id' => '1', 'status' => 'running', 'title' => 'Code', 'role' => 'coder', 'tool_name' => null, 'started_at' => $started, 'created_at' => $started],
        ['id' => '2', 'status' => 'running', 'title' => 'Review', 'role' => 'reviewer', 'tool_name' => null, 'started_at' => $started, 'created_at' => $started],
        ['id' => '3', 'status' => 'running', 'title' => 'Scrape', 'role' => 'orchestrator', 'tool_name' => 'web_scrape', 'started_at' => $started, 'created_at' => $started],
    ]);

    $renderer = new TerminalRenderer($io);
    $renderer->render(createResult(backgroundTasks: $summary), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->toContain('Background Agents [2x]');
    expect($text)->toContain('Background Tool [1x]');
    expect($text)->toContain('coder');
    expect($text)->toContain('reviewer');
    expect($text)->toContain('web_scrape');
});

test('does not render background section when no tasks exist', function () {
    [$io, $output] = createBufferedIo();

    $renderer = new TerminalRenderer($io);
    $renderer->render(createResult(), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->not->toContain('Background');
});

test('does not render background section when summary is empty', function () {
    [$io, $output] = createBufferedIo();

    $summary = new BackgroundTaskSummary();
    $renderer = new TerminalRenderer($io);
    $renderer->render(createResult(backgroundTasks: $summary), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->not->toContain('Background');
});

test('shows pending status for pending tasks', function () {
    [$io, $output] = createBufferedIo();

    $summary = BackgroundTaskSummary::fromRows([
        [
            'id' => 'ccc',
            'status' => 'pending',
            'title' => 'Queued task',
            'role' => 'coder',
            'tool_name' => null,
            'started_at' => null,
            'created_at' => (new DateTimeImmutable())->format('c'),
        ],
    ]);

    $renderer = new TerminalRenderer($io);
    $renderer->render(createResult(backgroundTasks: $summary), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->toContain('Background Agent');
    expect($text)->toContain('Queued task');
    expect($text)->toContain('pending');
});

test('pluralizes labels correctly for single vs multiple', function () {
    [$io, $output] = createBufferedIo();

    $started = (new DateTimeImmutable())->modify('-30 seconds')->format('c');
    $summary = BackgroundTaskSummary::fromRows([
        ['id' => '1', 'status' => 'running', 'title' => 'A', 'role' => 'coder', 'tool_name' => null, 'started_at' => $started, 'created_at' => $started],
    ]);

    $renderer = new TerminalRenderer($io);
    $renderer->render(createResult(backgroundTasks: $summary), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->toContain('Background Agent [1x]');
    expect($text)->not->toContain('Background Agents');
});

test('background section is not affected by showHints being false', function () {
    [$io, $output] = createBufferedIo();

    $started = (new DateTimeImmutable())->modify('-30 seconds')->format('c');
    $summary = BackgroundTaskSummary::fromRows([
        ['id' => '1', 'status' => 'running', 'title' => 'Task', 'role' => 'coder', 'tool_name' => null, 'started_at' => $started, 'created_at' => $started],
    ]);

    $renderer = new TerminalRenderer($io, showHints: false);
    $renderer->render(createResult(backgroundTasks: $summary), contentStreamed: true);

    $text = $output->fetch();
    expect($text)->toContain('Background Agent');
    expect($text)->toContain('coder');
});

test('AgentTurnResult toArray includes background_tasks when present', function () {
    $summary = BackgroundTaskSummary::fromRows([
        ['id' => 'aaa', 'status' => 'running', 'title' => 'Task', 'role' => 'coder', 'tool_name' => null, 'started_at' => '2026-03-29T10:00:00+00:00', 'created_at' => '2026-03-29T09:59:00+00:00'],
    ]);

    $result = createResult(backgroundTasks: $summary);
    $array = $result->toArray();

    expect($array)->toHaveKey('background_tasks');
    expect($array['background_tasks']['total_count'])->toBe(1);
    expect($array['background_tasks']['agents'])->toHaveCount(1);
});

test('AgentTurnResult toArray returns null background_tasks when absent', function () {
    $result = createResult();
    $array = $result->toArray();

    expect($array)->toHaveKey('background_tasks');
    expect($array['background_tasks'])->toBeNull();
});
