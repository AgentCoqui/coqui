<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\AuditHandler;
use CoquiBot\Coqui\Storage\AuditLogStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

covers(AuditHandler::class);

function auditHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-audit-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new AuditLogStore($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new AuditHandler($store, $storage),
    ];
}

test('GET /api/v1/audit returns the paginated envelope', function (): void {
    $f = auditHandlerFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 3; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        $response = $f['handler']->list(new ServerRequest('GET', '/api/v1/audit'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body)->toHaveKeys(['entries', 'total', 'limit', 'offset']);
        expect($body['entries'])->toHaveCount(3);
        expect($body['total'])->toBe(3);
        expect($body['limit'])->toBe(100);
        expect($body['offset'])->toBe(0);
        expect($body['entries'][0]['arguments'])->toBeArray();
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('total reflects the filtered set, not the returned page', function (): void {
    $f = auditHandlerFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 6; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        $request = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['limit' => '2']);
        $body = json_decode((string) $f['handler']->list($request)->getBody(), true);

        expect($body['entries'])->toHaveCount(2);
        expect($body['total'])->toBe(6);
        expect($body['limit'])->toBe(2);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('filters are applied from query parameters', function (): void {
    $f = auditHandlerFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $f['storage']->logAudit($sessionId, 'exec', ['n' => 1], 'auto_approved');
        $f['storage']->logAudit($sessionId, 'exec', ['n' => 2], 'blocked');
        $f['storage']->logAudit($sessionId, 'write_file', ['n' => 3], 'auto_approved');

        $byTool = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['tool_name' => 'exec']);
        expect(json_decode((string) $f['handler']->list($byTool)->getBody(), true)['total'])->toBe(2);

        $byAction = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['action' => 'blocked']);
        expect(json_decode((string) $f['handler']->list($byAction)->getBody(), true)['total'])->toBe(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('an invalid timestamp returns 400 validation_error', function (): void {
    $f = auditHandlerFixture();

    try {
        $request = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['after' => 'nonsense']);
        $response = $f['handler']->list($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('session-scoped route echoes the session id and scopes results', function (): void {
    $f = auditHandlerFixture();

    try {
        $a = $f['storage']->createSession('orchestrator', 'test/model');
        $b = $f['storage']->createSession('orchestrator', 'test/model');
        $f['storage']->logAudit($a, 'exec', ['n' => 1], 'auto_approved');
        $f['storage']->logAudit($b, 'exec', ['n' => 2], 'auto_approved');

        $response = $f['handler']->listForSession(new ServerRequest('GET', "/api/v1/sessions/{$a}/audit"), $a);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['session_id'])->toBe($a);
        expect($body['total'])->toBe(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('session-scoped route returns 404 for an unknown session', function (): void {
    $f = auditHandlerFixture();

    try {
        $response = $f['handler']->listForSession(new ServerRequest('GET', '/api/v1/sessions/nope/audit'), 'nope');

        expect($response->getStatusCode())->toBe(404);
        expect(json_decode((string) $response->getBody(), true)['code'])->toBe('session_not_found');
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('a session_id query parameter cannot widen a session-scoped request', function (): void {
    $f = auditHandlerFixture();

    try {
        $a = $f['storage']->createSession('orchestrator', 'test/model');
        $b = $f['storage']->createSession('orchestrator', 'test/model');
        $f['storage']->logAudit($a, 'exec', ['n' => 1], 'auto_approved');
        $f['storage']->logAudit($b, 'exec', ['n' => 2], 'auto_approved');

        $request = (new ServerRequest('GET', "/api/v1/sessions/{$a}/audit"))
            ->withQueryParams(['session_id' => $b]);

        $body = json_decode((string) $f['handler']->listForSession($request, $a)->getBody(), true);

        expect($body['session_id'])->toBe($a);
        expect($body['total'])->toBe(1);
        expect($body['entries'][0]['session_id'])->toBe($a);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('audit routes are registered as authenticated, never public', function (): void {
    $source = file_get_contents(dirname(__DIR__, 4) . '/src/Api/Handler/AuditHandler.php') ?: '';

    expect($source)->not->toContain('addPublicRoute');
});
