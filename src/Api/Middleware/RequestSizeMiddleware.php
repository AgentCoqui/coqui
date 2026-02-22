<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Request body size limiter.
 *
 * Rejects requests with bodies exceeding the configured maximum size.
 * Only applies to methods that typically carry a body (POST, PUT, PATCH).
 */
final class RequestSizeMiddleware
{
    /**
     * @param int $maxBytes  Maximum allowed request body size in bytes. Default: 50 MiB.
     */
    public function __construct(
        private readonly int $maxBytes = 52_428_800,
    ) {}

    /**
     * @param callable(ServerRequestInterface): Response $next
     */
    public function __invoke(ServerRequestInterface $request, callable $next): Response
    {
        $method = strtoupper($request->getMethod());

        // Only check body-carrying methods
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        // Check Content-Length header first (fast path)
        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '' && (int) $contentLength > $this->maxBytes) {
            return Router::errorResponse(
                ApiErrorCode::PAYLOAD_TOO_LARGE,
                sprintf('Request body exceeds maximum size of %s bytes', number_format($this->maxBytes)),
            );
        }

        // Also check actual body size (in case Content-Length is missing/wrong)
        $bodySize = $request->getBody()->getSize();
        if ($bodySize !== null && $bodySize > $this->maxBytes) {
            return Router::errorResponse(
                ApiErrorCode::PAYLOAD_TOO_LARGE,
                sprintf('Request body exceeds maximum size of %s bytes', number_format($this->maxBytes)),
            );
        }

        return $next($request);
    }
}
