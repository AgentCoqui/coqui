<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use CoquiBot\Coqui\Api\Middleware\RateLimitMiddleware;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

/**
 * Build a router with one authenticated and one public route, wrapped in the
 * rate-limit + auth stack exactly as ApiCommand wires it.
 */
function buildStack(string $apiKey, int $rateMax = 30): Router
{
    $router = new Router();
    $router->addRoute('GET', '/api/v1/private', static fn (): Response => new Response(200, [], 'private'));
    // The {name} path param is spread into the handler as a named argument by
    // Router::dispatch, so the handler must declare it — exactly as every real
    // param-route handler does (e.g. SessionHandler::get(..., string $id)).
    $router->addPublicRoute('POST', '/api/v1/public/{name}', static fn (ServerRequestInterface $req, string $name): Response => new Response(200, [], 'public'));

    $router->addMiddleware(new RateLimitMiddleware($rateMax, 60));
    $router->addMiddleware(new AuthMiddleware($apiKey, static fn (string $path): bool => $router->isPublicPath($path)));

    return $router;
}

test('a normal mod route is 401 without a key when api.key is configured', function () {
    $router = buildStack('secret');

    $response = $router->dispatch(new ServerRequest('GET', '/api/v1/private'));

    expect($response->getStatusCode())->toBe(401);
});

test('a normal mod route is 200 with the correct Bearer key', function () {
    $router = buildStack('secret');
    $request = (new ServerRequest('GET', '/api/v1/private'))->withHeader('Authorization', 'Bearer secret');

    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(200);
});

test('a public route is reachable without a key even when api.key is set', function () {
    $router = buildStack('secret');

    $response = $router->dispatch(new ServerRequest('POST', '/api/v1/public/gh'));

    expect($response->getStatusCode())->toBe(200);
});

test('a public route is still rate-limited', function () {
    // Capacity of 1: first request consumes the only token, second is 429 —
    // proving auth exemption does not imply rate-limit exemption.
    $router = buildStack('secret', rateMax: 1);

    $first = $router->dispatch(new ServerRequest('POST', '/api/v1/public/gh'));
    $second = $router->dispatch(new ServerRequest('POST', '/api/v1/public/gh'));

    expect($first->getStatusCode())->toBe(200);
    expect($second->getStatusCode())->toBe(429);
});
