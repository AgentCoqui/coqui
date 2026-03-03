<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares a credential that a tool or toolkit requires to function.
 *
 * Used by CredentialGuardTool/CredentialGuardToolkit to check availability
 * before execution and provide actionable instructions to the LLM when
 * credentials are missing.
 *
 * Optional credentials do not block tool execution when missing — they are
 * still surfaced in guideline status so the LLM knows they can be configured.
 */
final readonly class CredentialRequirement
{
    /**
     * @param string $name        Environment variable name (e.g. BRAVE_SEARCH_API_KEY)
     * @param string $description Human-readable description including where to obtain the credential
     * @param bool   $optional    When true, missing credential does not block tool execution
     */
    public function __construct(
        public string $name,
        public string $description,
        public bool $optional = false,
    ) {}
}
