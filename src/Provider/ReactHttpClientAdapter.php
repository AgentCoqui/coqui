<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Provider;

use CoquiBot\Coqui\Api\ProcessCancellationToken;
use React\EventLoop\Loop;
use React\Http\Browser;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Adapter implementing Symfony's HttpClientInterface using ReactPHP's Browser.
 *
 * When running inside a Fiber (REPL agent turn), every await() call suspends
 * the Fiber and lets the ReactPHP event loop run timers — this is what makes
 * the spinner animate during LLM API calls. Providers don't need any changes;
 * they already accept ?HttpClientInterface in their constructors.
 *
 * Only the methods actually used by php-agents providers are implemented:
 * request(), stream(), withOptions(). The request/stream contract matches
 * what SseStreamParser expects.
 */
final class ReactHttpClientAdapter implements HttpClientInterface
{
    private Browser $browser;

    /** @var array<string, mixed> */
    private array $defaultOptions;

    /**
     * @param array<string, mixed> $defaultOptions Merged into every request
     */
    public function __construct(
        ?Browser $browser = null,
        array $defaultOptions = [],
        private readonly ?ProcessCancellationToken $cancellationToken = null,
    ) {
        $this->browser = ($browser ?? new Browser())->withTimeout(300);
        $this->defaultOptions = $defaultOptions;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = array_merge($this->defaultOptions, $options);

        $headers = $this->normalizeHeaders(is_array($options['headers'] ?? null) ? $options['headers'] : []);
        $body = '';

        // Handle auth_bearer → Authorization header
        if (isset($options['auth_bearer']) && is_string($options['auth_bearer']) && $options['auth_bearer'] !== '') {
            $this->setHeader($headers, 'Authorization', 'Bearer ' . $options['auth_bearer']);
        }

        // Handle json option → serialize body + set Content-Type
        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!$this->hasHeader($headers, 'Content-Type')) {
                $this->setHeader($headers, 'Content-Type', 'application/json');
            }
        } elseif (isset($options['body'])) {
            $body = is_string($options['body']) ? $options['body'] : '';
        }

        // Handle query parameters
        if (!empty($options['query']) && is_array($options['query'])) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($options['query']);
        }

        // Handle timeout override
        $browser = $this->browser;
        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            $browser = $browser->withTimeout((float) $options['timeout']);
        }

        // Use requestStreaming so the response body is a ReadableStreamInterface
        $promise = $browser->requestStreaming($method, $url, $headers, $body);

        $response = new ReactHttpResponse(
            promise: $promise,
            url: $url,
            method: $method,
            startTime: microtime(true),
        );

        $this->cancellationToken?->onCancel(static function () use ($response): void {
            // Cancellation may be requested from a signal handler or a timer callback
            // while the current Fiber is suspended inside await(). Deferring the
            // actual promise cancellation onto the event loop avoids switching Fibers
            // from the wrong execution context during shutdown.
            Loop::futureTick(static function () use ($response): void {
                $response->cancel();
            });
        });

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // Normalize to single response — providers always call stream() with one response
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        foreach ($responses as $response) {
            if (!$response instanceof ReactHttpResponse) {
                throw new \InvalidArgumentException(sprintf(
                    'Expected %s, got %s',
                    ReactHttpResponse::class,
                    get_class($response),
                ));
            }

            // Resolve the React promise (Fiber suspends here) and get the body stream
            $bodyStream = $response->getBodyStream();

            return new ReactResponseStream($bodyStream, $response);
        }

        throw new \InvalidArgumentException('No responses provided to stream()');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->defaultOptions = array_merge($this->defaultOptions, $options);

        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            $clone->browser = $this->browser->withTimeout((float) $options['timeout']);
        }

        return $clone;
    }

    public function withCancellationToken(?ProcessCancellationToken $cancellationToken): static
    {
        return new self($this->browser, $this->defaultOptions, $cancellationToken);
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if ($name === '') {
                continue;
            }

            $this->setHeader($normalized, $name, $value);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $existingName) {
            if (strcasecmp($existingName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function setHeader(array &$headers, string $name, mixed $value): void
    {
        foreach (array_keys($headers) as $existingName) {
            if (strcasecmp($existingName, $name) === 0) {
                unset($headers[$existingName]);
            }
        }

        $headers[$name] = $value;
    }
}
