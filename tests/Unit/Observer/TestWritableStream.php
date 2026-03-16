<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Unit\Observer;

use React\Stream\WritableStreamInterface;

/**
 * A minimal writable stream stub that captures all written output in a public buffer.
 * Used by SseObserverTest to assert on SSE output without a real HTTP stream.
 */
class TestWritableStream implements WritableStreamInterface
{
    public string $buffer = '';
    private bool $writable;

    public function __construct(bool $writable = true)
    {
        $this->writable = $writable;
    }

    public function isWritable(): bool { return $this->writable; }

    public function write($data): bool
    {
        if ($this->writable) {
            $this->buffer .= $data;
        }
        return $this->writable;
    }

    public function end($data = null): void {}
    public function close(): void {}
    public function pause(): void {}
    public function resume(): void {}

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $dest;
    }

    public function on($event, callable $listener): void {}
    public function once($event, callable $listener): void {}
    public function removeListener($event, callable $listener): void {}
    public function removeAllListeners($event = null): void {}
    public function listeners($event = null): array { return []; }
    public function emit($event, array $arguments = []): void {}
}
