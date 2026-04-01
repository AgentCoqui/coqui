<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Provider;

use React\Promise\Deferred;
use React\Stream\ReadableStreamInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

use function React\Async\await;

/**
 * Converts a ReactPHP ReadableStreamInterface into a pull-based
 * ResponseStreamInterface (Iterator<ResponseInterface, ChunkInterface>).
 *
 * Each call to current() returns a ReactHttpChunk. When the internal
 * queue is empty, the iterator suspends the Fiber via await() on a
 * Deferred — this lets the event loop run timers (spinner animation)
 * while waiting for the next chunk of data from the network.
 */
final class ReactResponseStream implements ResponseStreamInterface
{
    /** @var ReactHttpChunk[] */
    private array $queue = [];
    /** @var Deferred<ReactHttpChunk>|null */
    private ?Deferred $pending = null;
    private bool $finished = false;
    private bool $started = false;
    private int $offset = 0;
    private ?ChunkInterface $current = null;

    public function __construct(
        private readonly ReadableStreamInterface $stream,
        private readonly ResponseInterface $response,
    ) {
        $this->attachListeners();
    }

    public function current(): ChunkInterface
    {
        return $this->current ?? new ReactHttpChunk('');
    }

    public function key(): ResponseInterface
    {
        return $this->response;
    }

    public function next(): void
    {
        $this->current = $this->pullChunk();
    }

    public function valid(): bool
    {
        return $this->current !== null && !$this->current->isLast();
    }

    public function rewind(): void
    {
        // Pull the first chunk (isFirst: true)
        $this->current = $this->pullChunk();
    }

    private function pullChunk(): ?ChunkInterface
    {
        // If we have queued data, return immediately
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        // Stream is done and queue is empty
        if ($this->finished) {
            return null;
        }

        // Wait for next data via Deferred — Fiber suspends here
        $this->pending = new Deferred();
        $chunk = await($this->pending->promise());
        $this->pending = null;

        return $chunk;
    }

    private function attachListeners(): void
    {
        $this->stream->on('data', function (string $data): void {
            $isFirst = !$this->started;
            $this->started = true;

            $chunk = new ReactHttpChunk(
                content: $data,
                isFirst: $isFirst,
                offset: $this->offset,
            );
            $this->offset += strlen($data);

            if ($this->pending !== null) {
                $deferred = $this->pending;
                $this->pending = null;
                $deferred->resolve($chunk);
            } else {
                $this->queue[] = $chunk;
            }
        });

        $this->stream->on('end', function (): void {
            $this->finished = true;

            $lastChunk = new ReactHttpChunk(
                content: '',
                isLast: true,
                offset: $this->offset,
            );

            if ($this->pending !== null) {
                $deferred = $this->pending;
                $this->pending = null;
                $deferred->resolve($lastChunk);
            } else {
                $this->queue[] = $lastChunk;
            }
        });

        $this->stream->on('error', function (\Throwable $e): void {
            $this->finished = true;

            $errorChunk = new ReactHttpChunk(
                content: '',
                isLast: true,
                offset: $this->offset,
                error: $e->getMessage(),
            );

            if ($this->pending !== null) {
                $deferred = $this->pending;
                $this->pending = null;
                $deferred->resolve($errorChunk);
            } else {
                $this->queue[] = $errorChunk;
            }
        });
    }
}
