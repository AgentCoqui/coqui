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
 * Session-scoped active-project endpoints.
 *
 * GET   /api/v1/sessions/{id}/project — get active project for a session
 * PATCH /api/v1/sessions/{id}/project — set or clear active project
 */
final readonly class SessionProjectHandler
{
    public function __construct(
        private SessionStorage $storage,
        private ProjectStore $projectStore,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/project
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        if ($this->storage->getSession($id) === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $activeProjectId = $this->storage->getActiveProjectId($id);
        $project = $activeProjectId !== null ? $this->projectStore->getProject($activeProjectId) : null;

        return Router::jsonResponse([
            'session_id' => $id,
            'active_project_id' => $activeProjectId,
            'project' => $project,
        ]);
    }

    /**
     * PATCH /api/v1/sessions/{id}/project
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        if ($this->storage->getSession($id) === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $projectId = array_key_exists('project_id', $body) ? trim((string) ($body['project_id'] ?? '')) : '';
        $projectSlug = array_key_exists('project_slug', $body) ? trim((string) ($body['project_slug'] ?? '')) : '';
        $clear = array_key_exists('clear', $body)
            ? filter_var($body['clear'], FILTER_VALIDATE_BOOLEAN)
            : false;

        if ($clear && ($projectId !== '' || $projectSlug !== '')) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'clear cannot be combined with project_id or project_slug');
        }

        if ($projectId !== '' && $projectSlug !== '') {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Specify either project_id or project_slug, not both');
        }

        if ($clear) {
            $this->storage->setActiveProject($id, null);

            return Router::jsonResponse([
                'session_id' => $id,
                'active_project_id' => null,
                'project' => null,
            ]);
        }

        $lookup = $projectId !== '' ? $projectId : $projectSlug;
        if ($lookup === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Provide project_id, project_slug, or clear=true');
        }

        $project = $this->projectStore->getProject($lookup);
        if ($project === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        $this->storage->setActiveProject($id, (string) $project['id']);

        return Router::jsonResponse([
            'session_id' => $id,
            'active_project_id' => $project['id'],
            'project' => $project,
        ]);
    }
}