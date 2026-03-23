<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Webhook;

use CoquiBot\Coqui\Contract\WebhookVerifierInterface;

/**
 * Slack webhook signature verification using Slack signing secret v0.
 *
 * Validates signatures as documented at:
 * https://api.slack.com/authentication/verifying-requests-from-slack
 *
 * Includes 5-minute replay protection via X-Slack-Request-Timestamp.
 */
final readonly class SlackWebhookVerifier implements WebhookVerifierInterface
{
    private const int MAX_TIMESTAMP_AGE_SECONDS = 300;

    public function verify(string $payload, string $secret, array $headers): bool
    {
        $timestamp = $headers['x-slack-request-timestamp'] ?? '';
        $signature = $headers['x-slack-signature'] ?? '';

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        // Replay protection: reject requests older than 5 minutes
        $tsInt = (int) $timestamp;
        if (abs(time() - $tsInt) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            return false;
        }

        $baseString = "v0:{$timestamp}:{$payload}";
        $expected = 'v0=' . hash_hmac('sha256', $baseString, $secret);

        return hash_equals($expected, $signature);
    }

    public function sourceType(): string
    {
        return 'slack';
    }
}
