<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\AgentFiberExecutor;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Server status and control endpoints.
 *
 * GET  /api/server/info   — version, uptime, active sessions/tasks
 * GET  /api/server/stats  — database-level statistics
 */
final readonly class ServerHandler
{
    public function __construct(
        private SessionStorage $storage,
        private float $startTime,
        private AgentFiberExecutor $executor,
        private ?BackgroundTaskManager $taskManager = null,
    ) {}

    /**
     * GET /api/server/info — runtime info (version, uptime, load).
     */
    public function info(ServerRequestInterface $request): Response
    {
        $uptimeSeconds = (int) (microtime(true) - $this->startTime);

        $data = [
            'version' => self::version(),
            'php_version' => PHP_VERSION,
            'uptime_seconds' => $uptimeSeconds,
            'active_sessions' => $this->executor->activeCount(),
            'memory' => [
                'usage_bytes' => memory_get_usage(true),
                'peak_bytes' => memory_get_peak_usage(true),
            ],
        ];

        if ($this->taskManager !== null) {
            $data['tasks'] = [
                'active' => $this->taskManager->activeCount(),
                'pending' => $this->taskManager->pendingCount(),
            ];
        }

        return Router::jsonResponse($data);
    }

    /**
     * GET /api/server/stats — persistent database statistics.
     */
    public function stats(ServerRequestInterface $request): Response
    {
        $dbStats = $this->storage->getDatabaseStats();
        $tableCheck = $this->storage->checkTablesExist();

        return Router::jsonResponse([
            'database' => $dbStats,
            'tables' => $tableCheck,
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
