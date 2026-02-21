<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Middleware;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Per-IP token bucket rate limiter.
 *
 * Tracks request counts per client IP using an in-memory bucket.
 * Tokens refill over time. Exempt paths (health, OPTIONS) are not counted.
 *
 * Stateless across server restarts (in-memory only).
 */
final class RateLimitMiddleware
{
    /** @var array<string, array{tokens: float, last: float}> */
    private array $buckets = [];

    /** Paths exempt from rate limiting */
    private const array EXEMPT_PATHS = ['/api/health'];

    /**
     * @param int $maxRequests  Maximum requests per window (bucket capacity).
     * @param int $windowSeconds  Window duration in seconds (refill period).
     */
    public function __construct(
        private readonly int $maxRequests = 30,
        private readonly int $windowSeconds = 60,
    ) {}

    /**
     * @param callable(ServerRequestInterface): Response $next
     */
    public function __invoke(ServerRequestInterface $request, callable $next): Response
    {
        // Skip rate limiting for exempt paths and preflight
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        if ($method === 'OPTIONS' || in_array($path, self::EXEMPT_PATHS, true)) {
            return $next($request);
        }

        $clientIp = $this->resolveClientIp($request);

        if (!$this->consume($clientIp)) {
            $retryAfter = (int) ceil($this->windowSeconds / $this->maxRequests);

            /** @phpstan-ignore method.internalClass */
            $rateLimitResponse = Router::errorResponse(
                ApiErrorCode::RATE_LIMITED,
                'Too many requests. Please try again later.',
                ['retry_after' => $retryAfter],
            )->withHeader('Retry-After', (string) $retryAfter);

            return $rateLimitResponse;
        }

        $response = $next($request);

        // Add rate limit headers for visibility
        $bucket = $this->buckets[$clientIp] ?? null;
        $remaining = $bucket !== null ? max(0, (int) floor($bucket['tokens'])) : $this->maxRequests;

        /** @phpstan-ignore method.internalClass, method.internalClass */
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    /**
     * Try to consume a token from the client's bucket.
     *
     * Returns true if the request is allowed, false if rate limited.
     */
    private function consume(string $clientIp): bool
    {
        $now = microtime(true);

        if (!isset($this->buckets[$clientIp])) {
            $this->buckets[$clientIp] = [
                'tokens' => (float) ($this->maxRequests - 1),
                'last' => $now,
            ];
            return true;
        }

        $bucket = &$this->buckets[$clientIp];
        $elapsed = $now - $bucket['last'];
        $refillRate = $this->maxRequests / $this->windowSeconds;

        // Refill tokens based on elapsed time
        $bucket['tokens'] = min(
            (float) $this->maxRequests,
            $bucket['tokens'] + ($elapsed * $refillRate),
        );
        $bucket['last'] = $now;

        if ($bucket['tokens'] < 1.0) {
            return false;
        }

        $bucket['tokens'] -= 1.0;

        // Periodic cleanup: remove stale entries to prevent memory growth
        if (count($this->buckets) > 1000) {
            $this->cleanup($now);
        }

        return true;
    }

    /**
     * Remove stale bucket entries that have fully refilled.
     */
    private function cleanup(float $now): void
    {
        $staleThreshold = $now - ($this->windowSeconds * 2);

        foreach ($this->buckets as $ip => $bucket) {
            if ($bucket['last'] < $staleThreshold) {
                unset($this->buckets[$ip]);
            }
        }
    }

    /**
     * Extract client IP from request.
     *
     * Checks X-Forwarded-For first (for reverse proxy setups), then falls
     * back to the server params REMOTE_ADDR.
     */
    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            // Take the first IP (client IP in standard proxy chains)
            $ips = array_map('trim', explode(',', $forwarded));
            return $ips[0];
        }

        $serverParams = $request->getServerParams();

        return (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
    }
}
