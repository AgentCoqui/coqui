<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\CommandCatalogHandler;
use React\Http\Message\ServerRequest;

test('command catalog handler returns grouped runtime command metadata', function () {
    $handler = new CommandCatalogHandler();

    $response = $handler->get(new ServerRequest('GET', '/api/v1/server/commands'));
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body['count'])->toBeGreaterThan(0);
    expect($body['sections'])->not->toBeEmpty();
    expect($body['commands'])->not->toBeEmpty();

    $commandNames = array_column($body['commands'], 'name');
    expect($commandNames)->toContain('/help');
    expect($commandNames)->toContain('/prompt');

    $helpCommand = null;
    foreach ($body['commands'] as $command) {
        if (($command['name'] ?? null) === '/help') {
            $helpCommand = $command;
            break;
        }
    }

    expect($helpCommand)->not->toBeNull();
    expect($helpCommand['usage'])->toBe('/help');
    expect($helpCommand['section'])->toBe('System & Exit');

    $sectionNames = array_column($body['sections'], 'name');
    expect($sectionNames)->toContain('Context & Inspection');
});