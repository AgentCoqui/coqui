<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\ApiLifecycleController;
use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\ChannelManager;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\AppVersion;
use CoquiBot\Coqui\Support\RuntimeIdentity;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Server status and control endpoints.
 *
 * GET  /api/v1/server/info   — version, uptime, active sessions/tasks
 * GET  /api/v1/server/stats  — database-level statistics
 */
final readonly class ServerHandler
{
    public function __construct(
        private SessionStorage $storage,
        private float $startTime,
        private AgentTurnManager $turnManager,
        private string $workspacePath,
        private string $databasePath,
        private ?BackgroundTaskManager $taskManager = null,
        private ?LoopManager $loopManager = null,
        private ?ChannelManager $channelManager = null,
        private ?ApiLifecycleController $lifecycle = null,
    ) {}

    /**
     * GET /api/v1/server/info — runtime info (version, uptime, load).
     */
    public function info(ServerRequestInterface $request): Response
    {
        $uptimeSeconds = (int) (microtime(true) - $this->startTime);

        $data = [
            'version' => self::version(),
            'php_version' => PHP_VERSION,
            'uptime_seconds' => $uptimeSeconds,
            'active_sessions' => $this->turnManager->activeCount(),
            'memory' => [
                'usage_bytes' => memory_get_usage(true),
                'peak_bytes' => memory_get_peak_usage(true),
            ],
            'runtime' => [
                'workspace_id' => RuntimeIdentity::fingerprintPath($this->workspacePath),
                'database_id' => RuntimeIdentity::fingerprintPath($this->databasePath),
            ],
        ];

        if ($this->taskManager !== null) {
            $data['tasks'] = [
                'active' => $this->taskManager->activeCount(),
                'pending' => $this->taskManager->pendingCount(),
                'last_tick_at' => $this->taskManager->lastTickAt(),
            ];
        }

        if ($this->loopManager !== null) {
            $data['loops'] = [
                'last_tick_at' => $this->loopManager->lastTickAt(),
                'last_reconcile_at' => $this->loopManager->lastReconcileAt(),
            ];
        }

        if ($this->channelManager !== null) {
            $data['channels'] = [
                'configured' => $this->channelManager->stats()['total'],
                'enabled' => $this->channelManager->stats()['enabled'],
                'ready' => $this->channelManager->stats()['ready'],
                'active_runtimes' => $this->channelManager->stats()['active_runtimes'],
                'registered_drivers' => $this->channelManager->stats()['registered_drivers'],
                'last_tick_at' => $this->channelManager->lastTickAt(),
                'last_reconcile_at' => $this->channelManager->lastReconcileAt(),
            ];
        }

        if ($this->lifecycle !== null) {
            $data['restart'] = $this->lifecycle->restartState();
        }

        return Router::jsonResponse($data);
    }

    /**
     * POST /api/v1/server/restart — restart the launcher-managed API process.
     */
    public function restart(ServerRequestInterface $request): Response
    {
        if ($this->lifecycle === null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Server restart control is unavailable in this environment.');
        }

        $accepted = $this->lifecycle->requestRestart(
            reason: 'API restart requested by operator.',
            source: 'api.server.restart',
        );

        if (!$accepted) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                'API restart is only supported when the server is running under the Coqui launcher.',
                $this->lifecycle->restartState(),
            );
        }

        return Router::jsonResponse([
            'accepted' => true,
            'message' => 'API restart requested.',
            'restart' => $this->lifecycle->restartState(),
        ], 202);
    }

    /**
     * GET /api/v1/server/stats — persistent database statistics.
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
        return AppVersion::current();
    }
}
