<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * CORS middleware for cross-origin API access.
 *
 * Handles OPTIONS preflight requests and adds CORS headers to all responses.
 * Configurable allowed origins — defaults to '*' for local development.
 */
final class CorsMiddleware
{
    /** @var string[] */
    private readonly array $allowedOrigins;

    /**
     * @param string[] $allowedOrigins  Allowed origins. Empty or ['*'] allows all.
     */
    public function __construct(
        array $allowedOrigins = ['*'],
    ) {
        $this->allowedOrigins = $allowedOrigins;
    }

    /**
     * @param callable(ServerRequestInterface): Response $next
     */
    public function __invoke(ServerRequestInterface $request, callable $next): Response
    {
        $origin = $request->getHeaderLine('Origin');

        $corsHeaders = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept',
            'Access-Control-Max-Age' => '86400',
        ];

        // Determine allowed origin
        if (in_array('*', $this->allowedOrigins, true) || $this->allowedOrigins === []) {
            $corsHeaders['Access-Control-Allow-Origin'] = '*';
        } elseif ($origin !== '' && in_array($origin, $this->allowedOrigins, true)) {
            $corsHeaders['Access-Control-Allow-Origin'] = $origin;
            $corsHeaders['Vary'] = 'Origin';
        }

        // Handle preflight
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return new Response(204, $corsHeaders);
        }

        // Process request and add CORS headers to response
        $response = $next($request);

        foreach ($corsHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
