<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * D3: resolves the effective filesystem/shell root for a session. A session's
 * opaque `workspace` (when set) wins; otherwise the instance-global workspace
 * is used. Loop stages and child runs inherit whatever a session resolves to.
 */
final readonly class SessionWorkspaceResolver
{
    public function __construct(
        private SessionStorage $storage,
        private string $defaultWorkspace,
    ) {}

    public function resolve(?string $sessionId): string
    {
        if ($sessionId === null) {
            return $this->defaultWorkspace;
        }

        $session = $this->storage->getSession($sessionId);
        $workspace = $session['workspace'] ?? null;

        return is_string($workspace) && $workspace !== '' ? $workspace : $this->defaultWorkspace;
    }
}
