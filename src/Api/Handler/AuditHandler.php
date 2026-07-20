<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Storage\AuditLogQuery;
use CoquiBot\Coqui\Storage\AuditLogStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Audit-log read endpoints.
 *
 * GET /api/v1/audit                  — global, filterable, paginated
 * GET /api/v1/sessions/{id}/audit    — session-scoped convenience
 *
 * Both are normal authenticated routes. The API-key middleware supplies 401;
 * this handler never constructs one.
 */
final readonly class AuditHandler
{
    public function __construct(
        private AuditLogStore $store,
        private SessionStorage $storage,
    ) {}

    public function register(Router $router): void
    {
        $v1 = '/api/v1';

        $router->get($v1 . '/audit', [$this, 'list']);
        $router->get($v1 . '/sessions/{id}/audit', [$this, 'listForSession']);
    }

    /**
     * GET /api/v1/audit?session_id=&tool_name=&action=&after=&before=&limit=&offset=
     */
    public function list(ServerRequestInterface $request): Response
    {
        try {
            $query = AuditLogQuery::fromParams($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return Router::jsonResponse($this->envelope($query));
    }

    /**
     * GET /api/v1/sessions/{id}/audit
     */
    public function listForSession(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $params = $request->getQueryParams();

        // The path segment is authoritative — a session_id parameter must not widen scope.
        unset($params['session_id']);

        try {
            $query = AuditLogQuery::fromParams($params);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        $scoped = new AuditLogQuery(
            sessionId: $id,
            toolName: $query->toolName,
            action: $query->action,
            after: $query->after,
            before: $query->before,
            limit: $query->limit,
            offset: $query->offset,
        );

        return Router::jsonResponse(['session_id' => $id] + $this->envelope($scoped));
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(AuditLogQuery $query): array
    {
        return [
            'entries' => $this->store->query($query),
            'total' => $this->store->count($query),
            'limit' => $query->limit,
            'offset' => $query->offset,
        ];
    }
}
