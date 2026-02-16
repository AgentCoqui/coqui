<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Simple pattern-matching HTTP router for the API server.
 *
 * Maps method + path patterns to handler callables. Supports path parameters
 * via {name} placeholders (e.g. /api/sessions/{id}).
 *
 * No framework dependency — built on ReactPHP request/response objects.
 */
final class Router
{
    /** @var array<string, array{pattern: string, handler: callable, regex: string, params: string[]}> */
    private array $routes = [];

    /** @var callable[] */
    private array $middleware = [];

    /**
     * Register a route handler.
     *
     * @param callable(ServerRequestInterface, array<string, string>): Response $handler
     */
    public function addRoute(string $method, string $path, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $matches) use (&$params): string {
            $params[] = $matches[1];
            return '([^/]+)';
        }, $path);

        $key = strtoupper($method) . ':' . $path;
        $this->routes[$key] = [
            'pattern' => $path,
            'handler' => $handler,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
        ];
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

    /**
     * Dispatch a request to the matching handler.
     */
    public function dispatch(ServerRequestInterface $request): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // Handle OPTIONS preflight — let middleware handle CORS headers
        if ($method === 'OPTIONS') {
            $handler = static fn(): Response => new Response(204);
            return $this->applyMiddleware($request, $handler);
        }

        // Find matching route
        foreach ($this->routes as $route) {
            $routeMethod = strtoupper(explode(':', array_search($route, $this->routes, true) ?: '')[0] ?? '');

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
        $handler = static fn(): Response => self::jsonResponse(['error' => ['code' => 404, 'message' => 'Not found']], 404);
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
