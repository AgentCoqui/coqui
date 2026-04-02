<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ArtifactStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Artifact read-only API endpoints.
 *
 * GET    /api/v1/sessions/{id}/artifacts                  — list artifacts
 * GET    /api/v1/sessions/{id}/artifacts/{artifactId}     — get artifact
 * GET    /api/v1/sessions/{id}/artifacts/{artifactId}/versions — version history
 *
 * Mutating operations (create, update, delete) are REPL-only.
 */
final readonly class ArtifactHandler
{
    public function __construct(
        private ArtifactStore $store,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/artifacts?type=code&stage=draft
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $params = $request->getQueryParams();
        $type = isset($params['type']) ? trim((string) $params['type']) : null;
        $stage = isset($params['stage']) ? trim((string) $params['stage']) : null;

        $artifacts = $this->store->list(
            sessionId: $id,
            type: $type !== '' ? $type : null,
            stage: $stage !== '' ? $stage : null,
        );

        return Router::jsonResponse([
            'artifacts' => $artifacts,
            'count' => count($artifacts),
        ]);
    }

    /**
     * GET /api/v1/sessions/{id}/artifacts/{artifactId}
     */
    public function get(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $artifact = $this->store->get($artifactId, sessionId: $id);

        if ($artifact === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        return Router::jsonResponse($artifact);
    }

    /**
     * GET /api/v1/sessions/{id}/artifacts/{artifactId}/versions
     */
    public function versions(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $artifact = $this->store->get($artifactId, sessionId: $id);
        if ($artifact === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        $versions = $this->store->getVersions($artifactId, sessionId: $id);

        return Router::jsonResponse([
            'artifact_id' => $artifactId,
            'versions' => $versions,
            'count' => count($versions),
        ]);
    }
}
