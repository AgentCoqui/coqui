<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use CoquiBot\Coqui\Storage\IdempotencyStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

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
     * The body is read once and re-materialised so it can be both persisted and
     * returned to the client (the underlying stream may not be seekable). Typed
     * against the PSR interface so header/body access is contract-level.
     */
    private function recordAndBuffer(string $key, string $route, string $actor, ResponseInterface $response): Response
    {
        $body = (string) $response->getBody();
        $this->store->record($key, $route, $actor, $response->getStatusCode(), $body);

        return new Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            $body,
        );
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
     * @param array{status: int, body: string} $stored
     */
    private function replay(array $stored): Response
    {
        return new Response(
            $stored['status'],
            ['Content-Type' => 'application/json'],
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
