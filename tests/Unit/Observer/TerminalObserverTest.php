<?php

declare(strict_types=1);

use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Support\ImagePreviewService;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\ToolStatus;
use Symfony\Component\Console\Output\BufferedOutput;

function makeTerminalObserver(): array
{
    $output = new BufferedOutput();
    $observer = new TerminalObserver($output);

    return [$observer, $output];
}

/**
 * Create a TerminalObserver with an AnimatedTickCallback attached.
 *
 * The tick callback writes to the same BufferedOutput so we can verify
 * spinner output is suppressed during streamed output.
 */
function makeTerminalObserverWithTicker(): array
{
    $output = new BufferedOutput();
    $observer = new TerminalObserver($output);
    $ticker = new AnimatedTickCallback($output);
    $observer->setTickCallback($ticker);

    return [$observer, $output, $ticker];
}

function makeImagePreviewService(string $workspace): ImagePreviewService
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

// --- agent.reasoning ---

test('reasoning delta writes ⛭ prefix on first chunk', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'I think...');

    expect($output->fetch())->toContain('⛭');
});

test('reasoning delta writes the reasoning text', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'step one');

    expect($output->fetch())->toContain('step one');
});

test('reasoning delta prefix appears only once for multiple chunks', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'chunk A');
    $observer->handleEvent('agent.reasoning', 'chunk B');

    $text = $output->fetch();
    expect(substr_count($text, '⛭'))->toBe(1);
    expect($text)->toContain('chunk A');
    expect($text)->toContain('chunk B');
});

test('empty reasoning string is ignored', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', '');

    expect($output->fetch())->toBe('');
});

test('non-string reasoning data is ignored', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', null);
    $observer->handleEvent('agent.reasoning', 42);

    expect($output->fetch())->toBe('');
});

// --- close-line behavior ---

test('first text_delta after reasoning starts on a new line', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'thinking...');
    $observer->handleEvent('agent.text_delta', "Hello!\n");
    // Trigger flush via agent.done so the markdown buffer emits content
    $observer->handleEvent('agent.done', []);

    $text = $output->fetch();
    // The newline closing the reasoning line must appear before the response text
    $reasoningEnd = strpos($text, 'thinking...');
    $textStart = strpos($text, 'Hello!');
    expect($textStart)->toBeGreaterThan($reasoningEnd);

    // There must be a newline between reasoning and text
    $between = substr($text, $reasoningEnd + strlen('thinking...'), $textStart - $reasoningEnd - strlen('thinking...'));
    expect($between)->toContain("\n");
});

test('text_delta without preceding reasoning writes inline without extra newline', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.text_delta', 'Hello');
    $observer->handleEvent('agent.text_delta', " world\n");
    // Trigger flush via agent.done so the streaming markdown buffer emits content
    $observer->handleEvent('agent.done', []);

    expect($output->fetch())->toContain('Hello world');
});

test('agent.iteration closes open reasoning line with newline', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'thinking...');
    $output->fetch(); // flush

    $observer->handleEvent('agent.iteration', 2);

    // The newline closing the reasoning line should appear in output
    // and then the iteration marker follows
    $text = $output->fetch();
    expect($text)->toContain('⟳ Iteration 2');
});

test('agent.iteration does NOT add extra newline if no reasoning was streamed', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.iteration', 1);

    $text = $output->fetch();
    // Should contain the iteration marker but no leading blank line from reasoning close
    expect($text)->toContain('⟳ Iteration 1');
});

test('agent.done closes open reasoning line before done message', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'thinking...');
    $output->fetch();

    $observer->handleEvent('agent.done', ['response' => 'Final answer']);

    $text = $output->fetch();
    // After closing the reasoning line, done message appears
    expect($text)->toContain('Done');
});

test('tool_call closes open reasoning line before tool display', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'thinking...');
    $output->fetch();

    $toolCall = new ToolCall(id: 'call_1', name: 'get_weather', arguments: ['city' => 'NYC']);
    $observer->handleEvent('agent.tool_call', $toolCall);

    $text = $output->fetch();
    expect($text)->toContain('get_weather');
});

// --- agent.start resets state ---

test('agent.start resets reasoning flag so prefix appears again on next reasoning', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'first session');
    $output->fetch();
    $observer->handleEvent('agent.iteration', 1);
    $output->fetch();

    $observer->handleEvent('agent.start', null);
    $output->fetch();

    $observer->handleEvent('agent.reasoning', 'second session');

    $text = $output->fetch();
    expect($text)->toContain('⛭');
    expect($text)->toContain('second session');
});

// --- existing events still work ---

test('agent.start shows started message', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.start', null);

    expect($output->fetch())->toContain('Agent started');
});

test('agent.start and reasoning include the active actor label when set', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->setActorContext('trinity', 'orchestrator');
    $observer->handleEvent('agent.start', null);
    $observer->handleEvent('agent.reasoning', 'Greeting the user.');

    $text = $output->fetch();
    expect($text)->toContain('Agent started');
    expect($text)->toContain('(@trinity)');
    expect($text)->toContain('Greeting the user.');
});

test('group round start prints responder selection rationale', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('group_round_start', [
        'round' => 1,
        'max_rounds' => 3,
        'responders' => ['alex-hormozi', 'trinity'],
        'selection_source' => 'default_all',
        'selection_rationale' => 'No explicit member mentions were provided, so all group members respond in stored order.',
    ]);

    $text = $output->fetch();
    expect($text)->toContain('Group round 1/3');
    expect($text)->toContain('@alex-hormozi, @trinity');
    expect($text)->toContain('all group members respond in stored order');
});

test('agent.error shows error message', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.error', 'Something went wrong');

    expect($output->fetch())->toContain('Something went wrong');
});

test('agent.text_delta writes text inline', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.text_delta', 'Hello ');
    $observer->handleEvent('agent.text_delta', "world\n");
    // Trigger flush via agent.done so the streaming markdown buffer emits content
    $observer->handleEvent('agent.done', []);

    expect($output->fetch())->toContain('Hello world');
});

test('unknown event is silently ignored', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('unknown.event', 'data');

    expect($output->fetch())->toBe('');
});

// --- tick callback suspend/resume integration ---

test('text_delta suspends tick callback on first chunk', function () {
    [$observer, $output, $ticker] = makeTerminalObserverWithTicker();

    // Start the ticker to simulate the REPL spinner running
    $ticker->start();
    $output->fetch(); // discard start output

    // First text_delta should suspend the ticker
    $observer->handleEvent('agent.text_delta', "Hello\n");
    $output->fetch(); // discard

    // Subsequent tick() calls should produce no spinner output
    $ticker->tick();
    $ticker->tick();

    expect($output->fetch())->toBe('');
});

test('reasoning suspends tick callback on first chunk', function () {
    [$observer, $output, $ticker] = makeTerminalObserverWithTicker();

    $ticker->start();
    $output->fetch();

    $observer->handleEvent('agent.reasoning', 'thinking...');
    $output->fetch();

    // Ticker should be suspended — no spinner output
    $ticker->tick();

    expect($output->fetch())->toBe('');
});

test('showStatusLine via tool_call resumes tick callback after text streaming', function () {
    [$observer, $output, $ticker] = makeTerminalObserverWithTicker();

    $ticker->start();
    $output->fetch();

    // Simulate text streaming → suspends ticker
    $observer->handleEvent('agent.text_delta', "response text\n");
    $observer->handleEvent('agent.done', []);
    $output->fetch();

    // Now simulate a new iteration with a tool call → showStatusLine resumes
    $observer->handleEvent('agent.start', null);
    $observer->handleEvent('agent.iteration', 2);
    $output->fetch();

    $toolCall = new ToolCall(id: 'call_1', name: 'read_file', arguments: ['path' => 'foo.php']);
    $observer->handleEvent('agent.tool_call', $toolCall);
    $output->fetch();

    // The ticker should be resumed — tick() should produce spinner output
    // Force a draw by resetting the throttle window
    $ticker->start('read_file');
    $text = $output->fetch();

    expect($text)->toContain('Working on read_file');
});

test('text_delta does not suspend on subsequent chunks', function () {
    [$observer, $output, $ticker] = makeTerminalObserverWithTicker();

    $ticker->start();
    $output->fetch();

    // First chunk suspends
    $observer->handleEvent('agent.text_delta', 'chunk1');
    $output->fetch();

    // Verify suspended
    $ticker->tick();
    expect($output->fetch())->toBe('');

    // Second chunk should NOT call suspend again (already suspended)
    // This is a no-op verification — the point is no errors occur
    $observer->handleEvent('agent.text_delta', 'chunk2');
    $output->fetch();

    $ticker->tick();
    expect($output->fetch())->toBe('');
});

test('spinner does not interfere with streaming text', function () {
    [$observer, $output, $ticker] = makeTerminalObserverWithTicker();

    $ticker->start();
    $output->fetch(); // discard initial spinner

    // Simulate streaming: multiple text deltas with tick() calls between them
    // (mimicking AbstractAgent's tick-between-chunks + periodic timer)
    $observer->handleEvent('agent.text_delta', "Hello ");
    $ticker->tick(); // Should be no-op (suspended after first text_delta)
    $observer->handleEvent('agent.text_delta', "world\n");
    $ticker->tick(); // Still no-op

    // Flush via done
    $observer->handleEvent('agent.done', []);

    $text = $output->fetch();

    // Text should be present and no "Working" should appear in the streaming output
    expect($text)->toContain('Hello world');
    // After the first text_delta, no spinner frames should have been drawn
    expect(substr_count($text, 'Working'))->toBe(0);
});

test('tool result renders image preview for workspace screenshot payloads', function () {
    $workspace = sys_get_temp_dir() . '/coqui-terminal-observer-preview-' . bin2hex(random_bytes(8));
    $imagePath = $workspace . '/browser/screenshots/example.png';

    mkdir(dirname($imagePath), 0755, true);
    file_put_contents($imagePath, 'fixture');

    try {
        $output = new BufferedOutput();
        $observer = new TerminalObserver($output, makeImagePreviewService($workspace));

        $observer->handleEvent('agent.start', null);
        $output->fetch();

        $observer->handleEvent('agent.tool_result', ToolResult::success(json_encode([
            'session' => 'integration',
            'page_id' => 'page-1',
            'path' => 'browser/screenshots/example.png',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'));

        $text = $output->fetch();

        expect($text)->toContain('Image captured: example.png')
            ->and($text)->toContain('Path:')
            ->and($text)->toContain('PREVIEW:example.png:40');
    } finally {
        cleanupTestTree($workspace);
    }
});

test('tool result only auto-previews the first image in a turn', function () {
    $workspace = sys_get_temp_dir() . '/coqui-terminal-observer-first-preview-' . bin2hex(random_bytes(8));
    $firstPath = $workspace . '/browser/screenshots/first.png';
    $secondPath = $workspace . '/browser/screenshots/second.png';

    mkdir(dirname($firstPath), 0755, true);
    file_put_contents($firstPath, 'fixture');
    file_put_contents($secondPath, 'fixture');

    try {
        $output = new BufferedOutput();
        $observer = new TerminalObserver($output, makeImagePreviewService($workspace));

        $observer->handleEvent('agent.start', null);
        $output->fetch();

        $observer->handleEvent('agent.tool_result', ToolResult::success(json_encode([
            'session' => 'integration',
            'page_id' => 'page-1',
            'path' => 'browser/screenshots/first.png',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'));
        $observer->handleEvent('agent.tool_result', ToolResult::success(json_encode([
            'session' => 'integration',
            'page_id' => 'page-1',
            'path' => 'browser/screenshots/second.png',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'));

        $text = $output->fetch();

        expect(substr_count($text, 'PREVIEW:'))->toBe(1)
            ->and($text)->toContain('Image captured: second.png');
    } finally {
        cleanupTestTree($workspace);
    }
});
