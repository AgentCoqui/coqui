<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

/**
 * Checks whether the Coqui API server is reachable.
 *
 * Used by LoopToolkit and LoopHandler to fail fast when the API is down,
 * preventing loops from being created that can never advance.
 */
final class ApiHealthCheck
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = '3300';
    private const TIMEOUT_SECONDS = 2;

    /**
     * Check if the API server is reachable by hitting the health endpoint.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function check(): array
    {
        $host = self::resolveHost();
        $port = self::resolvePort();
        $url = sprintf('http://%s:%s/api/v1/health', $host, $port);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Cannot reach API server at %s:%s. Loops require the API server for stage execution. '
                    . 'Start Coqui with "make start" or ensure "coqui api" is running.',
                    $host,
                    $port,
                ),
            ];
        }

        // Check HTTP status from response headers
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        if ($status >= 400) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'API server at %s:%s returned HTTP %d. The server may be unhealthy.',
                    $host,
                    $port,
                    $status,
                ),
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    private static function resolveHost(): string
    {
        $env = getenv('COQUI_API_HOST');

        return ($env !== false && $env !== '') ? $env : self::DEFAULT_HOST;
    }

    private static function resolvePort(): string
    {
        $env = getenv('COQUI_API_PORT');

        return ($env !== false && $env !== '') ? $env : self::DEFAULT_PORT;
    }
}
