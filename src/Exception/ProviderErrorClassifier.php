<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

/**
 * Classifies provider errors as retryable or fatal.
 *
 * Used by FallbackProvider to decide whether to attempt a fallback model
 * when the primary provider fails. Rate limits (429), server errors (5xx),
 * and network timeouts are retryable. Auth errors (401/403) and client
 * errors (400, 404, 422) are fatal.
 */
final class ProviderErrorClassifier
{
    /**
     * Check if the error is retryable (fallback candidate).
     */
    public static function isRetryable(\Throwable $e): bool
    {
        $statusCode = self::extractStatusCode($e);

        if ($statusCode !== null) {
            return match (true) {
                $statusCode === 429 => true,                    // Rate limited
                $statusCode >= 500 && $statusCode < 600 => true, // Server error
                $statusCode === 408 => true,                    // Request timeout
                default => false,
            };
        }

        // Network-level errors (connection refused, DNS, timeout)
        $message = strtolower($e->getMessage());
        return str_contains($message, 'timeout')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'could not resolve')
            || str_contains($message, 'network')
            || str_contains($message, 'timed out');
    }

    /**
     * Extract HTTP status code from the exception, if available.
     */
    public static function extractStatusCode(\Throwable $e): ?int
    {
        if ($e instanceof ClientExceptionInterface || $e instanceof ServerExceptionInterface) {
            return $e->getResponse()->getStatusCode();
        }

        // Check for status code in exception message (common pattern)
        if (preg_match('/\b(4\d{2}|5\d{2})\b/', $e->getMessage(), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get a human-readable classification of the error.
     */
    public static function classify(\Throwable $e): string
    {
        $statusCode = self::extractStatusCode($e);

        return match (true) {
            $statusCode === 429 => 'rate_limited',
            $statusCode === 401 || $statusCode === 403 => 'auth_error',
            $statusCode === 400 => 'bad_request',
            $statusCode === 404 => 'not_found',
            $statusCode !== null && $statusCode >= 500 => 'server_error',
            $statusCode === 408 => 'timeout',
            self::isRetryable($e) => 'network_error',
            default => 'unknown',
        };
    }
}
