<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Observer\TerminalObserver;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Create a minimal SplSubject stub.
 *
 * EscCancellationObserver only needs the SplSubject contract — it passes the subject
 * through to TerminalObserver unchanged. TerminalObserver will bail early if the
 * subject doesn't implement AgentInterface, but that's fine for these unit tests
 * which focus on the ESC detection and delegation behaviour of the decorator.
 */
function makeSubject(): \SplSubject
{
    return new class implements \SplSubject {
        public function attach(\SplObserver $observer): void {}
        public function detach(\SplObserver $observer): void {}
        public function notify(): void {}
    };
}

/**
 * Create a fake STDIN resource pre-loaded with the given bytes.
 *
 * @return resource
 */
function makeStdin(string $bytes = ''): mixed
{
    $stream = fopen('php://memory', 'rw');
    assert(is_resource($stream));
    fwrite($stream, $bytes);
    rewind($stream);
    stream_set_blocking($stream, false);
    return $stream;
}

function makeEscObserver(
    ProcessCancellationToken $token,
    mixed $stdin,
): array {
    $output = new BufferedOutput();
    $inner = new TerminalObserver($output);
    // Force isTty=true so ESC detection runs on the injected memory stream
    $observer = new EscCancellationObserver($inner, $token, $output, $stdin, isTty: true);
    return [$observer, $output];
}

/**
 * Append bytes to the fake STDIN stream without disturbing the current read pointer.
 *
 * @param resource $stream
 */
function appendToStdin(mixed $stream, string $bytes): void
{
    $position = ftell($stream);
    assert($position !== false);

    fseek($stream, 0, SEEK_END);
    fwrite($stream, $bytes);
    fseek($stream, $position);
}

// --- Active flag ---

test('inactive observer does not read stdin', function () {
    $stdin = makeStdin("\x1B");
    $token = new ProcessCancellationToken();
    [$observer] = makeEscObserver($token, $stdin);

    $observer->active = false;
    $observer->update(makeSubject());

    expect($token->isCancelled())->toBeFalse();
});

test('active observer reads stdin and cancels on ESC', function () {
    $stdin = makeStdin("\x1B");
    $token = new ProcessCancellationToken();
    [$observer] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());

    expect($token->isCancelled())->toBeTrue();
});

// --- ESC detection ---

test('non-ESC byte does not cancel token', function () {
    $stdin = makeStdin('a');
    $token = new ProcessCancellationToken();
    [$observer] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());

    expect($token->isCancelled())->toBeFalse();
});

test('empty stdin does not cancel token', function () {
    $stdin = makeStdin('');
    $token = new ProcessCancellationToken();
    [$observer] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());

    expect($token->isCancelled())->toBeFalse();
});

// --- Cancellation message ---

test('cancellation message is printed when ESC detected', function () {
    $stdin = makeStdin("\x1B");
    $token = new ProcessCancellationToken();
    [$observer, $output] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());

    expect($output->fetch())->toContain('Cancellation requested');
});

test('cancellation message is printed only once across multiple events', function () {
    $stdin = makeStdin("\x1B");
    $token = new ProcessCancellationToken();
    [$observer, $output] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());
    appendToStdin($stdin, "\x1B");
    $observer->update(makeSubject());

    expect(substr_count($output->fetch(), 'Cancellation requested'))->toBe(1);
});

// --- setToken() ---

test('setToken replaces the token and resets message flag', function () {
    $stdin = makeStdin("\x1B");
    $token1 = new ProcessCancellationToken();
    [$observer, $output] = makeEscObserver($token1, $stdin);

    // First turn: cancel token1 and show message
    $observer->active = true;
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());
    expect($token1->isCancelled())->toBeTrue();
    $output->fetch(); // flush output

    // Second turn: new token, message flag reset
    $token2 = new ProcessCancellationToken();
    $observer->setToken($token2);
    appendToStdin($stdin, "\x1B");
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());

    expect($token2->isCancelled())->toBeTrue();
    expect($output->fetch())->toContain('Cancellation requested');
});

test('endTurn drains stale ESC bytes so the next turn starts clean', function () {
    $stdin = makeStdin("\x1B");
    $token1 = new ProcessCancellationToken();
    [$observer, $output] = makeEscObserver($token1, $stdin);

    $observer->setToken($token1);
    $observer->active = true;
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());

    expect($token1->isCancelled())->toBeTrue();
    expect($output->fetch())->toContain('Cancellation requested');

    $observer->endTurn();

    $token2 = new ProcessCancellationToken();
    $observer->beginTurn($token2);
    $observer->update(makeSubject());

    expect($token2->isCancelled())->toBeFalse();
    expect($output->fetch())->not->toContain('Cancellation requested');
});

// --- Delegation to inner observer ---

test('inner TerminalObserver update() is called without throwing', function () {
    $stdin = makeStdin('');
    $token = new ProcessCancellationToken();
    [$observer] = makeEscObserver($token, $stdin);

    $observer->active = true;

    // Expect no exception — delegation to inner observer happens regardless of subject type
    expect(fn () => $observer->update(makeSubject()))->not->toThrow(\Throwable::class);
});

test('inner observer update() is called even after ESC cancellation', function () {
    $stdin = makeStdin("\x1B");
    $token = new ProcessCancellationToken();
    [$observer, $output] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());
    $output->fetch(); // flush cancellation message

    // Next event: already cancelled, inner observer should still be called without error
    expect(fn () => $observer->update(makeSubject()))->not->toThrow(\Throwable::class);
});

test('up arrow does not cancel token', function () {
    $stdin = makeStdin("\x1B[A");
    $token = new ProcessCancellationToken();
    [$observer, $output] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());

    expect($token->isCancelled())->toBeFalse();
    expect($output->fetch())->not->toContain('Cancellation requested');
});

test('fragmented up arrow does not cancel token', function () {
    $stdin = makeStdin("\x1B");
    $token = new ProcessCancellationToken();
    [$observer, $output] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());
    appendToStdin($stdin, "[A");
    $observer->update(makeSubject());
    usleep(110000);
    $observer->update(makeSubject());

    expect($token->isCancelled())->toBeFalse();
    expect($output->fetch())->not->toContain('Cancellation requested');
});

// --- Already-cancelled token ---

test('already-cancelled token suppresses further ESC reads', function () {
    $stdin = makeStdin("\x1B");
    $token = new ProcessCancellationToken();
    $token->cancel(); // pre-cancelled
    [$observer, $output] = makeEscObserver($token, $stdin);

    $observer->active = true;
    $observer->update(makeSubject());

    // No cancellation message because isCancelled() skips the fread branch
    expect($output->fetch())->not->toContain('Cancellation requested');
});
