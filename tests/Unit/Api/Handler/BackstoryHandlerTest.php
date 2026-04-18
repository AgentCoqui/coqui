<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\BackstoryHandler;
use CoquiBot\Coqui\Backstory\BackstoryAssembler;
use CoquiBot\Coqui\Backstory\BackstoryInspectionService;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use React\Http\Message\ServerRequest;

function createBackstoryHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-backstory-handler-' . bin2hex(random_bytes(8));
    $profilePath = $workspacePath . '/profiles/caelum';
    mkdir($profilePath . '/backstory/nested', 0755, true);
    file_put_contents($profilePath . '/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($profilePath . '/backstory/intro.md', "# Intro\n\nCaelum has a long memory.");
    file_put_contents($profilePath . '/backstory/nested/notes.txt', "Prefers reflective conversations.");
    file_put_contents($profilePath . '/backstory/image.png', random_bytes(32));

    $assembler = new BackstoryAssembler();
    $assembler->generate($profilePath);

    $profileDiscovery = new ProfileDiscovery($workspacePath);
    $inspectionService = new BackstoryInspectionService($workspacePath, $profileDiscovery, $assembler);

    return [
        'workspacePath' => $workspacePath,
        'handler' => new BackstoryHandler($inspectionService),
    ];
}

function cleanupBackstoryHandlerFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
}

test('backstory handler returns explicit unavailable metadata when no profile is selected', function () {
    $fixture = createBackstoryHandlerFixture();

    try {
        $response = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/server/backstory'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['available'])->toBeFalse();
        expect($body['reason'])->toBe('no_active_profile');
        expect($body['content'])->toBeNull();
        expect($body['files'])->toBe([]);
    } finally {
        cleanupBackstoryHandlerFixture($fixture);
    }
});

test('backstory handler returns generated backstory content and breakdown metadata', function () {
    $fixture = createBackstoryHandlerFixture();

    try {
        $response = $fixture['handler']->get(
            (new ServerRequest('GET', '/api/v1/server/backstory'))->withQueryParams(['profile' => 'caelum'])
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['available'])->toBeTrue();
        expect($body['profile'])->toBe('caelum');
        expect($body['source_folder'])->toBe('profiles/caelum/backstory');
        expect($body['generated_backstory_path'])->toBe('profiles/caelum/backstory.md');
        expect($body['content'])->toContain('## Backstory');
        expect($body['supported_file_count'])->toBe(2);
        expect($body['successful_file_count'])->toBe(2);
        expect($body['unsupported_file_count'])->toBe(1);
        expect($body['failed_file_count'])->toBe(0);
        expect($body['total_tokens'])->toBeGreaterThan(0);
        expect($body['files'])->toHaveCount(2);
        expect($body['unsupported_files'])->toHaveCount(1);
        expect($body['folders'])->not->toBeEmpty();
        expect($body['last_modified_at'])->not->toBeNull();
        expect($body['needs_regeneration'])->toBeFalse();

        $filePaths = array_column($body['files'], 'path');
        $folderPaths = array_column($body['folders'], 'path');

        expect($filePaths)->toContain('profiles/caelum/backstory/intro.md');
        expect($filePaths)->toContain('profiles/caelum/backstory/nested/notes.txt');
        expect($folderPaths)->toContain('');
        expect($folderPaths)->toContain('nested');
    } finally {
        cleanupBackstoryHandlerFixture($fixture);
    }
});

test('backstory handler rejects unknown profiles', function () {
    $fixture = createBackstoryHandlerFixture();

    try {
        $response = $fixture['handler']->get(
            (new ServerRequest('GET', '/api/v1/server/backstory'))->withQueryParams(['profile' => 'missing'])
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
        expect($body['error'])->toContain('Unknown profile');
    } finally {
        cleanupBackstoryHandlerFixture($fixture);
    }
});