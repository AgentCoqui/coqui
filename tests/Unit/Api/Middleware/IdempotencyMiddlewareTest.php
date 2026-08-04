<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\IdempotencyMiddleware;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\IdempotencyStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

/**
 * @return array{IdempotencyMiddleware, string}
 */
function makeIdempotencyMiddleware(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-idem-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $mw = new IdempotencyMiddleware(
        new IdempotencyStore($storage->getPdo()),
        [
            ['method' => 'POST', 'path' => '/api/v1/sessions'],
            ['method' => 'POST', 'path' => '/api/v1/sessions/{id}/messages'],
        ],
    );

    return [$mw, $dbPath];
}

test('a repeated creator request with the same key runs the handler once and replays the first response', function () {
    [$mw, $dbPath] = makeIdempotencyMiddleware();
    try {
        $calls = 0;
        $next = function () use (&$calls): Response {
            $calls++;
            return Router::jsonResponse(['id' => 'sess-' . $calls], 201);
        };

        $request = (new ServerRequest('POST', '/api/v1/sessions'))->withHeader('Idempotency-Key', 'abc');

        $first = $mw($request, $next);
        $second = $mw($request, $next);

        expect($calls)->toBe(1);
        expect($first->getStatusCode())->toBe(201);
        expect($second->getStatusCode())->toBe(201);
        expect((string) $second->getBody())->toBe((string) $first->getBody());
        expect((string) $second->getBody())->toBe('{"id":"sess-1"}');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('a creator request with no Idempotency-Key runs the handler every time', function () {
    [$mw, $dbPath] = makeIdempotencyMiddleware();
    try {
        $calls = 0;
        $next = function () use (&$calls): Response {
            $calls++;
            return Router::jsonResponse(['id' => 'sess-' . $calls], 201);
        };

        $request = new ServerRequest('POST', '/api/v1/sessions');

        $mw($request, $next);
        $mw($request, $next);

        expect($calls)->toBe(2);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('a different Idempotency-Key re-invokes the handler', function () {
    [$mw, $dbPath] = makeIdempotencyMiddleware();
    try {
        $calls = 0;
        $next = function () use (&$calls): Response {
            $calls++;
            return Router::jsonResponse(['id' => 'sess-' . $calls], 201);
        };

        $reqA = (new ServerRequest('POST', '/api/v1/sessions'))->withHeader('Idempotency-Key', 'key-a');
        $reqB = (new ServerRequest('POST', '/api/v1/sessions'))->withHeader('Idempotency-Key', 'key-b');

        $mw($reqA, $next);
        $mw($reqB, $next);

        expect($calls)->toBe(2);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('the same key on a different creator route does not collide', function () {
    [$mw, $dbPath] = makeIdempotencyMiddleware();
    try {
        $calls = 0;
        $next = function () use (&$calls): Response {
            $calls++;
            return Router::jsonResponse(['id' => 'x-' . $calls], 201);
        };

        $sessions = (new ServerRequest('POST', '/api/v1/sessions'))->withHeader('Idempotency-Key', 'shared');
        $messages = (new ServerRequest('POST', '/api/v1/sessions/s1/messages'))->withHeader('Idempotency-Key', 'shared');

        $mw($sessions, $next);
        $mw($messages, $next);

        // Different route templates ⇒ different tuples ⇒ handler runs for each.
        expect($calls)->toBe(2);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('a non-creator route with an Idempotency-Key passes through without dedup', function () {
    [$mw, $dbPath] = makeIdempotencyMiddleware();
    try {
        $calls = 0;
        $next = function () use (&$calls): Response {
            $calls++;
            return Router::jsonResponse(['ok' => true], 200);
        };

        // GET /sessions is not a creator; the header must not trigger dedup.
        $request = (new ServerRequest('GET', '/api/v1/sessions'))->withHeader('Idempotency-Key', 'abc');

        $mw($request, $next);
        $mw($request, $next);

        expect($calls)->toBe(2);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
