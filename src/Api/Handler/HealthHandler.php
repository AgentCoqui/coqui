<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\WebhookStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * GET /api/health — liveness check with uptime and active session count.
 */
final readonly class HealthHandler
{
    public function __construct(
        private float $startTime,
        private AgentTurnManager $turnManager,
        private ?BackgroundTaskManager $taskManager = null,
        private ?ScheduleStore $scheduleStore = null,
        private ?WebhookStore $webhookStore = null,
    ) {}

    public function __invoke(ServerRequestInterface $request): Response
    {
        $uptimeSeconds = (int) (microtime(true) - $this->startTime);

        $data = [
            'status' => 'ok',
            'version' => self::version(),
            'uptime_seconds' => $uptimeSeconds,
            'active_sessions' => $this->turnManager->activeCount(),
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

        if ($this->webhookStore !== null) {
            $data['webhooks'] = $this->webhookStore->getStats();
        }

        return Router::jsonResponse($data);
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
