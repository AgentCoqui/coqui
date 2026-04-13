<?php

declare(strict_types=1);

use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use CoquiBot\Coqui\Repl\TerminalStateManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @return array{0: resource, 1: resource}
 */
function makeSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    assert($pair !== false);

    stream_set_blocking($pair[0], false);
    stream_set_blocking($pair[1], false);

    return [$pair[0], $pair[1]];
}

/**
 * @return array{0: InterruptiblePrompt, 1: BufferedOutput}
 */
function makeInterruptiblePrompt(mixed $stdin): array
{
    $output = new BufferedOutput();
    $io = new SymfonyStyle(new ArrayInput([]), $output);
    $terminalState = new TerminalStateManager($stdin, isTty: true);

    return [new InterruptiblePrompt($io, $terminalState, $stdin), $output];
}

/**
 * @param resource $writer
 */
function writeAndClosePromptInput(mixed $writer, string $bytes): void
{
    fwrite($writer, $bytes);
    fclose($writer);
}

test('standalone escape cancels prompt input', function (): void {
    [$reader, $writer] = makeSocketPair();
    writeAndClosePromptInput($writer, "\x1B");
    [$prompt] = makeInterruptiblePrompt($reader);

    try {
        expect(fn () => $prompt->ask('Prompt'))->toThrow(InteractionCancelledException::class);
    } finally {
        fclose($reader);
    }
});

test('up arrow sequence is ignored during prompt input', function (): void {
    [$reader, $writer] = makeSocketPair();
    writeAndClosePromptInput($writer, "\x1B[Ahello\n");
    [$prompt, $output] = makeInterruptiblePrompt($reader);

    try {
        expect($prompt->ask('Prompt'))->toBe('hello');
        expect($output->fetch())->not->toContain('Cancellation requested');
    } finally {
        fclose($reader);
    }
});

test('fragmented up arrow sequence is ignored during prompt input', function (): void {
    if (!function_exists('pcntl_fork')) {
        expect(true)->toBeTrue();
        return;
    }

    [$reader, $writer] = makeSocketPair();
    $pid = pcntl_fork();
    assert($pid !== -1);

    if ($pid === 0) {
        fwrite($writer, "\x1B");
        usleep(10000);
        fwrite($writer, "[Aworld\n");
        fclose($writer);
        exit(0);
    }

    fclose($writer);
    [$prompt] = makeInterruptiblePrompt($reader);

    try {
        expect($prompt->ask('Prompt'))->toBe('world');
    } finally {
        fclose($reader);
        pcntl_waitpid($pid, $status);
    }
});