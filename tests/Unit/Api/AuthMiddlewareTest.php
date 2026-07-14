<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

$ok = static fn (): Response => new Response(200, [], 'ok');

test('no configured key allows all requests', function () use ($ok) {
    $mw = new AuthMiddleware(null, null);

    $response = $mw(new ServerRequest('GET', '/api/v1/anything'), $ok);

    expect($response->getStatusCode())->toBe(200);
});

test('configured key rejects a request with no Authorization header', function () use ($ok) {
    $mw = new AuthMiddleware('secret', null);

    $response = $mw(new ServerRequest('GET', '/api/v1/sessions'), $ok);

    expect($response->getStatusCode())->toBe(401);
});

test('configured key accepts a correct Bearer token', function () use ($ok) {
    $mw = new AuthMiddleware('secret', null);
    $request = (new ServerRequest('GET', '/api/v1/sessions'))->withHeader('Authorization', 'Bearer secret');

    $response = $mw($request, $ok);

    expect($response->getStatusCode())->toBe(200);
});

test('health is no longer auto-exempt when no isPublic closure is given', function () use ($ok) {
    // Locks in removal of the hardcoded /api/v1/health string: with a null
    // closure, health must be treated like any other route → 401 without a key.
    $mw = new AuthMiddleware('secret', null);

    $response = $mw(new ServerRequest('GET', '/api/v1/health'), $ok);

    expect($response->getStatusCode())->toBe(401);
});

test('isPublic closure exempts a matching path from the Bearer check', function () use ($ok) {
    $isPublic = static fn (string $path): bool => $path === '/api/v1/health';
    $mw = new AuthMiddleware('secret', $isPublic);

    $response = $mw(new ServerRequest('GET', '/api/v1/health'), $ok);

    expect($response->getStatusCode())->toBe(200);
});

test('isPublic closure does not exempt a non-matching path', function () use ($ok) {
    $isPublic = static fn (string $path): bool => $path === '/api/v1/health';
    $mw = new AuthMiddleware('secret', $isPublic);

    $response = $mw(new ServerRequest('GET', '/api/v1/sessions'), $ok);

    expect($response->getStatusCode())->toBe(401);
});

test('OPTIONS preflight is skipped regardless of key', function () use ($ok) {
    $mw = new AuthMiddleware('secret', null);

    $response = $mw(new ServerRequest('OPTIONS', '/api/v1/sessions'), $ok);

    expect($response->getStatusCode())->toBe(200);
});
