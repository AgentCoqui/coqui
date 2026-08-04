<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Parsed HTTP precondition headers for optimistic-concurrency mutations.
 *
 * CAP 0.5.0 distinguishes three mutation intents on a versioned Core object
 * (persona, role, loop definition, ...):
 *
 *  - create:        `If-None-Match: *`   — fail if the object already exists.
 *  - guarded update: `If-Match: <version>` — fail (409/412) if the stored
 *                    version differs from the client's expectation.
 *  - unconditional:  neither header       — apply without a concurrency guard.
 */
final readonly class Precondition
{
    public function __construct(
        public bool $isCreate,
        public ?int $expectedVersion,
        public bool $isUnconditional,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        $ifMatch = trim($request->getHeaderLine('If-Match'));

        return new self(
            isCreate: $ifNoneMatch === '*',
            expectedVersion: $ifMatch === '' ? null : self::parseVersion($ifMatch),
            isUnconditional: $ifNoneMatch === '' && $ifMatch === '',
        );
    }

    /**
     * Parse an If-Match ETag into an integer version.
     *
     * Tolerates the weak-validator prefix (`W/`) and surrounding double quotes
     * so `W/"7"`, `"7"`, and `7` all resolve to 7. A non-numeric token (e.g. the
     * `*` wildcard) yields null — there is no concrete version to match.
     */
    private static function parseVersion(string $raw): ?int
    {
        $token = trim($raw);
        if (str_starts_with($token, 'W/')) {
            $token = substr($token, 2);
        }
        $token = trim($token, '"');

        return ctype_digit($token) ? (int) $token : null;
    }
}
