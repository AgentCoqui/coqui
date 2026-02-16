<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Resolves credentials from workspace .env and process environment.
 *
 * Provides hot-reload: after set() is called, the credential is immediately
 * available to all tools via get()/has() without restarting the session.
 */
interface CredentialResolverInterface
{
    /**
     * Get a credential value by key name.
     *
     * Checks workspace .env first, then falls back to process environment.
     * Returns null if the credential is not found in either source.
     */
    public function get(string $key): ?string;

    /**
     * Check if a credential exists and has a non-empty value.
     */
    public function has(string $key): bool;

    /**
     * Persist a credential to the workspace .env and update the process environment.
     *
     * After calling set(), the credential is immediately available via getenv()
     * for any toolkit using lazy resolution.
     */
    public function set(string $key, string $value): void;

    /**
     * Remove a credential from the workspace .env and process environment.
     */
    public function delete(string $key): void;

    /**
     * Load all workspace .env entries into the process environment.
     *
     * Called once at boot so that ToolkitDiscovery's fromEnv() factories
     * see workspace credentials via getenv().
     */
    public function loadIntoProcessEnv(): void;

    /**
     * Get all stored credential key names (no values).
     *
     * @return string[]
     */
    public function keys(): array;

    /**
     * Get the path to the .env file.
     */
    public function envPath(): string;
}
