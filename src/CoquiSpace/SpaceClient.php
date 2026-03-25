<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use CoquiBot\Coqui\Config\PathHelper;

/**
 * HTTP client for the Coqui Space REST API.
 *
 * Uses closure-based URL and token resolution for hot-reload compatibility —
 * CredentialTool::set() -> putenv() takes effect on the next API call.
 */
final class SpaceClient
{
    private const int TIMEOUT = 30;

    private const int MAX_ERROR_LENGTH = 500;

    /** @var \Closure(): string */
    private \Closure $urlResolver;

    /** @var \Closure(): string */
    private \Closure $tokenResolver;

    /**
     * @param \Closure(): string $urlResolver   Returns the API base URL
     * @param \Closure(): string $tokenResolver Returns the bearer token (empty = anonymous)
     */
    public function __construct(
        \Closure $urlResolver,
        \Closure $tokenResolver,
        private readonly HttpClientInterface $http,
    ) {
        $this->urlResolver = $urlResolver;
        $this->tokenResolver = $tokenResolver;
    }

    // -- Skills ---------------------------------------------------------------

    /**
     * Search skills by keyword.
     *
     * @return array<string, mixed>
     */
    public function searchSkills(string $query, int $limit = 10, ?string $cursor = null): array
    {
        $params = ['q' => $query, 'limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/search', $params);
    }

    /**
     * List published skills with sorting and filtering.
     *
     * @return array<string, mixed>
     */
    public function listSkills(
        string $sort = 'updated',
        ?string $tags = null,
        int $limit = 20,
        ?string $cursor = null,
    ): array {
        $params = ['sort' => $sort, 'limit' => $limit];
        if ($tags !== null) {
            $params['tags'] = $tags;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/skills', $params);
    }

    /**
     * Get full details for a skill.
     *
     * @return array<string, mixed>
     */
    public function skillDetails(string $owner, string $name): array
    {
        return $this->get("/skills/{$owner}/{$name}");
    }

    /**
     * Get version history for a skill.
     *
     * @return array<string, mixed>
     */
    public function skillVersions(string $owner, string $name, int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get("/skills/{$owner}/{$name}/versions", $params);
    }

    /**
     * Get reviews for a skill.
     *
     * @return array<string, mixed>
     */
    public function skillReviews(string $owner, string $name, int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get("/skills/{$owner}/{$name}/reviews", $params);
    }

    /**
     * Get the raw SKILL.md content for a skill.
     */
    public function skillFile(string $owner, string $name): string
    {
        $url = $this->baseUrl() . "/skills/{$owner}/{$name}/file";

        $response = $this->http->request('GET', $url, [
            'timeout' => self::TIMEOUT,
            'headers' => $this->headers(false),
        ]);

        return $response->getContent();
    }

    /**
     * Download a skill as a ZIP archive.
     *
     * @return array{bytes: string, filename: string}
     */
    public function downloadSkill(string $owner, string $name, ?string $version = null): array
    {
        $params = ['username' => $owner, 'name' => $name];
        if ($version !== null) {
            $params['version'] = $version;
        }

        $url = $this->baseUrl() . '/download?' . http_build_query($params);

        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => array_merge($this->headers(false), [
                    'Accept' => 'application/zip',
                ]),
            ]);

            $bytes = $response->getContent();

            $disposition = $response->getHeaders()['content-disposition'][0] ?? '';
            $filename = $this->parseFilename($disposition) ?? "{$name}.zip";

            return ['bytes' => $bytes, 'filename' => $filename];
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Failed to download skill %s/%s: HTTP %d — %s',
                $owner,
                $name,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * Log a skill install (fire-and-forget stats tracking).
     *
     * @return array<string, mixed>
     */
    public function logSkillInstall(string $owner, string $name, ?string $clientVersion = null): array
    {
        $data = [];
        if ($clientVersion !== null) {
            $data['clientVersion'] = $clientVersion;
        }

        return $this->post("/skills/{$owner}/{$name}/install", $data);
    }

    // -- Toolkits -------------------------------------------------------------

    /**
     * List published toolkits with cursor-based pagination.
     *
     * @return array<string, mixed>
     */
    public function listToolkits(
        string $sort = 'downloads',
        ?string $tags = null,
        int $limit = 20,
        ?string $cursor = null,
    ): array {
        $params = ['sort' => $sort, 'limit' => $limit];
        if ($tags !== null) {
            $params['tags'] = $tags;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/toolkits', $params);
    }

    /**
     * Search toolkits (Packagist-style endpoint).
     *
     * @return array<string, mixed>
     */
    public function searchToolkits(string $query, int $perPage = 15, int $page = 1): array
    {
        return $this->get('/search.json', [
            'q' => $query,
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * List popular toolkits.
     *
     * @return array<string, mixed>
     */
    public function popularToolkits(int $perPage = 15, int $page = 1): array
    {
        return $this->get('/explore/popular.json', [
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * Get toolkit details by owner/name.
     *
     * @return array<string, mixed>
     */
    public function toolkitDetails(string $owner, string $name): array
    {
        return $this->get("/toolkits/{$owner}/{$name}");
    }

    /**
     * Get toolkit Packagist-format metadata by vendor/package name.
     *
     * @return array<string, mixed>
     */
    public function toolkitPackage(string $vendor, string $package): array
    {
        return $this->get("/packages/{$vendor}/{$package}.json");
    }

    /**
     * Get reviews for a toolkit.
     *
     * @return array<string, mixed>
     */
    public function toolkitReviews(string $owner, string $name, int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get("/toolkits/{$owner}/{$name}/reviews", $params);
    }

    /**
     * Log a toolkit install (fire-and-forget stats tracking).
     *
     * @return array<string, mixed>
     */
    public function logToolkitInstall(string $owner, string $name, ?string $clientVersion = null): array
    {
        $data = [];
        if ($clientVersion !== null) {
            $data['clientVersion'] = $clientVersion;
        }

        return $this->post("/toolkits/{$owner}/{$name}/install", $data);
    }

    // -- Social / User --------------------------------------------------------

    /**
     * Star a skill or toolkit.
     *
     * @return array<string, mixed>
     */
    public function star(string $entityType, string $owner, string $name): array
    {
        return $this->post("/stars/{$entityType}/{$owner}/{$name}", []);
    }

    /**
     * Unstar a skill or toolkit.
     *
     * @return array<string, mixed>
     */
    public function unstar(string $entityType, string $owner, string $name): array
    {
        return $this->delete("/stars/{$entityType}/{$owner}/{$name}");
    }

    /**
     * Get the authenticated user's profile.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->get('/me');
    }

    // -- Submissions ----------------------------------------------------------

    /**
     * Submit a skill or toolkit URL for review.
     *
     * @return array<string, mixed>
     */
    public function createSubmission(string $type, string $sourceUrl, ?string $notes = null): array
    {
        $data = ['type' => $type, 'sourceUrl' => $sourceUrl];
        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        return $this->post('/submissions', $data);
    }

    // -- Tags -----------------------------------------------------------------

    /**
     * Get available tags for skills and/or toolkits.
     *
     * @param string $type Filter: 'all', 'skills', or 'toolkits'
     * @return array<string, mixed>
     */
    public function getTags(string $type = 'all'): array
    {
        $params = [];
        if ($type !== 'all') {
            $params['type'] = $type;
        }

        return $this->get('/tags', $params);
    }

    // -- Unified Search -------------------------------------------------------

    /**
     * Search both skills and toolkits in a single request.
     *
     * @return array<string, mixed>
     */
    public function searchAll(string $query, int $limit = 10, ?string $cursor = null): array
    {
        $params = ['q' => $query, 'limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/search/all', $params);
    }

    // -- Reviews --------------------------------------------------------------

    /**
     * Post a review on a skill or toolkit.
     *
     * @return array<string, mixed>
     */
    public function createReview(string $entityType, string $owner, string $name, int $rating, ?string $title = null, ?string $body = null): array
    {
        $data = ['rating' => $rating];
        if ($title !== null) {
            $data['title'] = $title;
        }
        if ($body !== null) {
            $data['body'] = $body;
        }

        return $this->post("/{$entityType}s/{$owner}/{$name}/reviews", $data);
    }

    // -- Collections ----------------------------------------------------------

    /**
     * List public collections.
     *
     * @return array<string, mixed>
     */
    public function listCollections(int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/collections', $params);
    }

    /**
     * Create a new collection.
     *
     * @return array<string, mixed>
     */
    public function createCollection(string $name, ?string $description = null, bool $isPublic = true): array
    {
        $data = ['name' => $name, 'isPublic' => $isPublic];
        if ($description !== null) {
            $data['description'] = $description;
        }

        return $this->post('/collections', $data);
    }

    /**
     * Get a collection with its items.
     *
     * @return array<string, mixed>
     */
    public function getCollection(int $id): array
    {
        return $this->get("/collections/{$id}");
    }

    /**
     * Update a collection.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateCollection(int $id, array $data): array
    {
        return $this->patch("/collections/{$id}", $data);
    }

    /**
     * Delete a collection.
     *
     * @return array<string, mixed>
     */
    public function deleteCollection(int $id): array
    {
        return $this->delete("/collections/{$id}");
    }

    /**
     * Add an item to a collection.
     *
     * @return array<string, mixed>
     */
    public function addCollectionItem(int $collectionId, string $entityType, int $entityId, ?string $note = null): array
    {
        $data = ['entityType' => $entityType, 'entityId' => $entityId];
        if ($note !== null) {
            $data['note'] = $note;
        }

        return $this->post("/collections/{$collectionId}/items", $data);
    }

    /**
     * Remove an item from a collection.
     *
     * @return array<string, mixed>
     */
    public function removeCollectionItem(int $collectionId, string $entityType, int $entityId): array
    {
        $url = $this->baseUrl() . "/collections/{$collectionId}/items";

        try {
            $response = $this->http->request('DELETE', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(true),
                'json' => ['entityType' => $entityType, 'entityId' => $entityId],
            ]);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'DELETE /collections/%d/items failed: HTTP %d — %s',
                $collectionId,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    // -- User Account (/me) ---------------------------------------------------

    /**
     * List the authenticated user's skills.
     *
     * @return array<string, mixed>
     */
    public function mySkills(int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/me/skills', $params);
    }

    /**
     * List the authenticated user's toolkits.
     *
     * @return array<string, mixed>
     */
    public function myToolkits(): array
    {
        return $this->get('/me/toolkits');
    }

    /**
     * List the authenticated user's collections.
     *
     * @return array<string, mixed>
     */
    public function myCollections(): array
    {
        return $this->get('/me/collections');
    }

    /**
     * List the authenticated user's starred items.
     *
     * @return array<string, mixed>
     */
    public function myStars(int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/me/stars', $params);
    }

    /**
     * List the authenticated user's install history.
     *
     * @return array<string, mixed>
     */
    public function myInstalls(int $limit = 50, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/me/installs', $params);
    }

    /**
     * List the authenticated user's submissions.
     *
     * @return array<string, mixed>
     */
    public function mySubmissions(): array
    {
        return $this->get('/me/submissions');
    }

    /**
     * Get the authenticated user's analytics.
     *
     * @return array<string, mixed>
     */
    public function myAnalytics(int $days = 30): array
    {
        return $this->get('/me/analytics', ['days' => $days]);
    }

    /**
     * Get the authenticated user's notifications.
     *
     * @return array<string, mixed>
     */
    public function myNotifications(int $limit = 50, ?bool $unread = null): array
    {
        $params = ['limit' => $limit];
        if ($unread !== null) {
            $params['unread'] = $unread ? 'true' : 'false';
        }

        return $this->get('/me/notifications', $params);
    }

    /**
     * Mark notifications as read.
     *
     * @param array<string, mixed> $data  e.g. ['all' => true] or ['id' => 123]
     * @return array<string, mixed>
     */
    public function markNotificationsRead(array $data): array
    {
        return $this->post('/me/notifications', $data);
    }

    // -- Health ---------------------------------------------------------------

    /**
     * Check API health status.
     *
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return $this->get('/health');
    }

    /**
     * Whether a token is currently configured.
     */
    public function isAuthenticated(): bool
    {
        return $this->token() !== '';
    }

    // -- HTTP primitives ------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl() . $path;
        $options = [
            'timeout' => self::TIMEOUT,
            'headers' => $this->headers(true),
        ];

        if ($query !== []) {
            $options['query'] = $query;
        }

        try {
            $response = $this->http->request('GET', $url, $options);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'GET %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        $url = $this->baseUrl() . $path;

        try {
            $response = $this->http->request('POST', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(true),
                'json' => $data,
            ]);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'POST %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function patch(string $path, array $data): array
    {
        $url = $this->baseUrl() . $path;

        try {
            $response = $this->http->request('PATCH', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(true),
                'json' => $data,
            ]);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'PATCH %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $path): array
    {
        $url = $this->baseUrl() . $path;

        try {
            $response = $this->http->request('DELETE', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(true),
            ]);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'DELETE %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function baseUrl(): string
    {
        return PathHelper::trimTrailingSlash(($this->urlResolver)());
    }

    private function token(): string
    {
        return ($this->tokenResolver)();
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $withAuth): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'coqui/0.1.0',
        ];

        if ($withAuth) {
            $token = $this->token();
            if ($token !== '') {
                $headers['Authorization'] = "Bearer {$token}";
            }
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        try {
            /** @var array<string, mixed> */
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Failed to decode API response: {$e->getMessage()}");
        }
    }

    private function extractError(HttpExceptionInterface $e): string
    {
        try {
            $body = $e->getResponse()->getContent(false);
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                $message = $decoded['error'] ?? $decoded['message'] ?? $body;
            } else {
                $message = $body;
            }
        } catch (\Throwable) {
            $message = $e->getMessage();
        }

        if (is_array($message)) {
            $message = json_encode($message);
        }

        $message = (string) $message;

        if (strlen($message) > self::MAX_ERROR_LENGTH) {
            return substr($message, 0, self::MAX_ERROR_LENGTH) . '...';
        }

        return $message;
    }

    private function parseFilename(string $disposition): ?string
    {
        if (preg_match('/filename[*]?=["\']?([^"\';\s]+)/', $disposition, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
