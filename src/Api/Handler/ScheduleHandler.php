<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\JsonHelper;
use CoquiBot\Coqui\Utility\ScheduleValidator;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Schedule API endpoints.
 *
 * POST   /api/v1/schedules              — create schedule
 * GET    /api/v1/schedules              — list schedules
 * GET    /api/v1/schedules/{id}         — get schedule
 * PATCH  /api/v1/schedules/{id}         — update schedule
 * DELETE /api/v1/schedules/{id}         — delete schedule
 * POST   /api/v1/schedules/{id}/enable  — enable schedule
 * POST   /api/v1/schedules/{id}/disable — disable schedule
 * POST   /api/v1/schedules/{id}/trigger — trigger schedule
 */
final readonly class ScheduleHandler
{
    public function __construct(
        private ScheduleStore $store,
        private ?SessionStorage $storage = null,
    ) {}

    /**
     * POST /api/v1/schedules
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'name is required');
        }
        if (($error = ScheduleValidator::validateName($name)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }
        if ($this->store->getByName($name) !== null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Schedule name "%s" already exists', $name));
        }

        $scheduleExpression = trim((string) ($body['schedule_expression'] ?? ''));
        if ($scheduleExpression === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'schedule_expression is required');
        }
        if (($error = ScheduleValidator::validateExpression($scheduleExpression)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $prompt = trim((string) ($body['prompt'] ?? ''));
        if ($prompt === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'prompt is required');
        }
        if (($error = ScheduleValidator::validatePromptLength($prompt)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $timezone = trim((string) ($body['timezone'] ?? 'UTC'));
        if (($error = ScheduleValidator::validateTimezone($timezone)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $maxIterations = 48;
        if (array_key_exists('max_iterations', $body) && $body['max_iterations'] !== null && $body['max_iterations'] !== '') {
            if (!is_numeric($body['max_iterations'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_iterations must be an integer');
            }

            $maxIterations = (int) $body['max_iterations'];
            if ($maxIterations < 1) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_iterations must be greater than 0');
            }
            $maxIterations = ScheduleValidator::normalizeMaxIterations($maxIterations);
        }

        $maxFailures = 3;
        if (array_key_exists('max_failures', $body) && $body['max_failures'] !== null && $body['max_failures'] !== '') {
            if (!is_numeric($body['max_failures'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_failures must be an integer');
            }

            $maxFailures = (int) $body['max_failures'];
            if ($maxFailures < 1) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_failures must be greater than 0');
            }
            $maxFailures = ScheduleValidator::normalizeMaxFailures($maxFailures);
        }

        $id = $this->store->create(
            name: $name,
            scheduleExpression: $scheduleExpression,
            prompt: $prompt,
            role: trim((string) ($body['role'] ?? 'orchestrator')) ?: 'orchestrator',
            maxIterations: $maxIterations,
            description: isset($body['description']) ? trim((string) $body['description']) : null,
            createdBy: 'api',
            timezone: $timezone,
            maxFailures: $maxFailures,
        );

        $schedule = $this->store->get($id);

        return Router::jsonResponse(['schedule' => $schedule], 201);
    }

    /**
     * GET /api/v1/schedules?enabled=1&created_by=agent
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        $enabled = isset($params['enabled'])
            ? ((string) $params['enabled'] === '1')
            : null;
        $createdBy = isset($params['created_by']) && trim((string) $params['created_by']) !== ''
            ? trim((string) $params['created_by'])
            : null;

        $schedules = $this->store->list(enabled: $enabled, createdBy: $createdBy);
        $stats = $this->store->getStats();

        return Router::jsonResponse([
            'schedules' => $schedules,
            'count' => count($schedules),
            'stats' => $stats,
        ]);
    }

    /**
     * GET /api/v1/schedules/upcoming?hours=24
     */
    public function upcoming(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $hours = isset($params['hours']) ? (int) $params['hours'] : 24;
        if ($hours < 1) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'hours must be greater than 0');
        }

        $hours = min($hours, 24 * 30);
        $schedules = $this->store->getUpcoming($hours);

        return Router::jsonResponse([
            'schedules' => $schedules,
            'count' => count($schedules),
            'hours' => $hours,
        ]);
    }

    /**
     * GET /api/v1/schedules/stats
     */
    public function stats(ServerRequestInterface $request): Response
    {
        return Router::jsonResponse($this->store->getStats());
    }

    /**
     * GET /api/v1/schedules/{id}
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->store->get($id);
        if ($schedule === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        return Router::jsonResponse($schedule);
    }

    /**
     * GET /api/v1/schedules/{id}/runs?limit=20
     */
    public function runs(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->store->get($id);
        if ($schedule === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        if ($this->storage === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Task storage is not available');
        }

        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? (int) $params['limit'] : 20;
        if ($limit < 1) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'limit must be greater than 0');
        }

        $limit = min($limit, 100);
        $runs = $this->storage->listTasksForSchedule($id, $limit);
        $runs = array_map(fn(array $run): array => $this->normalizeRun($run), $runs);

        $counts = [];
        foreach ($runs as $run) {
            $status = (string) ($run['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return Router::jsonResponse([
            'schedule' => $schedule,
            'runs' => $runs,
            'count' => count($runs),
            'counts' => $counts,
        ]);
    }

    /**
     * PATCH /api/v1/schedules/{id}
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->requireMutableSchedule($id);
        if ($schedule instanceof Response) {
            return $schedule;
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        if (array_key_exists('enabled', $body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Use the enable or disable action endpoint to change enabled state');
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if (($error = ScheduleValidator::validateName($name)) !== null) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
            }

            $existing = $this->store->getByName($name);
            if ($existing !== null && (string) $existing['id'] !== $id) {
                return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Schedule name "%s" already exists', $name));
            }
        }

        $scheduleExpression = null;
        if (array_key_exists('schedule_expression', $body)) {
            $scheduleExpression = trim((string) $body['schedule_expression']);
            if (($error = ScheduleValidator::validateExpression($scheduleExpression)) !== null) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
            }
        }

        $prompt = null;
        if (array_key_exists('prompt', $body)) {
            $prompt = trim((string) $body['prompt']);
            if ($prompt === '') {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'prompt must not be empty');
            }
            if (($error = ScheduleValidator::validatePromptLength($prompt)) !== null) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
            }
        }

        $timezone = null;
        if (array_key_exists('timezone', $body)) {
            $timezone = trim((string) $body['timezone']);
            if (($error = ScheduleValidator::validateTimezone($timezone)) !== null) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
            }
        }

        $maxIterations = null;
        if (array_key_exists('max_iterations', $body)) {
            if (!is_numeric($body['max_iterations'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_iterations must be an integer');
            }
            $maxIterations = (int) $body['max_iterations'];
            if ($maxIterations < 1) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_iterations must be greater than 0');
            }
            $maxIterations = ScheduleValidator::normalizeMaxIterations($maxIterations);
        }

        $maxFailures = null;
        if (array_key_exists('max_failures', $body)) {
            if (!is_numeric($body['max_failures'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_failures must be an integer');
            }
            $maxFailures = (int) $body['max_failures'];
            if ($maxFailures < 1) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_failures must be greater than 0');
            }
            $maxFailures = ScheduleValidator::normalizeMaxFailures($maxFailures);
        }

        $this->store->update(
            id: $id,
            name: $name,
            description: array_key_exists('description', $body) ? trim((string) $body['description']) : null,
            scheduleExpression: $scheduleExpression,
            prompt: $prompt,
            role: array_key_exists('role', $body) ? trim((string) $body['role']) : null,
            maxIterations: $maxIterations,
            timezone: $timezone,
            maxFailures: $maxFailures,
        );

        return Router::jsonResponse(['schedule' => $this->store->get($id)]);
    }

    /**
     * DELETE /api/v1/schedules/{id}
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->requireMutableSchedule($id);
        if ($schedule instanceof Response) {
            return $schedule;
        }

        $this->store->delete($id);

        return Router::jsonResponse(['deleted' => true]);
    }

    /**
     * POST /api/v1/schedules/{id}/enable
     */
    public function enable(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->requireMutableSchedule($id);
        if ($schedule instanceof Response) {
            return $schedule;
        }

        $this->store->enable($id);

        return Router::jsonResponse(['schedule' => $this->store->get($id)]);
    }

    /**
     * POST /api/v1/schedules/{id}/disable
     */
    public function disable(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->requireMutableSchedule($id);
        if ($schedule instanceof Response) {
            return $schedule;
        }

        $this->store->disable($id);

        return Router::jsonResponse(['schedule' => $this->store->get($id)]);
    }

    /**
     * POST /api/v1/schedules/{id}/trigger
     */
    public function trigger(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->requireMutableSchedule($id);
        if ($schedule instanceof Response) {
            return $schedule;
        }

        $now = Clock::nowUtc();
        $this->store->forceNextRun($id, $now);

        return Router::jsonResponse([
            'schedule' => $this->store->get($id),
            'message' => 'Schedule will fire on the next API scheduler tick.',
        ]);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function requireMutableSchedule(string $id): array|Response
    {
        $schedule = $this->store->get($id);
        if ($schedule === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        if (($schedule['source'] ?? ScheduleStore::SOURCE_SYSTEM) === ScheduleStore::SOURCE_FILESYSTEM) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                'Schedule is defined by a filesystem JSON file and must be changed at the source file instead.',
                ['source_path' => $schedule['source_path'] ?? null],
            );
        }

        return $schedule;
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function normalizeRun(array $run): array
    {
        $run['metadata'] = JsonHelper::decodeJsonObject($run['metadata'] ?? null);

        return $run;
    }
}
