<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Simple pattern-matching HTTP router for the API server.
 *
 * Maps method + path patterns to handler callables. Supports path parameters
 * via {name} placeholders (e.g. /api/v1/sessions/{id}).
 *
 * No framework dependency — built on ReactPHP request/response objects.
 */
final class Router
{
    /** @var array<string, array{pattern: string, handler: callable, regex: string, params: string[], requiresAuth: bool}> */
    private array $routes = [];

    /** @var list<string> Compiled regexes for routes registered as public (auth-exempt). */
    private array $publicPatterns = [];

    /**
     * @var list<array{method: string, path: string}> Public routes, for the boot-time audit log.
     *
     * Populated by addPublicRoute() and read back for the boot audit in Task 2; declared here so
     * that method does not re-introduce it. Remove this ignore once a reader lands.
     * @phpstan-ignore property.onlyWritten
     */
    private array $publicRoutes = [];

    /** @var callable[] */
    private array $middleware = [];

    /**
     * Register a route handler.
     *
     * @param callable(ServerRequestInterface, array<string, string>): Response $handler
     * @param bool $requiresAuth When false, the route is exempt from AuthMiddleware. Prefer addPublicRoute() to set this.
     */
    public function addRoute(string $method, string $path, callable $handler, bool $requiresAuth = true): void
    {
        $compiled = $this->compilePattern($path);

        $key = strtoupper($method) . ':' . $path;
        $this->routes[$key] = [
            'pattern' => $path,
            'handler' => $handler,
            'regex' => $compiled['regex'],
            'params' => $compiled['params'],
            'requiresAuth' => $requiresAuth,
        ];
    }

    /**
     * Compile a {param} path pattern into an anchored, single-segment regex.
     *
     * @return array{regex: string, params: string[]}
     */
    private function compilePattern(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $matches) use (&$params): string {
            $params[] = $matches[1];
            return '([^/]+)';
        }, $path);

        return ['regex' => '#^' . $regex . '$#', 'params' => $params];
    }

    /**
     * Determine whether a request path matches a route registered as public.
     */
    public function isPublicPath(string $path): bool
    {
        foreach ($this->publicPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register middleware that wraps all handlers.
     *
     * @param callable(ServerRequestInterface, callable): Response $middleware
     */
    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Convenience methods for common HTTP verbs.
     */
    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Create a standard error response using ApiErrorCode.
     */
    public static function errorResponse(ApiErrorCode $code, string $message, mixed $details = null): Response
    {
        return self::jsonResponse($code->toPayload($message, $details), $code->httpStatus());
    }

    /**
     * Dispatch a request to the matching handler.
     *
     * Wraps the entire dispatch in a try/catch to prevent internal
     * details from leaking to clients in uncaught exceptions.
     */
    public function dispatch(ServerRequestInterface $request): Response
    {
        try {
            return $this->doDispatch($request);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Coqui API] Uncaught exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            return self::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * Internal dispatch logic.
     */
    private function doDispatch(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // Handle OPTIONS preflight — let middleware handle CORS headers
        if ($method === 'OPTIONS') {
            $handler = static fn(): Response => new Response(204);
            return $this->applyMiddleware($request, $handler);
        }

        // Find matching route
        foreach ($this->routes as $routeKey => $route) {
            $routeMethod = strtoupper(explode(':', $routeKey)[0]);

            if ($routeMethod !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches); // Remove full match

                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? '';
                }

                $handler = static fn(ServerRequestInterface $req): Response => ($route['handler'])($req, ...$params);
                return $this->applyMiddleware($request, $handler);
            }
        }

        // 404
        $handler = static fn(): Response => self::errorResponse(ApiErrorCode::NOT_FOUND, 'Not found');
        return $this->applyMiddleware($request, $handler);
    }

    /**
     * Apply middleware chain, innermost first.
     *
     * @param callable(ServerRequestInterface): Response $handler
     */
    private function applyMiddleware(ServerRequestInterface $request, callable $handler): Response
    {
        $next = $handler;

        foreach (array_reverse($this->middleware) as $mw) {
            $current = $next;
            $next = static fn(ServerRequestInterface $req): Response => $mw($req, $current);
        }

        return $next($request);
    }

    /**
     * Create a JSON response.
     *
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, string> $headers
     */
    public static function jsonResponse(array $data, int $status = 200, array $headers = []): Response
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response(
            $status,
            array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
            $json,
        );
    }
}
