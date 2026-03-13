<?php

declare(strict_types=1);

use CoquiBot\Coqui\Exception\ProviderErrorClassifier;

test('429 is retryable', function () {
    $e = new \RuntimeException('HTTP 429 Too Many Requests');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('500 is retryable', function () {
    $e = new \RuntimeException('HTTP 500 Internal Server Error');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('502 is retryable', function () {
    $e = new \RuntimeException('HTTP 502 Bad Gateway');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('503 is retryable', function () {
    $e = new \RuntimeException('HTTP 503 Service Unavailable');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('408 is retryable', function () {
    $e = new \RuntimeException('HTTP 408 Request Timeout');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('401 is not retryable', function () {
    $e = new \RuntimeException('HTTP 401 Unauthorized');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeFalse();
});

test('403 is not retryable', function () {
    $e = new \RuntimeException('HTTP 403 Forbidden');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeFalse();
});

test('400 is not retryable', function () {
    $e = new \RuntimeException('HTTP 400 Bad Request');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeFalse();
});

test('404 is not retryable', function () {
    $e = new \RuntimeException('HTTP 404 Not Found');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeFalse();
});

test('connection refused is retryable', function () {
    $e = new \RuntimeException('Connection refused');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('DNS resolution failure is retryable', function () {
    $e = new \RuntimeException('Could not resolve host');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('timeout is retryable', function () {
    $e = new \RuntimeException('Operation timed out');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('network error is retryable', function () {
    $e = new \RuntimeException('Network is unreachable');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeTrue();
});

test('generic error is not retryable', function () {
    $e = new \RuntimeException('Something went wrong');
    expect(ProviderErrorClassifier::isRetryable($e))->toBeFalse();
});

// extractStatusCode

test('extracts status code from message', function (string $message, int $expected) {
    $e = new \RuntimeException($message);
    expect(ProviderErrorClassifier::extractStatusCode($e))->toBe($expected);
})->with([
    ['HTTP 429 Too Many Requests', 429],
    ['HTTP 500 Internal Server Error', 500],
    ['HTTP 401 Unauthorized', 401],
    ['Server returned 503', 503],
    ['Error: 404 not found', 404],
]);

test('returns null for no status code', function () {
    $e = new \RuntimeException('Connection refused');
    expect(ProviderErrorClassifier::extractStatusCode($e))->toBeNull();
});

// classify

test('classifies rate limited', function () {
    $e = new \RuntimeException('HTTP 429 Too Many Requests');
    expect(ProviderErrorClassifier::classify($e))->toBe('rate_limited');
});

test('classifies auth error 401', function () {
    $e = new \RuntimeException('HTTP 401 Unauthorized');
    expect(ProviderErrorClassifier::classify($e))->toBe('auth_error');
});

test('classifies auth error 403', function () {
    $e = new \RuntimeException('HTTP 403 Forbidden');
    expect(ProviderErrorClassifier::classify($e))->toBe('auth_error');
});

test('classifies bad request', function () {
    $e = new \RuntimeException('HTTP 400 Bad Request');
    expect(ProviderErrorClassifier::classify($e))->toBe('bad_request');
});

test('classifies not found', function () {
    $e = new \RuntimeException('HTTP 404 Not Found');
    expect(ProviderErrorClassifier::classify($e))->toBe('not_found');
});

test('classifies server error', function () {
    $e = new \RuntimeException('HTTP 502 Bad Gateway');
    expect(ProviderErrorClassifier::classify($e))->toBe('server_error');
});

test('classifies network error', function () {
    $e = new \RuntimeException('Connection refused');
    expect(ProviderErrorClassifier::classify($e))->toBe('network_error');
});

test('classifies unknown error', function () {
    $e = new \RuntimeException('Something weird happened');
    expect(ProviderErrorClassifier::classify($e))->toBe('unknown');
});
