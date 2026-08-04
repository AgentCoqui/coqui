<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\CursorPage;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Exception\RequestBodyException;
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
    use DecodesRequestBody;

    public function __construct(
        private ScheduleStore $store,
        private ?SessionStorage $storage = null,
    ) {}

    /**
     * POST /api/v1/schedules
     */
    public function create(ServerRequestInterface $request): Response
    {
        try {
            $body = $this->decodeAuthoringBody(
                $request,
                ['name', 'cron', 'persona_id', 'action'],
                [],
            );
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
        }

        $name = trim((string) $body['name']);
        if (($error = ScheduleValidator::validateName($name)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }
        if ($this->store->getByName($name) !== null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Schedule name "%s" already exists', $name));
        }

        $scheduleExpression = trim((string) $body['cron']);
        if (($error = ScheduleValidator::validateExpression($scheduleExpression)) !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
        }

        $personaId = trim((string) $body['persona_id']);
        if ($personaId === '') {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'persona_id must not be empty', ['field' => 'persona_id']);
        }

        if (!is_array($body['action'])) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'action must be an object', ['field' => 'action']);
        }

        try {
            $id = $this->store->create(
                name: $name,
                scheduleExpression: $scheduleExpression,
                action: $body['action'],
                personaId: $personaId,
                createdBy: 'api',
            );
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
        }

        $row = $this->store->get($id);
        if ($row === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Schedule could not be read back after creation');
        }

        return Router::jsonResponse(ScheduleStore::toWire($row), 201);
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

        $schedules = array_map(
            static fn (array $row): array => ScheduleStore::toWire($row),
            $this->store->list(enabled: $enabled, createdBy: $createdBy),
        );

        // Declared default sort: next_run_at ASC (NULLS LAST), id ASC — id is
        // the stable tiebreak + unique cursor key.
        usort($schedules, static function (array $a, array $b): int {
            $aNext = $a['next_run_at'] ?? null;
            $bNext = $b['next_run_at'] ?? null;

            if ($aNext !== $bNext) {
                if ($aNext === null) {
                    return 1;
                }
                if ($bNext === null) {
                    return -1;
                }

                return strcmp((string) $aNext, (string) $bNext);
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        $page = CursorPage::build(
            $schedules,
            CursorPage::limitFrom($params['limit'] ?? null),
            static fn(array $schedule): string => (string) ($schedule['id'] ?? ''),
            CursorPage::decode(isset($params['cursor']) ? (string) $params['cursor'] : null),
        );

        return Router::jsonResponse([
            ...$page,
            'stats' => $this->store->getStats(),
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

        return Router::jsonResponse(ScheduleStore::toWire($schedule));
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

        try {
            $body = $this->decodePatchBody($request, ['name', 'cron', 'persona_id', 'action', 'status']);
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
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
        if (array_key_exists('cron', $body)) {
            $scheduleExpression = trim((string) $body['cron']);
            if (($error = ScheduleValidator::validateExpression($scheduleExpression)) !== null) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $error);
            }
        }

        $personaId = null;
        if (array_key_exists('persona_id', $body)) {
            $personaId = trim((string) $body['persona_id']);
            if ($personaId === '') {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'persona_id must not be empty', ['field' => 'persona_id']);
            }
        }

        $action = null;
        if (array_key_exists('action', $body)) {
            if (!is_array($body['action'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'action must be an object', ['field' => 'action']);
            }
            $action = $body['action'];
        }

        if (array_key_exists('status', $body) && !in_array($body['status'], ['enabled', 'disabled'], true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'status must be enabled or disabled', ['field' => 'status']);
        }

        try {
            $this->store->update(
                id: $id,
                name: $name,
                scheduleExpression: $scheduleExpression,
                action: $action,
                personaId: $personaId,
            );
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
        }

        // `status` is the CAP enabled|disabled toggle; route it through the
        // same enable/disable path that recomputes next_run_at.
        if (array_key_exists('status', $body)) {
            $body['status'] === 'enabled' ? $this->store->enable($id) : $this->store->disable($id);
        }

        $row = $this->store->get($id);
        if ($row === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        return Router::jsonResponse(ScheduleStore::toWire($row));
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
