<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Provider;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use React\Stream\ReadableStreamInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function React\Async\await;

/**
 * Lazy Symfony ResponseInterface backed by a ReactPHP Promise.
 *
 * The underlying HTTP response is not resolved until a method that
 * needs it is called. Each blocking accessor uses React\Async\await()
 * which suspends the current Fiber, letting the event loop drive the
 * spinner animation and other timers while waiting for the response.
 */
final class ReactHttpResponse implements ResponseInterface
{
    private ?PsrResponseInterface $resolved = null;
    private ?string $bufferedContent = null;
    private bool $cancelled = false;

    /**
     * @param \React\Promise\PromiseInterface<PsrResponseInterface> $promise
     * @param string $url Request URL for getInfo()
     * @param string $method Request method for getInfo()
     */
    public function __construct(
        private \React\Promise\PromiseInterface $promise,
        private readonly string $url = '',
        private readonly string $method = 'GET',
        private readonly float $startTime = 0.0,
    ) {}

    public function getStatusCode(): int
    {
        return $this->resolve()->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        $psrHeaders = $this->resolve()->getHeaders();

        // Symfony expects lowercase header names with list<string> values
        $normalized = [];
        foreach ($psrHeaders as $name => $values) {
            $normalized[strtolower((string) $name)] = array_values($values);
        }

        return $normalized;
    }

    public function getContent(bool $throw = true): string
    {
        if ($this->bufferedContent !== null) {
            return $this->bufferedContent;
        }

        $response = $this->resolve();
        $body = $response->getBody();

        if ($body instanceof ReadableStreamInterface) {
            $this->bufferedContent = $this->bufferStream($body);
        } else {
            $this->bufferedContent = (string) $body;
        }

        return $this->bufferedContent;
    }

    /** @return array<string, mixed> */
    public function toArray(bool $throw = true): array
    {
        $content = $this->getContent($throw);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new \JsonException(sprintf(
                'Response body is not valid JSON: %s',
                json_last_error_msg(),
            ));
        }

        return $data;
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        if ($this->resolved !== null) {
            $body = $this->resolved->getBody();
            if ($body instanceof ReadableStreamInterface && $body->isReadable()) {
                $body->close();
            }
        }
        $this->promise->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = [
            'canceled' => $this->cancelled,
            'error' => null,
            'http_code' => $this->resolved !== null ? $this->resolved->getStatusCode() : 0,
            'http_method' => $this->method,
            'redirect_count' => 0,
            'redirect_url' => null,
            'response_headers' => [],
            'start_time' => $this->startTime,
            'url' => $this->url,
            'user_data' => null,
        ];

        if ($type !== null) {
            return $info[$type] ?? null;
        }

        return $info;
    }

    /**
     * Get the body stream for use by ReactResponseStream.
     *
     * This resolves the promise (suspending the Fiber) and returns
     * the ReadableStreamInterface body for chunked iteration.
     */
    public function getBodyStream(): ReadableStreamInterface
    {
        $response = $this->resolve();
        $body = $response->getBody();

        if (!$body instanceof ReadableStreamInterface) {
            throw new \RuntimeException('Response body is not a ReadableStreamInterface — was this a streaming request?');
        }

        return $body;
    }

    private function resolve(): PsrResponseInterface
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = await($this->promise);

        return $this->resolved;
    }

    /**
     * Buffer a ReadableStreamInterface to a string via await().
     *
     * Each chunk received suspends the Fiber, letting the event loop run.
     */
    private function bufferStream(ReadableStreamInterface $stream): string
    {
        $buffer = '';

        $deferred = new \React\Promise\Deferred();

        $stream->on('data', function (string $data) use (&$buffer): void {
            $buffer .= $data;
        });

        $stream->on('end', function () use ($deferred, &$buffer): void {
            $deferred->resolve($buffer);
        });

        $stream->on('error', function (\Throwable $e) use ($deferred): void {
            $deferred->reject($e);
        });

        if (!$stream->isReadable()) {
            return $buffer;
        }

        await($deferred->promise());

        return $buffer;
    }
}
