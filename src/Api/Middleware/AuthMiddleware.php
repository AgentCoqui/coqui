<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * API key authentication middleware.
 *
 * Reads the expected key from openclaw.json config (`api.key`).
 * If no key is configured, all requests pass (local-only mode).
 * When configured, requires `Authorization: Bearer <key>` on all requests.
 */
final class AuthMiddleware
{
    public function __construct(
        private readonly ?string $apiKey = null,
    ) {}

    /**
     * @param callable(ServerRequestInterface): Response $next
     */
    public function __invoke(ServerRequestInterface $request, callable $next): Response
    {
        // No key configured — allow all requests (local-only mode)
        if ($this->apiKey === null || $this->apiKey === '') {
            return $next($request);
        }

        // Skip auth for OPTIONS preflight
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $next($request);
        }

        // Skip auth for health endpoint
        if ($request->getUri()->getPath() === '/api/health') {
            return $next($request);
        }

        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '') {
            return $this->unauthorized('Missing Authorization header');
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('Invalid Authorization format. Expected: Bearer <key>');
        }

        $token = substr($authHeader, 7);

        if (!hash_equals($this->apiKey, $token)) {
            return $this->unauthorized('Invalid API key');
        }

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return Router::errorResponse(ApiErrorCode::UNAUTHORIZED, $message);
    }
}
