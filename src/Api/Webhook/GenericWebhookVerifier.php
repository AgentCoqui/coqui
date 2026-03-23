<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Webhook;

use CoquiBot\Coqui\Contract\WebhookVerifierInterface;

/**
 * Generic HMAC-SHA256 webhook verification.
 *
 * Expects the signature in one of these headers (checked in order):
 *   X-Webhook-Signature, X-Signature, Authorization (Bearer scheme)
 *
 * Supports optional 5-minute replay protection via X-Webhook-Timestamp.
 */
final readonly class GenericWebhookVerifier implements WebhookVerifierInterface
{
    private const int MAX_TIMESTAMP_AGE_SECONDS = 300;

    private const array SIGNATURE_HEADERS = [
        'x-webhook-signature',
        'x-signature',
    ];

    public function verify(string $payload, string $secret, array $headers): bool
    {
        // Replay protection if timestamp header is present
        $timestamp = $headers['x-webhook-timestamp'] ?? '';
        if ($timestamp !== '') {
            $tsInt = (int) $timestamp;
            if (abs(time() - $tsInt) > self::MAX_TIMESTAMP_AGE_SECONDS) {
                return false;
            }
            // Include timestamp in the signed payload
            $payload = "{$timestamp}.{$payload}";
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        // Check dedicated signature headers
        foreach (self::SIGNATURE_HEADERS as $header) {
            $value = $headers[$header] ?? '';
            if ($value === '') {
                continue;
            }

            // Strip optional "sha256=" prefix
            $candidate = str_starts_with($value, 'sha256=')
                ? substr($value, 7)
                : $value;

            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        // Check Authorization: Bearer scheme
        $auth = $headers['authorization'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            $candidate = substr($auth, 7);
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function sourceType(): string
    {
        return 'generic';
    }
}
