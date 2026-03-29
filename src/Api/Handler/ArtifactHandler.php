<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ArtifactStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Artifact CRUD API endpoints.
 *
 * GET    /api/v1/sessions/{id}/artifacts                  — list artifacts
 * POST   /api/v1/sessions/{id}/artifacts                  — create artifact
 * GET    /api/v1/sessions/{id}/artifacts/{artifactId}     — get artifact
 * PATCH  /api/v1/sessions/{id}/artifacts/{artifactId}     — update artifact
 * DELETE /api/v1/sessions/{id}/artifacts/{artifactId}     — delete artifact
 * GET    /api/v1/sessions/{id}/artifacts/{artifactId}/versions — version history
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
     * POST /api/v1/sessions/{id}/artifacts
     * { "title": "...", "content": "...", "type"?: "code", "language"?: "php", "filepath"?: "src/..." }
     */
    public function create(ServerRequestInterface $request, string $id): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if ($title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title is required');
        }

        $content = (string) ($body['content'] ?? '');
        $type = (string) ($body['type'] ?? 'code');
        $language = isset($body['language']) ? trim((string) $body['language']) : null;
        $filepath = isset($body['filepath']) ? trim((string) $body['filepath']) : null;

        $artifactId = $this->store->create(
            sessionId: $id,
            title: $title,
            content: $content,
            type: $type,
            language: $language !== '' ? $language : null,
            filepath: $filepath !== '' ? $filepath : null,
        );

        $artifact = $this->store->get($artifactId);

        return Router::jsonResponse($artifact ?? ['id' => $artifactId], 201);
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
     * PATCH /api/v1/sessions/{id}/artifacts/{artifactId}
     * { "content"?: "...", "title"?: "...", "stage"?: "review", "change_summary"?: "..." }
     */
    public function update(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $artifact = $this->store->get($artifactId, sessionId: $id);
        if ($artifact === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        // Stage-only update
        if (isset($body['stage']) && !isset($body['content'])) {
            $this->store->updateStage($artifactId, trim((string) $body['stage']), sessionId: $id);
            $updated = $this->store->get($artifactId, sessionId: $id);
            return Router::jsonResponse($updated ?? $artifact);
        }

        // Content update (with optional stage/title)
        if (isset($body['content'])) {
            $this->store->update(
                id: $artifactId,
                content: (string) $body['content'],
                changeSummary: isset($body['change_summary']) ? trim((string) $body['change_summary']) : null,
                title: isset($body['title']) ? trim((string) $body['title']) : null,
                stage: isset($body['stage']) ? trim((string) $body['stage']) : null,
                sessionId: $id,
            );
        }

        $updated = $this->store->get($artifactId, sessionId: $id);

        return Router::jsonResponse($updated ?? $artifact);
    }

    /**
     * DELETE /api/v1/sessions/{id}/artifacts/{artifactId}
     */
    public function delete(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $deleted = $this->store->delete($artifactId, sessionId: $id);

        if (!$deleted) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        return Router::jsonResponse(['deleted' => true, 'id' => $artifactId]);
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
