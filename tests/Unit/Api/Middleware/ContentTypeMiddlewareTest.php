<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\ContentTypeMiddleware;
use React\Http\Io\BufferedBody;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

test('allows GET requests without content type', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest('GET', '/api/v1/sessions');

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('allows DELETE requests without content type', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest('DELETE', '/api/v1/sessions/123');

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('allows POST with application/json content type', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/123/messages',
        ['Content-Type' => 'application/json'],
        '{"prompt":"hi"}',

    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('allows POST with multipart/form-data content type', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/123/files',
        ['Content-Type' => 'multipart/form-data; boundary=----FormBoundary'],
        'some multipart data',
    );

    $response = $middleware($request, fn() => new Response(201, [], 'ok'));

    expect($response->getStatusCode())->toBe(201);
});

test('rejects POST with unsupported content type', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/123/messages',
        ['Content-Type' => 'text/xml'],
        '<xml>data</xml>',
    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(415);
    $body = json_decode((string) $response->getBody(), true);
    expect($body['code'])->toBe('unsupported_media_type');
});

test('allows POST with empty body and no content type', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest('POST', '/api/v1/sessions');

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('allows PUT with application/json', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest(
        'PUT',
        '/api/v1/resource',
        ['Content-Type' => 'application/json'],
        '{}',
    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});

test('allows PATCH with application/vnd.api+json', function () {
    $middleware = new ContentTypeMiddleware();
    $request = new ServerRequest(
        'PATCH',
        '/api/v1/resource',
        ['Content-Type' => 'application/vnd.api+json'],
        '{}',
    );

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
});
