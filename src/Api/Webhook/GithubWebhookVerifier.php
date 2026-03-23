<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Webhook;

use CoquiBot\Coqui\Contract\WebhookVerifierInterface;

/**
 * GitHub webhook signature verification using X-Hub-Signature-256.
 *
 * Validates HMAC-SHA256 signatures as documented at:
 * https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries
 */
final readonly class GithubWebhookVerifier implements WebhookVerifierInterface
{
    public function verify(string $payload, string $secret, array $headers): bool
    {
        $signature = $headers['x-hub-signature-256'] ?? '';
        if ($signature === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function sourceType(): string
    {
        return 'github';
    }
}
