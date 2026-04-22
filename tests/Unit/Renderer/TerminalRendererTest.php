<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\BackgroundTaskSummary;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
use CoquiBot\Coqui\Support\ImagePreviewService;
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

function createRendererImagePreviewService(string $workspace): ImagePreviewService
{
    return new ImagePreviewService(
        $workspace,
        static fn(string $path, int $width): array => [
            'preview' => 'PREVIEW:' . basename($path) . ':' . $width,
            'preview_format' => 'ansi_blocks',
            'unavailable_reason' => null,
        ],
    );
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
    expect($array['background_tasks']['agents'][0]['started_at'])->toBe('2026-03-29T10:00:00+00:00');
    expect($array['background_tasks']['agents'][0]['created_at'])->toBe('2026-03-29T09:59:00+00:00');
});

test('AgentTurnResult toArray returns null background_tasks when absent', function () {
    $result = createResult();
    $array = $result->toArray();

    expect($array)->toHaveKey('background_tasks');
    expect($array['background_tasks'])->toBeNull();
});

test('AgentTurnResult fromError returns full API payload shape', function () {
    $array = AgentTurnResult::fromError('Internal error', 'partial output')->toArray();

    expect($array)->toMatchArray([
        'content' => 'partial output',
        'iterations' => 0,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
        'duration_ms' => 0,
        'tools_used' => [],
        'child_agent_count' => 0,
        'restart_requested' => false,
        'iteration_limit_reached' => false,
        'budget_exhausted' => false,
        'context_usage' => null,
        'file_edits' => null,
        'error' => 'Internal error',
        'review_feedback' => null,
        'review_approved' => null,
        'background_tasks' => null,
    ]);
});

test('renders local markdown image previews when content was not streamed', function () {
    [$io, $output] = createBufferedIo();
    $workspace = sys_get_temp_dir() . '/coqui-terminal-renderer-preview-' . bin2hex(random_bytes(8));
    $imagePath = $workspace . '/images/example.png';

    mkdir(dirname($imagePath), 0755, true);
    file_put_contents($imagePath, 'fixture');

    try {
        $result = new AgentTurnResult(
            content: '![Rendered](images/example.png)',
            iterations: 1,
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            durationMs: 0,
            toolsUsed: [],
            childAgentCount: 0,
            restartRequested: false,
        );

        $renderer = new TerminalRenderer($io, imagePreviewService: createRendererImagePreviewService($workspace));
        $renderer->render($result, contentStreamed: false);

        $text = $output->fetch();
        $plain = preg_replace('/\e\[[\d;]*m/', '', $text) ?? $text;

        expect($plain)->toContain('Assistant:')
            ->and($plain)->toContain('[image preview: Rendered]')
            ->and($plain)->toContain('PREVIEW:example.png:40');
    } finally {
        cleanupTestTree($workspace);
    }
});
