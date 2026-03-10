<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\RequestSizeMiddleware;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

test('allows GET requests regardless of size', function () {
    $middleware = new RequestSizeMiddleware(maxBytes: 100);
    $request = new ServerRequest('GET', '/api/v1/sessions');

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('allows POST with body within limit', function () {
    $middleware = new RequestSizeMiddleware(maxBytes: 1000);
    $request = new ServerRequest(
        'POST',
        '/api/v1/endpoint',
        ['Content-Length' => '10'],
        'small body',
    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('rejects POST with Content-Length exceeding limit', function () {
    $middleware = new RequestSizeMiddleware(maxBytes: 100);
    $request = new ServerRequest(
        'POST',
        '/api/v1/endpoint',
        ['Content-Length' => '200'],
    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(413);
    $body = json_decode((string) $response->getBody(), true);
    expect($body['code'])->toBe('payload_too_large');
});

test('rejects PUT with body size exceeding limit', function () {
    $middleware = new RequestSizeMiddleware(maxBytes: 50);
    $largeContent = str_repeat('x', 100);

    $request = new ServerRequest(
        'PUT',
        '/api/v1/endpoint',
        [],
        $largeContent,
    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(413);
});

test('allows POST with no Content-Length and small body', function () {
    $middleware = new RequestSizeMiddleware(maxBytes: 1000);
    $request = new ServerRequest('POST', '/api/v1/endpoint');

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('default max size is 50 MiB', function () {
    $middleware = new RequestSizeMiddleware();

    // Use reflection to verify default
    $ref = new \ReflectionClass($middleware);
    $maxBytes = $ref->getProperty('maxBytes')->getValue($middleware);

    expect($maxBytes)->toBe(52_428_800);
});

test('allows DELETE requests regardless of Content-Length', function () {
    $middleware = new RequestSizeMiddleware(maxBytes: 10);
    $request = new ServerRequest(
        'DELETE',
        '/api/v1/resource/123',
        ['Content-Length' => '99999'],
    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});
