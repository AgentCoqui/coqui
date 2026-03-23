<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\ScheduleManager;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Utility\ScheduleValidator;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Schedule CRUD API endpoints.
 *
 * GET    /api/v1/schedules              — list schedules
 * POST   /api/v1/schedules              — create schedule
 * GET    /api/v1/schedules/{id}         — get schedule
 * PATCH  /api/v1/schedules/{id}         — update schedule
 * DELETE /api/v1/schedules/{id}         — delete schedule
 * POST   /api/v1/schedules/{id}/trigger — force immediate execution
 * POST   /api/v1/schedules/{id}/enable  — enable schedule
 * POST   /api/v1/schedules/{id}/disable — disable schedule
 */
final readonly class ScheduleHandler
{
    public function __construct(
        private ScheduleStore $store,
        private ScheduleManager $manager,
    ) {}

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
     * POST /api/v1/schedules
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        if (($error = ScheduleValidator::validateName($name)) !== null) {
            $nameCode = $name === '' ? ApiErrorCode::MISSING_FIELD : ApiErrorCode::VALIDATION_ERROR;
            return Router::errorResponse($nameCode, $error);
        }

        // Check uniqueness
        if ($this->store->getByName($name) !== null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Schedule "%s" already exists', $name));
        }

        $expression = isset($body['schedule_expression']) ? trim((string) $body['schedule_expression']) : '';
        if (($error = ScheduleValidator::validateExpression($expression)) !== null) {
            $exprCode = $expression === '' ? ApiErrorCode::MISSING_FIELD : ApiErrorCode::VALIDATION_ERROR;
            return Router::errorResponse($exprCode, $error);
        }

        $prompt = isset($body['prompt']) ? trim((string) $body['prompt']) : '';
        if ($prompt === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'prompt is required');
        }
        if (($error = ScheduleValidator::validatePromptLength($prompt)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $role = isset($body['role']) ? trim((string) $body['role']) : 'orchestrator';
        $maxIterations = ScheduleValidator::normalizeMaxIterations(isset($body['max_iterations']) ? (int) $body['max_iterations'] : 48);

        $timezone = isset($body['timezone']) ? trim((string) $body['timezone']) : 'UTC';
        if (($error = ScheduleValidator::validateTimezone($timezone)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $maxFailures = ScheduleValidator::normalizeMaxFailures(isset($body['max_failures']) ? (int) $body['max_failures'] : 3);

        $id = $this->store->create(
            name: $name,
            scheduleExpression: $expression,
            prompt: $prompt,
            role: $role,
            maxIterations: $maxIterations,
            description: isset($body['description']) ? trim((string) $body['description']) : null,
            createdBy: isset($body['created_by']) ? trim((string) $body['created_by']) : 'api',
            timezone: $timezone,
            maxFailures: $maxFailures,
        );

        $schedule = $this->store->get($id);

        return Router::jsonResponse($schedule ?? ['id' => $id], 201);
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
     * PATCH /api/v1/schedules/{id}
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->store->get($id);
        if ($schedule === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $name = isset($body['name']) ? trim((string) $body['name']) : null;
        if ($name !== null && ($error = ScheduleValidator::validateName($name)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }
        if ($name !== null && $name !== $schedule['name']) {
            $existing = $this->store->getByName($name);
            if ($existing !== null) {
                return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Schedule "%s" already exists', $name));
            }
        }

        $expression = isset($body['schedule_expression']) ? trim((string) $body['schedule_expression']) : null;
        if ($expression !== null && ($error = ScheduleValidator::validateExpression($expression)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $prompt = isset($body['prompt']) ? trim((string) $body['prompt']) : null;
        if ($prompt !== null && ($error = ScheduleValidator::validatePromptLength($prompt)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $timezone = isset($body['timezone']) ? trim((string) $body['timezone']) : null;
        if ($timezone !== null && ($error = ScheduleValidator::validateTimezone($timezone)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $enabled = isset($body['enabled']) ? (bool) $body['enabled'] : null;
        $maxIterations = isset($body['max_iterations']) ? ScheduleValidator::normalizeMaxIterations((int) $body['max_iterations']) : null;
        $maxFailures = isset($body['max_failures']) ? ScheduleValidator::normalizeMaxFailures((int) $body['max_failures']) : null;

        $this->store->update(
            id: $id,
            name: $name,
            description: isset($body['description']) ? trim((string) $body['description']) : null,
            scheduleExpression: $expression,
            prompt: $prompt,
            role: isset($body['role']) ? trim((string) $body['role']) : null,
            maxIterations: $maxIterations,
            enabled: $enabled,
            timezone: $timezone,
            maxFailures: $maxFailures,
        );

        $updated = $this->store->get($id);

        return Router::jsonResponse($updated ?? $schedule);
    }

    /**
     * DELETE /api/v1/schedules/{id}
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        if (!$this->store->delete($id)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        return Router::jsonResponse(['deleted' => true]);
    }

    /**
     * POST /api/v1/schedules/{id}/trigger
     */
    public function trigger(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->store->get($id);
        if ($schedule === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        $taskId = $this->manager->trigger($id);
        if ($taskId === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to trigger schedule');
        }

        return Router::jsonResponse([
            'schedule_id' => $id,
            'task_id' => $taskId,
            'message' => 'Schedule triggered. Task created and queued.',
        ]);
    }

    /**
     * POST /api/v1/schedules/{id}/enable
     */
    public function enable(ServerRequestInterface $request, string $id): Response
    {
        if (!$this->store->enable($id)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        $schedule = $this->store->get($id);

        return Router::jsonResponse($schedule ?? ['id' => $id, 'enabled' => true]);
    }

    /**
     * POST /api/v1/schedules/{id}/disable
     */
    public function disable(ServerRequestInterface $request, string $id): Response
    {
        if (!$this->store->disable($id)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        $schedule = $this->store->get($id);

        return Router::jsonResponse($schedule ?? ['id' => $id, 'enabled' => false]);
    }
}
