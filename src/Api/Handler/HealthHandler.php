<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiLifecycleController;
use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Support\AppVersion;
use CoquiBot\Coqui\Support\RuntimeIdentity;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * GET /api/v1/health — liveness check with uptime and active session count.
 */
final readonly class HealthHandler
{
    public function __construct(
        private float $startTime,
        private AgentTurnManager $turnManager,
        private string $workspacePath,
        private string $databasePath,
        private ?BackgroundTaskManager $taskManager = null,
        private ?LoopManager $loopManager = null,
        private ?ScheduleStore $scheduleStore = null,
        private ?ApiLifecycleController $lifecycle = null,
    ) {}

    public function __invoke(ServerRequestInterface $request): Response
    {
        $uptimeSeconds = (int) (microtime(true) - $this->startTime);

        $data = [
            'status' => 'ok',
            'version' => self::version(),
            'uptime_seconds' => $uptimeSeconds,
            'active_sessions' => $this->turnManager->activeCount(),
            'workspace_id' => RuntimeIdentity::fingerprintPath($this->workspacePath),
            'database_id' => RuntimeIdentity::fingerprintPath($this->databasePath),
        ];

        $data['managers'] = [
            'tasks' => $this->managerSummary($this->taskManager !== null, $this->taskManager?->lastTickAt()),
            'loops' => $this->managerSummary($this->loopManager !== null, $this->loopManager?->lastTickAt(), $this->loopManager?->lastReconcileAt()),
        ];

        if ($this->taskManager !== null) {
            $data['active_tasks'] = $this->taskManager->activeCount();
            $data['pending_tasks'] = $this->taskManager->pendingCount();
        }

        if ($this->scheduleStore !== null) {
            $stats = $this->scheduleStore->getStats();
            $upcoming = $this->scheduleStore->getUpcoming(1);
            $stats['next_run_at'] = $upcoming !== [] ? ($upcoming[0]['next_run_at'] ?? null) : null;
            $data['schedules'] = $stats;
        }

        if ($this->lifecycle !== null) {
            $data['restart'] = $this->lifecycle->restartState();
        }

        return Router::jsonResponse($data);
    }

    private static function version(): string
    {
        return AppVersion::current();
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function managerSummary(bool $available, ?string $lastTickAt, ?string $lastReconcileAt = null): array
    {
        $summary = [
            'available' => $available,
            'ready' => $available && $this->isManagerReady($lastTickAt),
            'last_tick_at' => $lastTickAt,
        ];

        if ($lastReconcileAt !== null) {
            $summary['last_reconcile_at'] = $lastReconcileAt;
        }

        return $summary;
    }

    private function isManagerReady(?string $lastTickAt): bool
    {
        if ($lastTickAt === null) {
            return (microtime(true) - $this->startTime) < 10.0;
        }

        try {
            $tickTime = new \DateTimeImmutable($lastTickAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return (time() - $tickTime->getTimestamp()) <= 15;
    }
}
