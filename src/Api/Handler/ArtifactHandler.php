<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\JsonHelper;
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
    /** @var list<string> */
    private const array ALLOWED_STAGES = ['draft', 'review', 'final'];

    public function __construct(
        private ArtifactStore $store,
        private ?SessionStorage $sessionStorage = null,
        private ?ProjectStore $projectStore = null,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/artifacts?type=code&stage=draft
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->requireReadableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $params = $request->getQueryParams();
        $type = isset($params['type']) ? trim((string) $params['type']) : null;
        $stage = isset($params['stage']) ? trim((string) $params['stage']) : null;

        $artifacts = $this->store->list(
            sessionId: $id,
            type: $type !== '' ? $type : null,
            stage: $stage !== '' ? $stage : null,
        );
        $artifacts = array_map(fn(array $artifact): array => $this->normalizeArtifact($artifact), $artifacts);

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
        return $this->artifactDetailResponse($id, $artifactId);
    }

    /**
     * GET /api/v1/sessions/{id}/artifacts/{artifactId}/versions
     */
    public function versions(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $session = $this->requireReadableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

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

    /**
     * POST /api/v1/sessions/{id}/artifacts
     */
    public function create(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title is required');
        }

        if (!array_key_exists('content', $body) || !is_string($body['content'])) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'content is required');
        }

        $type = trim((string) ($body['type'] ?? 'code'));
        if ($type === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'type cannot be empty');
        }

        $stage = strtolower(trim((string) ($body['stage'] ?? 'draft')));
        if (!in_array($stage, self::ALLOWED_STAGES, true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'stage must be draft, review, or final');
        }

        $language = array_key_exists('language', $body) ? $this->nullableString($body['language']) : null;
        $filepath = array_key_exists('filepath', $body) ? $this->nullableString($body['filepath']) : null;
        $projectId = array_key_exists('project_id', $body) ? $this->nullableId($body['project_id']) : null;
        $sprintId = array_key_exists('sprint_id', $body) ? $this->nullableId($body['sprint_id']) : null;
        $persistent = array_key_exists('persistent', $body) ? filter_var($body['persistent'], FILTER_VALIDATE_BOOLEAN) : false;

        $linkValidation = $this->validateArtifactLinks($projectId, $sprintId);
        if ($linkValidation instanceof Response) {
            return $linkValidation;
        }

        $metadata = $this->resolvedMetadata(null, $body);
        if ($metadata instanceof Response) {
            return $metadata;
        }

        $artifactId = $this->store->create(
            sessionId: $id,
            title: $title,
            content: $body['content'],
            type: $type,
            language: $language,
            filepath: $filepath,
            stage: $stage,
            metadata: $metadata,
            projectId: $projectId,
            sprintId: $sprintId,
            persistent: $persistent,
        );

        return $this->artifactDetailResponse($id, $artifactId, 201);
    }

    /**
     * PATCH /api/v1/sessions/{id}/artifacts/{artifactId}
     */
    public function update(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $artifact = $this->store->get($artifactId, sessionId: $id);
        if ($artifact === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $allowedKeys = ['title', 'content', 'change_summary', 'stage', 'metadata', 'tags', 'summary', 'language', 'project_id', 'sprint_id', 'persistent'];
        $unknownKeys = array_values(array_filter(
            array_keys($body),
            static fn(string $key): bool => !in_array($key, $allowedKeys, true),
        ));
        if ($unknownKeys !== []) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown artifact patch fields: %s', implode(', ', $unknownKeys)),
            );
        }

        if ($body === []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'At least one patch field is required');
        }

        $title = array_key_exists('title', $body) ? trim((string) $body['title']) : null;
        if (array_key_exists('title', $body) && $title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title cannot be empty');
        }

        $changeSummary = array_key_exists('change_summary', $body) ? $this->nullableString($body['change_summary']) : null;
        if ($changeSummary !== null && !array_key_exists('content', $body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'change_summary requires content');
        }

        $stage = array_key_exists('stage', $body) ? strtolower(trim((string) $body['stage'])) : null;
        if ($stage !== null && !in_array($stage, self::ALLOWED_STAGES, true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'stage must be draft, review, or final');
        }

        $language = array_key_exists('language', $body) ? $this->nullableString($body['language']) : null;
        $projectId = array_key_exists('project_id', $body) ? $this->nullableId($body['project_id']) : null;
        $sprintId = array_key_exists('sprint_id', $body) ? $this->nullableId($body['sprint_id']) : null;

        $linkValidation = $this->validateArtifactLinks(array_key_exists('project_id', $body) ? $projectId : null, array_key_exists('sprint_id', $body) ? $sprintId : null, $artifact);
        if ($linkValidation instanceof Response) {
            return $linkValidation;
        }

        $metadata = $this->resolvedMetadata($artifact, $body);
        if ($metadata instanceof Response) {
            return $metadata;
        }

        if (array_key_exists('content', $body)) {
            if (!is_string($body['content'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'content must be a string');
            }

            $this->store->update(
                $artifactId,
                $body['content'],
                $changeSummary,
                $title,
                $stage,
                $id,
            );
        } else {
            if ($stage !== null) {
                $this->store->updateStage($artifactId, $stage, $id);
            }

            $patch = [];
            if ($title !== null) {
                $patch['title'] = $title;
            }

            if (array_key_exists('language', $body)) {
                $patch['language'] = $language;
            }

            if ($metadata !== null || array_key_exists('metadata', $body) || array_key_exists('tags', $body) || array_key_exists('summary', $body)) {
                $patch['metadata'] = $metadata;
            }

            if (array_key_exists('project_id', $body)) {
                $patch['project_id'] = $projectId;
            }

            if (array_key_exists('sprint_id', $body)) {
                $patch['sprint_id'] = $sprintId;
            }

            if (array_key_exists('persistent', $body)) {
                $patch['persistent'] = filter_var($body['persistent'], FILTER_VALIDATE_BOOLEAN);
            } elseif (array_key_exists('project_id', $body) && $projectId !== null) {
                $patch['persistent'] = true;
            }

            if ($patch !== []) {
                $this->store->patch($artifactId, $patch, $id);
            }
        }

        if (array_key_exists('content', $body)) {
            $patch = [];

            if (array_key_exists('language', $body)) {
                $patch['language'] = $language;
            }

            if ($metadata !== null || array_key_exists('metadata', $body) || array_key_exists('tags', $body) || array_key_exists('summary', $body)) {
                $patch['metadata'] = $metadata;
            }

            if (array_key_exists('project_id', $body)) {
                $patch['project_id'] = $projectId;
            }

            if (array_key_exists('sprint_id', $body)) {
                $patch['sprint_id'] = $sprintId;
            }

            if (array_key_exists('persistent', $body)) {
                $patch['persistent'] = filter_var($body['persistent'], FILTER_VALIDATE_BOOLEAN);
            } elseif (array_key_exists('project_id', $body) && $projectId !== null) {
                $patch['persistent'] = true;
            }

            if ($patch !== []) {
                $this->store->patch($artifactId, $patch, $id);
            }
        }

        return $this->artifactDetailResponse($id, $artifactId);
    }

    /**
     * DELETE /api/v1/sessions/{id}/artifacts/{artifactId}
     */
    public function delete(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        if ($this->store->get($artifactId, sessionId: $id) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        $this->store->delete($artifactId, $id);

        return Router::jsonResponse([
            'deleted' => true,
            'id' => $artifactId,
        ]);
    }

    /**
     * POST /api/v1/sessions/{id}/artifacts/{artifactId}/versions
     */
    public function createVersion(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $artifact = $this->store->get($artifactId, sessionId: $id);
        if ($artifact === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        if (!array_key_exists('content', $body) || !is_string($body['content'])) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'content is required');
        }

        $changeSummary = array_key_exists('change_summary', $body) ? $this->nullableString($body['change_summary']) : null;
        $title = array_key_exists('title', $body) ? trim((string) $body['title']) : null;
        if ($title !== null && $title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title cannot be empty');
        }

        $stage = array_key_exists('stage', $body) ? strtolower(trim((string) $body['stage'])) : null;
        if ($stage !== null && !in_array($stage, self::ALLOWED_STAGES, true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'stage must be draft, review, or final');
        }

        $this->store->update($artifactId, $body['content'], $changeSummary, $title, $stage, $id);

        return $this->artifactDetailResponse($id, $artifactId);
    }

    /**
     * POST /api/v1/sessions/{id}/artifacts/{artifactId}/versions/{versionId}/restore
     */
    public function restoreVersion(ServerRequestInterface $request, string $id, string $artifactId, string $versionId): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $artifact = $this->store->get($artifactId, sessionId: $id);
        if ($artifact === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        $version = $this->store->getVersionById($artifactId, $versionId);
        if ($version === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact version not found');
        }

        $this->store->update(
            $artifactId,
            (string) $version['content'],
            sprintf('Restored version %d', (int) ($version['version'] ?? 0)),
            sessionId: $id,
        );

        return $this->artifactDetailResponse($id, $artifactId);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function requireWritableSession(string $sessionId): array|Response
    {
        if ($this->sessionStorage === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return SessionAccess::requireWritableSession($this->sessionStorage, $sessionId);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function requireReadableSession(string $sessionId): array|Response
    {
        if ($this->sessionStorage === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return SessionAccess::requireReadableSession($this->sessionStorage, $sessionId);
    }

    private function artifactDetailResponse(string $sessionId, string $artifactId, int $status = 200): Response
    {
        $session = $this->requireReadableSession($sessionId);
        if ($session instanceof Response) {
            return $session;
        }

        $artifact = $this->store->get($artifactId, sessionId: $sessionId);
        if ($artifact === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        return Router::jsonResponse($this->normalizeArtifact($artifact), $status);
    }

    /**
     * @param array<string, mixed>|null $existingArtifact
     * @param array<string, mixed> $body
     * @return array<string, mixed>|Response|null
     */
    private function resolvedMetadata(?array $existingArtifact, array $body): array|Response|null
    {
        $hasMetadataInput = array_key_exists('metadata', $body) || array_key_exists('tags', $body) || array_key_exists('summary', $body);
        if (!$hasMetadataInput) {
            return null;
        }

        $metadata = [];
        if ($existingArtifact !== null) {
            $metadata = JsonHelper::decodeJsonObject($existingArtifact['metadata'] ?? null) ?? [];
        }

        if (array_key_exists('metadata', $body)) {
            if (!is_array($body['metadata'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'metadata must be an object');
            }

            $metadata = $body['metadata'];
        }

        if (array_key_exists('tags', $body)) {
            if (!is_array($body['tags'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'tags must be an array of strings');
            }

            $tags = [];
            foreach ($body['tags'] as $tag) {
                if (!is_string($tag)) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'tags must be an array of strings');
                }

                $trimmed = trim($tag);
                if ($trimmed === '') {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'tags cannot contain empty values');
                }

                $tags[] = $trimmed;
            }

            $metadata['tags'] = array_values(array_unique($tags));
        }

        if (array_key_exists('summary', $body)) {
            $summary = $this->nullableString($body['summary']);
            if ($summary === null) {
                unset($metadata['summary']);
            } else {
                $metadata['summary'] = $summary;
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed>|null $existingArtifact
     */
    private function validateArtifactLinks(?string $projectId, ?string $sprintId, ?array $existingArtifact = null): ?Response
    {
        if ($this->projectStore === null) {
            return null;
        }

        $resolvedProjectId = $projectId;
        if ($resolvedProjectId !== null && $this->projectStore->getProject($resolvedProjectId) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        if ($sprintId !== null) {
            $sprint = $this->projectStore->getSprint($sprintId);
            if ($sprint === null) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
            }

            $sprintProjectId = (string) ($sprint['project_id'] ?? '');
            if ($resolvedProjectId !== null && $resolvedProjectId !== '' && $sprintProjectId !== '' && $resolvedProjectId !== $sprintProjectId) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'sprint_id does not belong to project_id');
            }

            if ($resolvedProjectId === null && $existingArtifact !== null) {
                $existingProjectId = is_string($existingArtifact['project_id'] ?? null) ? (string) $existingArtifact['project_id'] : null;
                if ($existingProjectId !== null && $existingProjectId !== '' && $existingProjectId !== $sprintProjectId) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'sprint_id does not belong to the artifact project');
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    private function normalizeArtifact(array $artifact): array
    {
        $artifact['metadata'] = JsonHelper::decodeJsonObject($artifact['metadata'] ?? null);

        return $artifact;
    }

    private function nullableId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
