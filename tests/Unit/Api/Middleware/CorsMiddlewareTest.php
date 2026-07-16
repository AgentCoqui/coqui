<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\CorsMiddleware;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

test('emits wildcard CORS headers by default and calls next', function () {
    $middleware = new CorsMiddleware();
    $called = false;
    $request = new ServerRequest('GET', '/api/v1/sessions');

    $response = $middleware($request, function () use (&$called) {
        $called = true;
        return new Response(200, [], 'ok');
    });

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->getHeaderLine('Access-Control-Allow-Methods'))
        ->toBe('GET, POST, PUT, PATCH, DELETE, OPTIONS');
    expect($response->getHeaderLine('Access-Control-Allow-Headers'))
        ->toBe('Content-Type, Authorization, Accept');
    expect($response->getHeaderLine('Access-Control-Max-Age'))->toBe('86400');
});

test('wildcard default does not emit Allow-Credentials (security invariant)', function () {
    $middleware = new CorsMiddleware();
    $request = new ServerRequest('GET', '/api/v1/sessions', ['Origin' => 'https://evil.example']);

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    // A wildcard ACAO combined with credentials is a spec-forbidden, unsafe combo.
    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->hasHeader('Access-Control-Allow-Credentials'))->toBeFalse();
});

test('echoes an allowlisted origin and adds Vary: Origin', function () {
    $middleware = new CorsMiddleware(['https://app.example']);
    $request = new ServerRequest('GET', '/api/v1/sessions', ['Origin' => 'https://app.example']);

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://app.example');
    expect($response->getHeaderLine('Vary'))->toBe('Origin');
});

test('does not reflect a non-allowlisted origin', function () {
    $middleware = new CorsMiddleware(['https://app.example']);
    $request = new ServerRequest('GET', '/api/v1/sessions', ['Origin' => 'https://evil.example']);

    $response = $middleware($request, fn() => new Response(200, [], 'ok'));

    // Arbitrary origins must never be reflected back.
    expect($response->hasHeader('Access-Control-Allow-Origin'))->toBeFalse();
    expect($response->getStatusCode())->toBe(200);
});

test('returns 204 for OPTIONS preflight without calling next', function () {
    $middleware = new CorsMiddleware();
    $called = false;
    $request = new ServerRequest('OPTIONS', '/api/v1/sessions');

    $response = $middleware($request, function () use (&$called) {
        $called = true;
        return new Response(200, [], 'should-not-run');
    });

    expect($called)->toBeFalse();
    expect($response->getStatusCode())->toBe(204);
    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->getHeaderLine('Access-Control-Allow-Methods'))
        ->toBe('GET, POST, PUT, PATCH, DELETE, OPTIONS');
});
