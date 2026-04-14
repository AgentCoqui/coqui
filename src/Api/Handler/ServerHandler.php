<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Agent\QualityAutomationStatusService;
use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\SessionStorage;
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
        private ?QualityAutomationStatusService $qualityAutomation = null,
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

        $quality = $this->qualitySummary();
        if ($quality !== null) {
            $data['quality_automation'] = $quality;
        }

        return Router::jsonResponse($data);
    }

    /**
     * GET /api/v1/server/quality — detailed quality automation state.
     */
    public function quality(ServerRequestInterface $request): Response
    {
        if ($this->qualityAutomation === null) {
            return Router::jsonResponse(['available' => false]);
        }

        return Router::jsonResponse($this->qualityAutomation->summary());
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
        $composerJson = dirname(__DIR__, 3) . '/composer.json';

        if (!file_exists($composerJson)) {
            return 'dev';
        }

        $data = json_decode(file_get_contents($composerJson) ?: '{}', true);

        return is_array($data) && isset($data['version']) ? (string) $data['version'] : 'dev';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function qualitySummary(): ?array
    {
        if ($this->qualityAutomation === null) {
            return null;
        }

        $summary = $this->qualityAutomation->summary();
        $presentSchedules = array_values(array_filter(
            $summary['schedules'],
            static fn(array $schedule): bool => (bool) $schedule['exists'],
        ));

        return [
            'enabled' => $summary['enabled'],
            'configured_schedules' => count($summary['schedules']),
            'present_schedules' => count($presentSchedules),
            'enabled_schedules' => count(array_filter(
                $presentSchedules,
                static fn(array $schedule): bool => (bool) $schedule['enabled'],
            )),
            'linked_follow_ups' => $summary['follow_ups']['counts']['linked'],
            'active_follow_ups' => count($summary['follow_ups']['active']),
        ];
    }
}
