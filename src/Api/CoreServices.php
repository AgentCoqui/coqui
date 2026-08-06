<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Read-only handle to the core collaborators a mod-provided API feature needs.
 * Deliberately minimal — widen only when a future feature-mod requires more.
 */
final readonly class CoreServices
{
    public function __construct(
        private SessionStorage $sessionStorage,
        private PersonaDiscovery $personaDiscovery,
        private OpenClawConfig $config,
    ) {}

    public function sessionStorage(): SessionStorage
    {
        return $this->sessionStorage;
    }

    public function pdo(): \PDO
    {
        return $this->sessionStorage->getPdo();
    }

    public function personaDiscovery(): PersonaDiscovery
    {
        return $this->personaDiscovery;
    }

    public function config(): OpenClawConfig
    {
        return $this->config;
    }
}
