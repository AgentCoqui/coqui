<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ApiFeatureDiscovery;
use CoquiBot\Coqui\Contract\ApiFeatureInterface;

// Global-namespace fake so class_exists() resolves it by name.
if (!class_exists('FakePingFeature')) {
    class FakePingFeature implements ApiFeatureInterface
    {
        public function register(Router $router, CoreServices $services): void
        {
            $router->get('/api/v1/__ping', static fn () => new \React\Http\Message\Response(200, [], 'pong'));
        }
    }
}

if (!class_exists('FakeThrowingFeature')) {
    class FakeThrowingFeature implements ApiFeatureInterface
    {
        public function register(Router $router, CoreServices $services): void
        {
            throw new \RuntimeException('boom');
        }
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-apifeature-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('discover returns empty when installed.json is missing', function () {
    expect((new ApiFeatureDiscovery($this->tempDir))->discover())->toBe([]);
});

test('discover instantiates a declared api feature', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/example',
            'extra' => ['php-agents' => ['apiFeatures' => ['FakePingFeature']]],
        ]],
    ]));

    $result = (new ApiFeatureDiscovery($this->tempDir))->discover();

    expect($result)->toHaveCount(1);
    expect($result[0])->toBeInstanceOf(FakePingFeature::class);
});

test('a discovered feature registers routes on the Router', function () {
    $router = new Router();
    $dbPath = sys_get_temp_dir() . '/coqui-apifeat-' . bin2hex(random_bytes(8)) . '.db';

    try {
        $storage = new \CoquiBot\Coqui\Storage\SessionStorage($dbPath);
        $services = new CoreServices(
            $storage,
            new \CoquiBot\Coqui\Config\ProfileDiscovery(sys_get_temp_dir()),
            \CoquiBot\Coqui\Config\OpenClawConfig::fromArray([])
        );

        (new FakePingFeature())->register($router, $services);
        $response = $router->dispatch(new \React\Http\Message\ServerRequest('GET', '/api/v1/__ping'));
        expect($response->getStatusCode())->toBe(200);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('registerAll isolates a throwing feature and continues past it', function () {
    $router = new Router();
    $dbPath = sys_get_temp_dir() . '/coqui-apifeat-' . bin2hex(random_bytes(8)) . '.db';

    try {
        $storage = new \CoquiBot\Coqui\Storage\SessionStorage($dbPath);
        $services = new CoreServices(
            $storage,
            new \CoquiBot\Coqui\Config\ProfileDiscovery(sys_get_temp_dir()),
            \CoquiBot\Coqui\Config\OpenClawConfig::fromArray([])
        );

        $errors = [];
        (new ApiFeatureDiscovery($this->tempDir))->registerAll(
            [new FakeThrowingFeature(), new FakePingFeature()],
            $router,
            $services,
            function (ApiFeatureInterface $feature, \Throwable $e) use (&$errors) {
                $errors[] = $feature::class . ': ' . $e->getMessage();
            },
        );

        // The good feature after the throwing one still registered its route.
        $response = $router->dispatch(new \React\Http\Message\ServerRequest('GET', '/api/v1/__ping'));
        expect($response->getStatusCode())->toBe(200);
        // The failure was reported exactly once, with the offending class + message.
        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('FakeThrowingFeature')->toContain('boom');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('discover skips classes that do not implement ApiFeatureInterface', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/bad',
            'extra' => ['php-agents' => ['apiFeatures' => ['stdClass', 'Nonexistent\\Class']]],
        ]],
    ]));

    expect((new ApiFeatureDiscovery($this->tempDir))->discover())->toBe([]);
});
