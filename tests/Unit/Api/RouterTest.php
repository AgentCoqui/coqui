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
