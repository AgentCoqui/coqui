<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\RateLimitMiddleware;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

/**
 * Build a GET request from a fixed simulated client IP so that every call in a
 * test lands in the same token bucket. A long window is used by the
 * limit-exceeded case so per-request (sub-millisecond) refill stays negligible.
 */
function rateLimitRequest(string $path = '/api/v1/sessions', string $ip = '203.0.113.7'): ServerRequest
{
    return new ServerRequest('GET', $path, ['X-Forwarded-For' => $ip]);
}

test('under-limit requests pass and carry rate-limit headers', function () {
    $middleware = new RateLimitMiddleware(5, 3600);

    $response = $middleware(rateLimitRequest(), fn() => new Response(200, [], 'ok'));

    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('X-RateLimit-Limit'))->toBe('5');
    expect($response->hasHeader('X-RateLimit-Remaining'))->toBeTrue();
});

test('exceeding the bucket returns 429 with Retry-After and RATE_LIMITED envelope', function () {
    // Long window => refill during the loop is negligible, so the 3rd request
    // (capacity 2) is deterministically rejected.
    $middleware = new RateLimitMiddleware(2, 3600);

    $last = null;
    for ($i = 0; $i < 3; $i++) {
        $last = $middleware(rateLimitRequest(), fn() => new Response(200, [], 'ok'));
    }

    expect($last->getStatusCode())->toBe(429);
    expect($last->hasHeader('Retry-After'))->toBeTrue();

    $body = json_decode((string) $last->getBody(), true);
    expect($body['code'])->toBe('rate_limited');
    expect($body)->toHaveKey('error');
    expect($body['details']['retry_after'])->toBeInt();
});

test('exempt health path bypasses limiting even when the bucket is empty', function () {
    $middleware = new RateLimitMiddleware(1, 3600);

    // Drain the (shared-IP) bucket on a non-exempt path first.
    $middleware(rateLimitRequest('/api/v1/sessions'), fn() => new Response(200, [], 'ok'));
    $drained = $middleware(rateLimitRequest('/api/v1/sessions'), fn() => new Response(200, [], 'ok'));
    expect($drained->getStatusCode())->toBe(429);

    // The exempt path is served regardless of bucket state.
    $health = $middleware(rateLimitRequest('/api/v1/health'), fn() => new Response(200, [], 'ok'));
    expect($health->getStatusCode())->toBe(200);
    // Exempt paths are not counted, so no rate-limit headers are attached.
    expect($health->hasHeader('X-RateLimit-Limit'))->toBeFalse();
});

test('OPTIONS requests bypass limiting', function () {
    $middleware = new RateLimitMiddleware(1, 3600);

    // Exhaust the bucket via GETs.
    $middleware(rateLimitRequest(), fn() => new Response(200, [], 'ok'));
    $blocked = $middleware(rateLimitRequest(), fn() => new Response(200, [], 'ok'));
    expect($blocked->getStatusCode())->toBe(429);

    $options = new ServerRequest('OPTIONS', '/api/v1/sessions', ['X-Forwarded-For' => '203.0.113.7']);
    $response = $middleware($options, fn() => new Response(204, [], ''));
    expect($response->getStatusCode())->toBe(204);
});

test('constructor rejects a non-positive maxRequests', function () {
    new RateLimitMiddleware(0, 60);
})->throws(InvalidArgumentException::class);

test('constructor rejects a non-positive windowSeconds', function () {
    new RateLimitMiddleware(2, 0);
})->throws(InvalidArgumentException::class);
