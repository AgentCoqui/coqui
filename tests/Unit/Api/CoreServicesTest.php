<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;

test('CoreServices exposes core collaborators', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-coreservices-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([]);
    $profileDiscovery = new PersonaDiscovery(sys_get_temp_dir());

    try {
        $services = new CoreServices($storage, $profileDiscovery, $config);

        expect($services->sessionStorage())->toBe($storage);
        expect($services->pdo())->toBe($storage->getPdo());
        expect($services->profileDiscovery())->toBe($profileDiscovery);
        expect($services->config())->toBe($config);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
