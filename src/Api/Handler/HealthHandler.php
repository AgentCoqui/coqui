<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\AgentFiberExecutor;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * GET /api/health — liveness check with uptime and active session count.
 */
final readonly class HealthHandler
{
    public function __construct(
        private float $startTime,
        private AgentFiberExecutor $executor,
    ) {}

    public function __invoke(ServerRequestInterface $request): Response
    {
        $uptimeSeconds = (int) (microtime(true) - $this->startTime);

        return Router::jsonResponse([
            'status' => 'ok',
            'version' => self::version(),
            'uptime_seconds' => $uptimeSeconds,
            'active_sessions' => $this->executor->activeCount(),
        ]);
    }

    private static function version(): string
    {
        $composerJson = dirname(__DIR__, 3) . '/composer.json';

        if (!file_exists($composerJson)) {
            return 'dev';
        }

        $data = json_decode(file_get_contents($composerJson) ?: '{}', true);

        return is_array($data) && isset($data['version']) ? (string) $data['version'] : 'dev';
    }
}
