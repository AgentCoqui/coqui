<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Project and sprint discovery API endpoints.
 *
 * GET /api/v1/projects                    — list projects
 * GET /api/v1/projects/{idOrSlug}         — get project detail
 * GET /api/v1/projects/{idOrSlug}/sprints — list project sprints
 * GET /api/v1/sprints/{id}                — get sprint detail
 */
final readonly class ProjectHandler
{
    use DecodesRequestBody;

    public function __construct(
        private ProjectStore $store,
        private ?SessionStorage $sessionStorage = null,
    ) {}

    /**
     * POST /api/v1/projects
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = $this->decodeJsonObjectOrNull($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $title = trim((string) ($body['title'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        $description = array_key_exists('description', $body) ? trim((string) ($body['description'] ?? '')) : null;

        if ($title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title is required');
        }

        if ($slug === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'slug is required');
        }

        try {
            $projectId = $this->store->createProject($title, $slug, $description);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        $project = $this->store->getProject($projectId);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Project created but could not be loaded');
        }

        return Router::jsonResponse([
            'project' => $this->normalizeProjectSummary($project),
        ], 201);
    }

    /**
     * GET /api/v1/projects?status=active&limit=50
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $status = isset($params['status']) && trim((string) $params['status']) !== ''
            ? trim((string) $params['status'])
            : null;
        $limit = isset($params['limit']) ? max(1, min((int) $params['limit'], 200)) : 50;

        $projects = array_map(
            fn(array $project): array => $this->normalizeProjectSummary($project),
            $this->store->listProjects($status, $limit),
        );

        return Router::jsonResponse([
            'projects' => $projects,
            'count' => count($projects),
        ]);
    }

    /**
     * GET /api/v1/projects/{idOrSlug}
     */
    public function get(ServerRequestInterface $request, string $idOrSlug): Response
    {
        $project = $this->store->getProject($idOrSlug);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        $sprints = $this->store->listSprints((string) $project['id']);
        $activeSprint = $this->store->getActiveSprintForProject((string) $project['id']);

        return Router::jsonResponse([
            'project' => $this->normalizeProjectDetail($project, $sprints, $activeSprint),
            'active_sprint' => $activeSprint,
        ]);
    }

    /**
     * PATCH /api/v1/projects/{idOrSlug}
     */
    public function update(ServerRequestInterface $request, string $idOrSlug): Response
    {
        $project = $this->store->getProject($idOrSlug);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        $body = $this->decodeJsonObjectOrNull($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $allowedKeys = ['title', 'description', 'status'];
        $unknownKeys = array_values(array_filter(
            array_keys($body),
            static fn(string $key): bool => !in_array($key, $allowedKeys, true),
        ));
        if ($unknownKeys !== []) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown project patch fields: %s', implode(', ', $unknownKeys)),
            );
        }

        if ($body === []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'At least one patch field is required');
        }

        $title = null;
        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title cannot be empty');
            }
        }

        $description = array_key_exists('description', $body)
            ? trim((string) ($body['description'] ?? ''))
            : null;

        $status = null;
        if (array_key_exists('status', $body)) {
            $status = strtolower(trim((string) $body['status']));
            if ($status === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'status cannot be empty');
            }
        }

        try {
            $this->store->updateProject((string) $project['id'], $title, $description, $status);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return $this->projectDetailResponse((string) $project['id']);
    }

    /**
     * DELETE /api/v1/projects/{idOrSlug}
     */
    public function delete(ServerRequestInterface $request, string $idOrSlug): Response
    {
        $project = $this->store->getProject($idOrSlug);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        if ((string) ($project['status'] ?? '') !== 'archived') {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                'Archive the project before deleting it.',
            );
        }

        $projectId = (string) $project['id'];
        $this->sessionStorage?->clearActiveProjectReferences($projectId);
        $this->store->deleteProject($projectId);

        return Router::jsonResponse([
            'deleted' => true,
            'id' => $projectId,
        ]);
    }

    /**
     * POST /api/v1/projects/{idOrSlug}/archive
     */
    public function archive(ServerRequestInterface $request, string $idOrSlug): Response
    {
        return $this->projectStatusResponse($idOrSlug, 'archived');
    }

    /**
     * POST /api/v1/projects/{idOrSlug}/activate
     */
    public function activate(ServerRequestInterface $request, string $idOrSlug): Response
    {
        return $this->projectStatusResponse($idOrSlug, 'active');
    }

    /**
     * GET /api/v1/projects/{idOrSlug}/sprints?status=planned
     */
    public function sprints(ServerRequestInterface $request, string $idOrSlug): Response
    {
        $project = $this->store->getProject($idOrSlug);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        $params = $request->getQueryParams();
        $status = isset($params['status']) && trim((string) $params['status']) !== ''
            ? trim((string) $params['status'])
            : null;
        $sprints = $this->store->listSprints((string) $project['id'], $status);

        return Router::jsonResponse([
            'project' => $this->normalizeProjectSummary($project),
            'sprints' => $sprints,
            'count' => count($sprints),
        ]);
    }

    /**
     * POST /api/v1/projects/{idOrSlug}/sprints
     */
    public function createSprint(ServerRequestInterface $request, string $idOrSlug): Response
    {
        $project = $this->store->getProject($idOrSlug);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        $body = $this->decodeJsonObjectOrNull($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title is required');
        }

        $acceptanceCriteria = array_key_exists('acceptance_criteria', $body)
            ? trim((string) ($body['acceptance_criteria'] ?? ''))
            : null;
        $contractArtifactId = array_key_exists('contract_artifact_id', $body)
            ? trim((string) ($body['contract_artifact_id'] ?? ''))
            : null;
        $lastSessionId = array_key_exists('last_session_id', $body)
            ? trim((string) ($body['last_session_id'] ?? ''))
            : null;
        $maxReviewRounds = $this->maxReviewRoundsFromBody($body);
        if ($maxReviewRounds instanceof Response) {
            return $maxReviewRounds;
        }
        if (!is_int($maxReviewRounds)) {
            $maxReviewRounds = 3;
        }

        try {
            $sprintId = $this->store->createSprint(
                (string) $project['id'],
                $title,
                $acceptanceCriteria,
                $contractArtifactId !== '' ? $contractArtifactId : null,
                $lastSessionId !== '' ? $lastSessionId : null,
                $maxReviewRounds,
            );
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return $this->sprintDetailResponse($sprintId, 201);
    }

    /**
     * GET /api/v1/sprints/{id}
     */
    public function sprint(ServerRequestInterface $request, string $id): Response
    {
        $sprint = $this->store->getSprint($id);
        if ($sprint === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
        }

        $project = $this->store->getProject((string) $sprint['project_id']);

        return Router::jsonResponse([
            'sprint' => $sprint,
            'project' => $project,
        ]);
    }

    /**
     * PATCH /api/v1/sprints/{id}
     */
    public function updateSprint(ServerRequestInterface $request, string $id): Response
    {
        $sprint = $this->store->getSprint($id);
        if ($sprint === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
        }

        $body = $this->decodeJsonObjectOrNull($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $allowedKeys = ['title', 'acceptance_criteria', 'contract_artifact_id', 'last_session_id', 'max_review_rounds'];
        $unknownKeys = array_values(array_filter(
            array_keys($body),
            static fn(string $key): bool => !in_array($key, $allowedKeys, true),
        ));
        if ($unknownKeys !== []) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown sprint patch fields: %s', implode(', ', $unknownKeys)),
            );
        }

        if ($body === []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'At least one patch field is required');
        }

        $title = null;
        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title cannot be empty');
            }
        }

        $acceptanceCriteria = array_key_exists('acceptance_criteria', $body)
            ? trim((string) ($body['acceptance_criteria'] ?? ''))
            : null;
        $contractArtifactId = array_key_exists('contract_artifact_id', $body)
            ? trim((string) ($body['contract_artifact_id'] ?? ''))
            : null;
        $lastSessionId = array_key_exists('last_session_id', $body)
            ? trim((string) ($body['last_session_id'] ?? ''))
            : null;
        $maxReviewRounds = $this->maxReviewRoundsFromBody($body, false);
        if ($maxReviewRounds instanceof Response) {
            return $maxReviewRounds;
        }

        try {
            $this->store->updateSprint(
                $id,
                $title,
                $acceptanceCriteria,
                $contractArtifactId,
                $lastSessionId,
                $maxReviewRounds,
            );
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return $this->sprintDetailResponse($id);
    }

    /**
     * DELETE /api/v1/sprints/{id}
     */
    public function deleteSprint(ServerRequestInterface $request, string $id): Response
    {
        $sprint = $this->store->getSprint($id);
        if ($sprint === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
        }

        if ((string) ($sprint['status'] ?? '') !== 'planned') {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                'Only planned sprints can be deleted.',
            );
        }

        $this->store->deleteSprint($id);

        return Router::jsonResponse([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    /**
     * POST /api/v1/sprints/{id}/start
     */
    public function startSprint(ServerRequestInterface $request, string $id): Response
    {
        return $this->transitionSprintResponse($id, 'in_progress');
    }

    /**
     * POST /api/v1/sprints/{id}/submit-review
     */
    public function submitReview(ServerRequestInterface $request, string $id): Response
    {
        return $this->transitionSprintResponse($id, 'review');
    }

    /**
     * POST /api/v1/sprints/{id}/complete
     */
    public function completeSprint(ServerRequestInterface $request, string $id): Response
    {
        return $this->transitionSprintResponse($id, 'complete');
    }

    /**
     * POST /api/v1/sprints/{id}/reject
     */
    public function rejectSprint(ServerRequestInterface $request, string $id): Response
    {
        $body = $this->decodeJsonObjectOrNull($request);
        $notes = null;
        if (is_array($body) && array_key_exists('reviewer_notes', $body)) {
            $notes = trim((string) ($body['reviewer_notes'] ?? ''));
            if ($notes === '') {
                $notes = null;
            }
        }

        return $this->transitionSprintResponse($id, 'rejected', $notes);
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private function normalizeProjectSummary(array $project): array
    {
        return [
            ...$project,
            'sprint_count' => (int) ($project['sprint_count'] ?? 0),
            'sprints_completed' => (int) ($project['sprints_completed'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $sprints
     * @param array<string, mixed>|null $activeSprint
     * @return array<string, mixed>
     */
    private function normalizeProjectDetail(array $project, array $sprints, ?array $activeSprint): array
    {
        $completed = count(array_filter(
            $sprints,
            static fn(array $sprint): bool => (string) ($sprint['status'] ?? '') === 'complete',
        ));

        return [
            ...$project,
            'sprint_count' => count($sprints),
            'sprints_completed' => $completed,
            'active_sprint_id' => $activeSprint['id'] ?? null,
        ];
    }

    private function projectStatusResponse(string $idOrSlug, string $status): Response
    {
        $project = $this->store->getProject($idOrSlug);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        try {
            $this->store->updateProject((string) $project['id'], status: $status);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return $this->projectDetailResponse((string) $project['id']);
    }

    private function projectDetailResponse(string $projectId, int $status = 200): Response
    {
        $project = $this->store->getProject($projectId);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        $sprints = $this->store->listSprints($projectId);
        $activeSprint = $this->store->getActiveSprintForProject($projectId);

        return Router::jsonResponse([
            'project' => $this->normalizeProjectDetail($project, $sprints, $activeSprint),
            'active_sprint' => $activeSprint,
        ], $status);
    }

    private function sprintDetailResponse(string $sprintId, int $status = 200): Response
    {
        $sprint = $this->store->getSprint($sprintId);
        if ($sprint === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
        }

        $project = $this->store->getProject((string) $sprint['project_id']);

        return Router::jsonResponse([
            'sprint' => $sprint,
            'project' => $project,
        ], $status);
    }

    private function transitionSprintResponse(string $id, string $targetStatus, ?string $notes = null): Response
    {
        $sprint = $this->store->getSprint($id);
        if ($sprint === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
        }

        try {
            $this->store->transitionSprint($id, $targetStatus, $notes);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, $e->getMessage());
        }

        return $this->sprintDetailResponse($id);
    }

    /**
     * @param array<string, mixed> $body
     * @return int|Response|null
     */
    private function maxReviewRoundsFromBody(array $body, bool $required = false): int|Response|null
    {
        if (!array_key_exists('max_review_rounds', $body)) {
            return $required ? 3 : null;
        }

        $rawValue = $body['max_review_rounds'];
        if (!is_int($rawValue) && !is_string($rawValue) && !is_float($rawValue)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_review_rounds must be a positive integer');
        }

        $maxReviewRounds = (int) $rawValue;
        if ($maxReviewRounds < 1) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_review_rounds must be greater than 0');
        }

        if ($maxReviewRounds > ProjectStore::MAX_REVIEW_ROUNDS_CAP) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('max_review_rounds cannot exceed %d', ProjectStore::MAX_REVIEW_ROUNDS_CAP),
            );
        }

        return $maxReviewRounds;
    }
}