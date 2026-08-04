<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use CoquiBot\Coqui\Storage\IdempotencyStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Stream\ReadableStreamInterface;

/**
 * CAP 0.5.0 Idempotency-Key dedup for creators (CORE-53).
 *
 * A creator request (a POST that mints a resource) carrying an `Idempotency-Key`
 * header is deduplicated: the first request runs the handler and its response is
 * recorded under the `(key, route, actor)` tuple; a later request with the same
 * tuple replays that stored response WITHOUT re-invoking the handler, so the
 * side-effect happens exactly once. Requests without the header, and requests to
 * non-creator routes, pass through unchanged.
 *
 * The set of creator routes is supplied at construction (method + path template)
 * and matched by the same `{param}` → single-segment compilation the Router uses,
 * so this middleware only engages on the routes wired to it.
 */
final class IdempotencyMiddleware
{
    /** @var list<array{method: string, regex: string, template: string}> */
    private array $creators = [];

    /**
     * @param list<array{method: string, path: string}> $creatorRoutes
     */
    public function __construct(
        private readonly IdempotencyStore $store,
        array $creatorRoutes,
    ) {
        foreach ($creatorRoutes as $route) {
            $method = strtoupper($route['method']);
            $this->creators[] = [
                'method' => $method,
                'regex' => $this->compile($route['path']),
                'template' => $method . ' ' . $route['path'],
            ];
        }
    }

    /**
     * @param callable(ServerRequestInterface): Response $next
     */
    public function __invoke(ServerRequestInterface $request, callable $next): Response
    {
        $key = $request->getHeaderLine('Idempotency-Key');

        // No key ⇒ no dedup, pass through unchanged.
        if ($key === '') {
            return $next($request);
        }

        $route = $this->matchCreator($request);

        // Header present but not a creator route ⇒ no dedup, pass through.
        if ($route === null) {
            return $next($request);
        }

        $actor = $this->actor($request);

        $stored = $this->store->lookup($key, $route, $actor);
        if ($stored !== null) {
            return $this->replay($stored);
        }

        return $this->recordAndBuffer($key, $route, $actor, $next($request));
    }

    /**
     * Record the handler's response under the tuple and return a buffered copy.
     *
     * A streaming / non-buffered response (SSE token streams, unknown-length
     * bodies) is passed through UNTOUCHED and recorded NOTHING: buffering it to a
     * string would collapse the live stream to an empty body and cache that empty
     * body for every retry. Only a safely-stringifiable buffered response is read
     * once, re-materialised, and persisted so it can be both stored and replayed.
     * Typed against the PSR interface so header/body access is contract-level.
     */
    private function recordAndBuffer(string $key, string $route, string $actor, ResponseInterface $response): Response
    {
        if ($this->isStreaming($response)) {
            // Live stream: return the original response unchanged, record nothing.
            return $response instanceof Response
                ? $response
                : new Response(
                    $response->getStatusCode(),
                    $response->getHeaders(),
                    $response->getBody(),
                );
        }

        $body = (string) $response->getBody();
        $contentType = $response->getHeaderLine('Content-Type');
        $this->store->record($key, $route, $actor, $response->getStatusCode(), $body, $contentType);

        return new Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            $body,
        );
    }

    /**
     * A response is streaming / non-buffered — and must never be recorded — when
     * it is an event stream, has an unknown body length, or its body is a live
     * React readable stream that would stringify to an empty body.
     */
    private function isStreaming(ResponseInterface $response): bool
    {
        if (stripos($response->getHeaderLine('Content-Type'), 'text/event-stream') === 0) {
            return true;
        }

        $body = $response->getBody();
        if ($body instanceof ReadableStreamInterface) {
            return true;
        }

        // React wraps a ReadableStreamInterface in a ReadableBodyStream whose
        // getSize() is null (unknown length) and whose __toString() is empty.
        return $body->getSize() === null;
    }

    /**
     * The matched creator route template (e.g. "POST /api/v1/sessions"), or null.
     */
    private function matchCreator(ServerRequestInterface $request): ?string
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        foreach ($this->creators as $creator) {
            if ($creator['method'] === $method && preg_match($creator['regex'], $path) === 1) {
                return $creator['template'];
            }
        }

        return null;
    }

    /**
     * Stable per-principal discriminator. coqui authenticates against a single
     * shared API key, so all authenticated callers are one principal; a Bearer
     * token is folded to a short digest (never stored raw), otherwise 'anon'.
     */
    private function actor(ServerRequestInterface $request): string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            return 'bearer:' . substr(hash('sha256', substr($auth, 7)), 0, 16);
        }

        return 'anon';
    }

    /**
     * @param array{status: int, body: string, content_type: string} $stored
     */
    private function replay(array $stored): Response
    {
        $contentType = $stored['content_type'] !== '' ? $stored['content_type'] : 'application/json';

        return new Response(
            $stored['status'],
            ['Content-Type' => $contentType],
            $stored['body'],
        );
    }

    /**
     * Compile a {param} path template into an anchored single-segment regex,
     * matching Router::compilePattern so wiring stays consistent.
     */
    private function compile(string $path): string
    {
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (): string => '([^/]+)',
            $path,
        );

        return '#^' . $regex . '$#';
    }
}
