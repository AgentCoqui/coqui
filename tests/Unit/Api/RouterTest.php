<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Router;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

test('addRoute registers an authenticated route that dispatches', function () {
    $router = new Router();
    $router->addRoute('GET', '/api/v1/thing', static fn (): Response => new Response(200, [], 'ok'));

    $response = $router->dispatch(new ServerRequest('GET', '/api/v1/thing'));

    expect($response->getStatusCode())->toBe(200);
});

test('a route registered via addRoute is not public by default', function () {
    $router = new Router();
    $router->addRoute('GET', '/api/v1/thing', static fn (): Response => new Response(200));

    expect($router->isPublicPath('/api/v1/thing'))->toBeFalse();
});

test('addPublicRoute registers a dispatchable route', function () {
    $router = new Router();
    $router->addPublicRoute('GET', '/api/v1/status', static fn (): Response => new Response(200, [], 'up'));

    $response = $router->dispatch(new ServerRequest('GET', '/api/v1/status'));

    expect($response->getStatusCode())->toBe(200);
});

test('isPublicPath matches a registered public route', function () {
    $router = new Router();
    $router->addPublicRoute('GET', '/api/v1/status', static fn (): Response => new Response(200));

    expect($router->isPublicPath('/api/v1/status'))->toBeTrue();
});

test('isPublicPath returns false for an unregistered path', function () {
    $router = new Router();

    expect($router->isPublicPath('/api/v1/status'))->toBeFalse();
});

test('isPublicPath matches a {param} public route on a single segment only', function () {
    $router = new Router();
    $router->addPublicRoute('POST', '/api/v1/webhooks/incoming/{name}', static fn (): Response => new Response(200));

    expect($router->isPublicPath('/api/v1/webhooks/incoming/gh'))->toBeTrue();
    // A sibling route without the trailing segment is NOT public.
    expect($router->isPublicPath('/api/v1/webhooks'))->toBeFalse();
    // An extra path segment must NOT match ([^/]+ is single-segment, regex is anchored).
    expect($router->isPublicPath('/api/v1/webhooks/incoming/gh/extra'))->toBeFalse();
});

test('publicRoutes lists registered public routes for the audit log', function () {
    $router = new Router();
    $router->addPublicRoute('GET', '/api/v1/health', static fn (): Response => new Response(200));
    $router->addPublicRoute('POST', '/api/v1/webhooks/incoming/{name}', static fn (): Response => new Response(200));

    expect($router->publicRoutes())->toBe([
        ['method' => 'GET', 'path' => '/api/v1/health'],
        ['method' => 'POST', 'path' => '/api/v1/webhooks/incoming/{name}'],
    ]);
});
