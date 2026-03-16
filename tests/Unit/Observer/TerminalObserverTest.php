<?php

declare(strict_types=1);

use CoquiBot\Coqui\Observer\TerminalObserver;
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

// --- agent.reasoning ---

test('reasoning delta writes 💭 prefix on first chunk', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.reasoning', 'I think...');

    expect($output->fetch())->toContain('💭');
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
    expect(substr_count($text, '💭'))->toBe(1);
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
    expect($text)->toContain('💭');
    expect($text)->toContain('second session');
});

// --- existing events still work ---

test('agent.start shows started message', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.start', null);

    expect($output->fetch())->toContain('Agent started');
});

test('agent.error shows error message', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.error', 'Something went wrong');

    expect($output->fetch())->toContain('Something went wrong');
});

test('agent.text_delta writes text inline', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('agent.text_delta', 'Hello ');
    $observer->handleEvent('agent.text_delta', 'world');

    expect($output->fetch())->toBe('Hello world');
});

test('unknown event is silently ignored', function () {
    [$observer, $output] = makeTerminalObserver();

    $observer->handleEvent('unknown.event', 'data');

    expect($output->fetch())->toBe('');
});
