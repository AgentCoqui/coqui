<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * Small helper for Server-Sent Events over a ReactPHP ThroughStream.
 *
 * Encapsulates SSE frame formatting, the text/event-stream response headers,
 * and client-disconnect wiring so streaming endpoints share one implementation.
 */
final class SseStream
{
    private ThroughStream $stream;

    public function __construct()
    {
        $this->stream = new ThroughStream();
    }

    /**
     * Format a single SSE frame. Pure — no side effects.
     *
     * @param array<string, mixed> $data
     */
    public static function format(string $type, array $data, ?int $id = null): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $prefix = $id !== null ? "id: {$id}\n" : '';

        return "{$prefix}event: {$type}\ndata: {$json}\n\n";
    }

    /**
     * @param array<string, mixed> $data
     */
    public function connected(array $data): void
    {
        $this->stream->write(self::format('connected', $data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function event(string $type, array $data, ?int $id = null): void
    {
        $this->stream->write(self::format($type, $data, $id));
    }

    /**
     * Write a terminal `done` frame and close the stream.
     *
     * @param array<string, mixed> $data
     */
    public function done(array $data): void
    {
        $this->stream->write(self::format('done', $data));
        $this->stream->end();
    }

    public function end(): void
    {
        $this->stream->end();
    }

    public function onClose(callable $callback): void
    {
        $this->stream->on('close', $callback);
    }

    public function response(): Response
    {
        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
            $this->stream,
        );
    }
}
