<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Support\RuntimeIdentity;

/**
 * Checks whether the Coqui API server is reachable.
 *
 * Used by LoopToolkit and LoopHandler to fail fast when the API is down,
 * preventing loops from being created that can never advance.
 */
final class ApiHealthCheck
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const TIMEOUT_SECONDS = CoquiDefaults::HEALTH_CHECK_TIMEOUT_SECONDS;

    /**
     * Check if the API server is reachable by hitting the health endpoint.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function check(
        ?string $expectedWorkspacePath = null,
        bool $requireTaskManager = false,
        bool $requireLoopManager = false,
    ): array
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

        set_error_handler(static fn(): bool => true);
        try {
            $result = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Cannot reach API server at %s:%s. Loops require the API server for stage execution. '
                    . 'Start Coqui with "coqui" or ensure "coqui --api-only" is running.',
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

        $payload = json_decode($result, true);
        if (!is_array($payload)) {
            return [
                'ok' => false,
                'error' => sprintf('API server at %s:%s returned an invalid health payload.', $host, $port),
            ];
        }

        return self::validatePayload($payload, $expectedWorkspacePath, $requireTaskManager, $requireLoopManager);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, error: ?string}
     */
    public static function validatePayload(
        array $payload,
        ?string $expectedWorkspacePath = null,
        bool $requireTaskManager = false,
        bool $requireLoopManager = false,
    ): array {
        if (($payload['status'] ?? null) !== 'ok') {
            return ['ok' => false, 'error' => 'API server reported an unhealthy status.'];
        }

        if ($expectedWorkspacePath !== null && $expectedWorkspacePath !== '') {
            $expectedWorkspaceId = RuntimeIdentity::fingerprintPath($expectedWorkspacePath);
            $remoteWorkspaceId = is_string($payload['workspace_id'] ?? null) ? $payload['workspace_id'] : '';

            if ($remoteWorkspaceId === '' || !hash_equals($expectedWorkspaceId, $remoteWorkspaceId)) {
                return [
                    'ok' => false,
                    'error' => 'API server is running against a different workspace/database context than the current REPL session.',
                ];
            }
        }

        if ($requireTaskManager) {
            $taskManager = self::managerPayload($payload, 'tasks');
            if ($taskManager === null || ($taskManager['ready'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'error' => 'API background task manager is not dispatch-ready. Background tasks will not start until the API worker is healthy.',
                ];
            }
        }

        if ($requireLoopManager) {
            $loopManager = self::managerPayload($payload, 'loops');
            if ($loopManager === null || ($loopManager['ready'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'error' => 'API loop manager is not dispatch-ready. Loops cannot advance stages until the API worker is healthy.',
                ];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function managerPayload(array $payload, string $name): ?array
    {
        $managers = $payload['managers'] ?? null;
        if (!is_array($managers)) {
            return null;
        }

        $manager = $managers[$name] ?? null;

        return is_array($manager) ? $manager : null;
    }

    private static function resolveHost(): string
    {
        $env = getenv('COQUI_API_HOST');

        return ($env !== false && $env !== '') ? $env : self::DEFAULT_HOST;
    }

    private static function resolvePort(): string
    {
        $env = getenv('COQUI_API_PORT');

        return ($env !== false && $env !== '') ? $env : (string) CoquiDefaults::API_DEFAULT_PORT;
    }
}
