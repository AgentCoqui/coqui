<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\FileUploadHandler;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createFileUploadHandlerFixture(): array
{
    $tmpDir = sys_get_temp_dir() . '/coqui-file-upload-handler-' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0755, true);

    $dbPath = $tmpDir . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $uploadStorage = new FileUploadStorage();

    return [
        'tmpDir' => $tmpDir,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new FileUploadHandler($storage, $uploadStorage),
    ];
}

function cleanupFileUploadHandlerFixture(array $fixture): void
{
    releaseTestObjectProperties((object) $fixture);
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['tmpDir']);
}

test('file upload handler rejects uploads for closed sessions', function () {
    $fixture = createFileUploadHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->closeSession($sessionId, 'history-rollover', true);

        $response = $fixture['handler']->upload(new ServerRequest('POST', '/api/v1/sessions/' . $sessionId . '/files'), $sessionId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('session_closed');
    } finally {
        cleanupFileUploadHandlerFixture($fixture);
    }
});

test('file upload handler rejects reads of a missing ref for hidden sessions', function () {
    $fixture = createFileUploadHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('learner', 'background-task', visibility: 'hidden');

        $response = $fixture['handler']->get(
            new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/files/missing'),
            $sessionId,
            'missing',
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(404);
        expect($body['code'])->toBe('session_not_found');
    } finally {
        cleanupFileUploadHandlerFixture($fixture);
    }
});