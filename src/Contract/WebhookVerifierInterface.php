<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Verifies incoming webhook request signatures.
 *
 * Each implementation handles a specific signature scheme
 * (GitHub HMAC-SHA256, Slack signing secret, generic HMAC, etc.).
 */
interface WebhookVerifierInterface
{
    /**
     * Verify that the incoming request is authentic.
     *
     * @param string $payload    Raw request body
     * @param string $secret     Shared signing secret
     * @param array<string, string> $headers  Request headers (lowercase keys)
     */
    public function verify(string $payload, string $secret, array $headers): bool;

    /**
     * The source type this verifier handles (e.g. 'github', 'slack', 'generic').
     */
    public function sourceType(): string;
}
