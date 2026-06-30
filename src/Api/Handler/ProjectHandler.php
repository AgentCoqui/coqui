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
 * Project discovery and management API endpoints.
 *
 * GET    /api/v1/projects                    — list projects
 * POST   /api/v1/projects                    — create a project
 * GET    /api/v1/projects/{idOrSlug}         — get project detail
 * PATCH  /api/v1/projects/{idOrSlug}         — update a project
 * DELETE /api/v1/projects/{idOrSlug}         — delete an archived project
 * POST   /api/v1/projects/{idOrSlug}/archive — archive a project
 * POST   /api/v1/projects/{idOrSlug}/activate — activate a project
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
            'project' => $project,
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

        $projects = $this->store->listProjects($status, $limit);

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

        return Router::jsonResponse([
            'project' => $project,
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

        return Router::jsonResponse([
            'project' => $project,
        ], $status);
    }
}
