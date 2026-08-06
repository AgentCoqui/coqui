<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\CursorPage;
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
 * POST   /api/v1/sessions/{id}/artifacts                  — create artifact
 * PATCH  /api/v1/sessions/{id}/artifacts/{artifactId}     — update artifact
 * DELETE /api/v1/sessions/{id}/artifacts/{artifactId}     — delete artifact
 *
 * Full CRUD is exposed over the API — create, update, and delete are wired.
 */
final readonly class ArtifactHandler
{
    use DecodesRequestBody;

    /**
     * Upper bound on rows fetched from storage before in-memory pagination.
     */
    private const int LIST_FETCH_CAP = 200;

    public function __construct(
        private ArtifactStore $store,
        private ?SessionStorage $sessionStorage = null,
        private ?ProjectStore $projectStore = null,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/artifacts?type=code
     *
     * `type` is the only supported filter. There is no `stage` parameter —
     * the stage machine was removed and the column is dormant.
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->requireReadableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $params = $request->getQueryParams();
        $type = isset($params['type']) ? trim((string) $params['type']) : null;

        // Fetch a full window (the store defaults to 50), then paginate in
        // memory. Declared default sort: updated_at DESC, id ASC — the store
        // orders by updated_at DESC; id is the stable tiebreak + cursor key.
        $artifacts = $this->store->list(
            sessionId: $id,
            type: $type !== '' ? $type : null,
            limit: self::LIST_FETCH_CAP,
        );
        $artifacts = array_map(fn(array $artifact): array => $this->normalizeArtifact($artifact), $artifacts);

        usort($artifacts, static function (array $a, array $b): int {
            $byUpdated = strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));

            return $byUpdated !== 0 ? $byUpdated : strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        $params = $request->getQueryParams();

        return Router::jsonResponse(CursorPage::build(
            $artifacts,
            CursorPage::limitFrom($params['limit'] ?? null),
            static fn(array $artifact): string => (string) ($artifact['id'] ?? ''),
            CursorPage::decode(isset($params['cursor']) ? (string) $params['cursor'] : null),
        ));
    }

    /**
     * GET /api/v1/sessions/{id}/artifacts/{artifactId}
     */
    public function get(ServerRequestInterface $request, string $id, string $artifactId): Response
    {
        return $this->artifactDetailResponse($id, $artifactId);
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

        $body = $this->decodeJsonObjectOrNull($request);
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

        $type = trim((string) ($body['type'] ?? 'document'));
        if ($type === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'type cannot be empty');
        }

        $language = array_key_exists('language', $body) ? $this->nullableString($body['language']) : null;
        $projectId = array_key_exists('project_id', $body) ? $this->nullableId($body['project_id']) : null;

        $linkValidation = $this->validateArtifactLinks($projectId);
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
            projectId: $projectId,
            metadata: $metadata,
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

        $body = $this->decodeJsonObjectOrNull($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $allowedKeys = ['title', 'content', 'metadata', 'tags', 'summary', 'project_id'];
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

        $projectId = array_key_exists('project_id', $body) ? $this->nullableId($body['project_id']) : null;

        $linkValidation = $this->validateArtifactLinks(array_key_exists('project_id', $body) ? $projectId : null, $artifact);
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

            $this->store->update($artifactId, $body['content'], $title, $id);
            $title = null; // already applied by update()
        }

        $patch = [];
        if ($title !== null) {
            $patch['title'] = $title;
        }

        if ($metadata !== null || array_key_exists('metadata', $body) || array_key_exists('tags', $body) || array_key_exists('summary', $body)) {
            $patch['metadata'] = $metadata;
        }

        if (array_key_exists('project_id', $body)) {
            $patch['project_id'] = $projectId;
        }

        if ($patch !== []) {
            $this->store->patch($artifactId, $patch, $id);
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
    private function validateArtifactLinks(?string $projectId, ?array $existingArtifact = null): ?Response
    {
        if ($this->projectStore === null) {
            return null;
        }

        if ($projectId !== null && $this->projectStore->getProject($projectId) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
        }

        return null;
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
