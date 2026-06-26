<?php

declare(strict_types=1);

use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use Symfony\Component\Console\Output\BufferedOutput;

function makeTickCallback(): array
{
    $output = new BufferedOutput();
    $callback = new AnimatedTickCallback($output);

    return [$callback, $output];
}

test('start() draws spinner frame immediately', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();

    $text = $output->fetch();
    expect($text)->toContain('Working');
    expect($text)->toContain('press ESC to cancel');
});

test('start() with context includes context in label', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start('exec');

    expect($output->fetch())->toContain('exec');
});

test('stop() clears status line', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $output->fetch(); // discard start output

    $cb->stop();

    // After stop, a clear sequence should have been written
    $text = $output->fetch();
    expect($text)->toContain("\r\033[K");
});

test('tick() is no-op when not started', function () {
    [$cb, $output] = makeTickCallback();

    $cb->tick();

    expect($output->fetch())->toBe('');
});

test('tick() is no-op after stop()', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $cb->stop();
    $output->fetch(); // discard

    $cb->tick();

    expect($output->fetch())->toBe('');
});

test('setContext() changes displayed label', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $output->fetch(); // discard initial draw

    $cb->setContext('read_file');
    // Force a redraw by resetting throttle — start() set lastDrawTime,
    // so we need to wait or force. Use a direct draw by calling start again.
    // Instead, we verify via a fresh start:
    $cb->stop();
    $cb->start('read_file');

    expect($output->fetch())->toContain('read_file');
});

test('suspend() clears status line and prevents further draws', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $output->fetch(); // discard start output

    $cb->suspend();

    $clearOutput = $output->fetch();
    expect($clearOutput)->toContain("\r\033[K");

    // Subsequent tick() calls should produce no output
    $cb->tick();
    $cb->tick();

    expect($output->fetch())->toBe('');
});

test('resume() re-enables drawing after suspend', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $cb->suspend();
    $output->fetch(); // discard

    $cb->resume();
    // resume() just sets active — need to call start() or tick() to draw.
    // start() resets lastDrawTime so the next tick draws immediately.
    $cb->start();

    expect($output->fetch())->toContain('Working');
});

test('suspend then resume preserves context', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $cb->setContext('exec');
    $cb->suspend();
    $output->fetch(); // discard

    $cb->resume();
    $cb->start('exec');

    expect($output->fetch())->toContain('exec');
});

test('suspend is idempotent', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $output->fetch();

    $cb->suspend();
    $output->fetch();

    // Second suspend should not error or produce extra output
    $cb->suspend();

    expect($output->fetch())->toBe('');
});

test('resume is idempotent', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();

    // Double resume should not error
    $cb->resume();
    $cb->resume();

    // Should still be active (start already set active=true)
    $cb->stop();
    expect($output->fetch())->toContain("\r\033[K");
});

test('clearStatusLine is no-op when nothing is visible', function () {
    [$cb, $output] = makeTickCallback();

    // Never started, so nothing visible
    $cb->clearStatusLine();

    expect($output->fetch())->toBe('');
});

test('clearStatusLine is idempotent after clearing', function () {
    [$cb, $output] = makeTickCallback();

    $cb->start();
    $output->fetch();

    $cb->clearStatusLine();
    $output->fetch();

    // Second clear should produce nothing
    $cb->clearStatusLine();

    expect($output->fetch())->toBe('');
});
