<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Tool\PackagistTool;
use CoquiBot\Coqui\Toolkit\PackagistToolkit;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// ---------------------------------------------------------------
// Toolkit registration
// ---------------------------------------------------------------

test('PackagistToolkit registers packagist tool', function () {
    $toolkit = new PackagistToolkit();
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(1);
    expect($tools[0]->name())->toBe('packagist');
});

test('PackagistToolkit has guidelines', function () {
    $toolkit = new PackagistToolkit();

    expect($toolkit->guidelines())->toContain('packagist');
    expect($toolkit->guidelines())->not->toBeEmpty();
});

// ---------------------------------------------------------------
// PackagistTool — schema
// ---------------------------------------------------------------

test('packagist tool has correct function schema', function () {
    $tool = new PackagistTool();
    $schema = $tool->toFunctionSchema();

    expect($schema['function']['name'])->toBe('packagist');
    expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    expect($schema['function']['parameters']['properties'])->toHaveKey('query');
    expect($schema['function']['parameters']['properties'])->toHaveKey('package');
    expect($schema['function']['parameters']['required'])->toContain('action');
});

// ---------------------------------------------------------------
// PackagistTool — search action
// ---------------------------------------------------------------

test('search requires query parameter', function () {
    $tool = new PackagistTool();
    $result = $tool->execute(['action' => 'search']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('query');
});

test('search returns formatted results', function () {
    $mockResponse = new MockResponse(json_encode([
        'results' => [
            [
                'name' => 'monolog/monolog',
                'description' => 'Sends your logs to files, sockets, etc.',
                'url' => 'https://packagist.org/packages/monolog/monolog',
                'repository' => 'https://github.com/Seldaek/monolog',
                'downloads' => 150_000_000,
                'favers' => 9_500,
            ],
        ],
        'total' => 1,
    ]));

    $httpClient = new MockHttpClient($mockResponse);
    $tool = new PackagistTool($httpClient);

    $result = $tool->execute([
        'action' => 'search',
        'query' => 'monolog',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('monolog/monolog');
    expect($result->content)->toContain('Sends your logs');
});

test('search handles empty results', function () {
    $mockResponse = new MockResponse(json_encode([
        'results' => [],
        'total' => 0,
    ]));

    $httpClient = new MockHttpClient($mockResponse);
    $tool = new PackagistTool($httpClient);

    $result = $tool->execute([
        'action' => 'search',
        'query' => 'nonexistentzzzpackage',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No packages found');
});

// ---------------------------------------------------------------
// PackagistTool — details action
// ---------------------------------------------------------------

test('details requires package parameter', function () {
    $tool = new PackagistTool();
    $result = $tool->execute(['action' => 'details']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('package');
});

test('details returns formatted package info', function () {
    $packageData = [
        'package' => [
            'name' => 'monolog/monolog',
            'description' => 'Sends your logs to files, sockets, etc.',
            'time' => '2011-01-01T00:00:00+00:00',
            'maintainers' => [
                ['name' => 'Seldaek'],
            ],
            'versions' => [
                '3.0.0' => [
                    'version' => '3.0.0',
                    'version_normalized' => '3.0.0.0',
                    'require' => ['php' => '>=8.1'],
                    'time' => '2024-01-01T00:00:00+00:00',
                ],
                '2.9.3' => [
                    'version' => '2.9.3',
                    'version_normalized' => '2.9.3.0',
                    'time' => '2024-06-01T00:00:00+00:00',
                ],
            ],
            'github_stars' => 21_000,
            'github_forks' => 1_800,
            'downloads' => [
                'total' => 150_000_000,
                'monthly' => 5_000_000,
                'daily' => 170_000,
            ],
            'type' => 'library',
            'repository' => 'https://github.com/Seldaek/monolog',
            'language' => 'PHP',
        ],
    ];

    $securityData = ['advisories' => []];

    $httpClient = new MockHttpClient([
        new MockResponse(json_encode($packageData)),
        new MockResponse(json_encode($securityData)),
    ]);

    $tool = new PackagistTool($httpClient);
    $result = $tool->execute([
        'action' => 'details',
        'package' => 'monolog/monolog',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('monolog/monolog');
    expect($result->content)->toContain('3.0.0');
});

// ---------------------------------------------------------------
// PackagistTool — unknown action
// ---------------------------------------------------------------

test('unknown action returns error', function () {
    $tool = new PackagistTool();
    $result = $tool->execute(['action' => 'foobar']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Unknown action');
});
