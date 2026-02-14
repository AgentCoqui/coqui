<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares a credential that a tool or toolkit requires to function.
 *
 * Used by CredentialGuardTool/CredentialGuardToolkit to check availability
 * before execution and provide actionable instructions to the LLM when
 * credentials are missing.
 */
final readonly class CredentialRequirement
{
    /**
     * @param string $name        Environment variable name (e.g. BRAVE_SEARCH_API_KEY)
     * @param string $description Human-readable description including where to obtain the credential
     */
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
