<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ProjectStore;
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
    public function __construct(
        private ProjectStore $store,
    ) {}

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
}