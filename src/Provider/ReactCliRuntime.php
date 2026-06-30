<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Provider;

use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessChunk;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessRequest;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessResult;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use React\ChildProcess\Process as ReactProcess;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Stream\WritableStreamInterface;

use function React\Async\await;

/**
 * Fiber-friendly CliRuntime that spawns CLI vendor binaries via ReactPHP.
 *
 * php-agents' CliProvider stays process-agnostic; this host implementation runs
 * the binary on the ReactPHP event loop and suspends the current Fiber with
 * await(), so the REPL spinner keeps animating during a CLI-backed LLM call —
 * the same approach ShellToolkit uses for shell commands and
 * ReactHttpClientAdapter uses for HTTP requests.
 */
final class ReactCliRuntime implements CliRuntimeInterface
{
    /** @var array<string, bool> */
    private array $availabilityCache = [];

    public function __construct(
        private readonly ?ProcessCancellationToken $cancellationToken = null,
    ) {}

    public function isAvailable(string $binary): bool
    {
        if (isset($this->availabilityCache[$binary])) {
            return $this->availabilityCache[$binary];
        }

        // Cheap PATH probe — does not spawn the LLM, just resolves the binary.
        $command = (PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ')
            . escapeshellarg($binary);

        $available = false;
        try {
            $result = $this->execute(new CliProcessRequest(
                binary: $binary,
                stdin: '',
                timeout: 5.0,
            ), $command);
            $available = $result->isSuccess() && trim($result->stdout) !== '';
        } catch (\Throwable) {
            $available = false;
        }

        return $this->availabilityCache[$binary] = $available;
    }

    public function run(CliProcessRequest $request): CliProcessResult
    {
        return $this->execute($request, $this->buildCommand($request));
    }

    public function stream(CliProcessRequest $request): iterable
    {
        $command = $this->buildCommand($request);
        $timeout = $request->timeout ?? CoquiDefaults::HTTP_MAX_TIMEOUT_SECONDS;

        $process = new ReactProcess($command, null, null);
        $process->start();

        $this->writeStdin($process, $request->stdin);

        /** @var CliProcessChunk[] $queue */
        $queue = [];
        /** @var Deferred<CliProcessChunk>|null $pending */
        $pending = null;
        $finished = false;
        $stderr = '';
        $exitCode = 0;

        $deliver = static function (CliProcessChunk $chunk) use (&$queue, &$pending): void {
            if ($pending !== null) {
                $deferred = $pending;
                $pending = null;
                $deferred->resolve($chunk);
            } else {
                $queue[] = $chunk;
            }
        };

        $process->stdout?->on('data', static function (string $data) use ($deliver): void {
            if ($data !== '') {
                $deliver(new CliProcessChunk(content: $data));
            }
        });

        $process->stderr?->on('data', static function (string $data) use (&$stderr): void {
            $stderr .= $data;
        });

        $timeoutTimer = Loop::addTimer($timeout, static function () use ($process): void {
            $process->terminate();
        });

        $this->cancellationToken?->onCancel(static function () use ($process): void {
            Loop::futureTick(static fn() => $process->terminate());
        });

        $process->on('exit', static function (?int $code) use (&$finished, &$exitCode, $deliver, $timeoutTimer): void {
            Loop::cancelTimer($timeoutTimer);
            $finished = true;
            $exitCode = $code ?? 1;
            $deliver(new CliProcessChunk(isLast: true, exitCode: $exitCode));
        });

        // Pull loop: suspend the Fiber on each empty queue so the event loop runs.
        while (true) {
            if ($queue !== []) {
                $chunk = array_shift($queue);
            } elseif ($finished) {
                return;
            } else {
                $pending = new Deferred();
                $chunk = await($pending->promise());
                $pending = null;
            }

            yield $chunk;

            if ($chunk->isLast) {
                if ($exitCode !== 0 && $chunk->error === null) {
                    // Surface stderr on the terminal chunk for the adapter/provider.
                    yield new CliProcessChunk(isLast: true, exitCode: $exitCode, error: trim($stderr));
                }

                return;
            }
        }
    }

    private function execute(CliProcessRequest $request, string $command): CliProcessResult
    {
        $timeout = $request->timeout ?? CoquiDefaults::HTTP_MAX_TIMEOUT_SECONDS;

        $process = new ReactProcess($command, null, null);
        $deferred = new Deferred();
        $stdout = '';
        $stderr = '';

        $process->start();

        $this->writeStdin($process, $request->stdin);

        $process->stdout?->on('data', static function (string $data) use (&$stdout): void {
            $stdout .= $data;
        });

        $process->stderr?->on('data', static function (string $data) use (&$stderr): void {
            $stderr .= $data;
        });

        $timeoutTimer = Loop::addTimer($timeout, static function () use ($process): void {
            $process->terminate();
        });

        $this->cancellationToken?->onCancel(static function () use ($process): void {
            Loop::futureTick(static fn() => $process->terminate());
        });

        $process->on('exit', static function (?int $code) use ($deferred, $timeoutTimer): void {
            Loop::cancelTimer($timeoutTimer);
            $deferred->resolve($code ?? 1);
        });

        $exitCode = (int) await($deferred->promise());

        return new CliProcessResult($exitCode, $stdout, $stderr);
    }

    private function writeStdin(ReactProcess $process, string $stdin): void
    {
        if ($stdin === '') {
            return;
        }

        $pipe = $process->stdin;
        if ($pipe instanceof WritableStreamInterface) {
            $pipe->write($stdin);
            $pipe->end();
        }
    }

    private function buildCommand(CliProcessRequest $request): string
    {
        $parts = [escapeshellarg($request->binary)];
        foreach ($request->arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }

        return implode(' ', $parts);
    }
}
