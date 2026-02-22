<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Content-Type validation middleware.
 *
 * Requires POST, PUT, and PATCH requests to include a JSON or multipart content type.
 * Rejects requests with missing or unsupported Content-Type headers.
 */
final class ContentTypeMiddleware
{
    /**
     * @param callable(ServerRequestInterface): Response $next
     */
    public function __invoke(ServerRequestInterface $request, callable $next): Response
    {
        $method = strtoupper($request->getMethod());

        // Only validate body-carrying methods
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $contentType = $request->getHeaderLine('Content-Type');

        $lower = strtolower($contentType);

        // Accept any JSON content type (application/json, application/vnd.api+json, etc.)
        if ($contentType !== '' && str_contains($lower, 'json')) {
            return $next($request);
        }

        // Accept multipart/form-data for file uploads
        if ($contentType !== '' && str_contains($lower, 'multipart/form-data')) {
            return $next($request);
        }

        // If no body is present, allow the request through
        $bodySize = $request->getBody()->getSize();
        if ($bodySize === 0 || $bodySize === null) {
            return $next($request);
        }

        return Router::errorResponse(
            ApiErrorCode::UNSUPPORTED_MEDIA_TYPE,
            'Content-Type must be application/json or multipart/form-data',
        );
    }
}
