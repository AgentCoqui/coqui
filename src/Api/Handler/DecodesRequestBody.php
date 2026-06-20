<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Shared JSON request-body decoding for API handlers.
 *
 * Centralizes the "decode the body as a JSON object" idiom that the
 * handlers previously reimplemented, in both the null-returning and
 * error-Response-returning shapes.
 */
trait DecodesRequestBody
{
    /**
     * Decode the request body as a JSON object, or null when it is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObjectOrNull(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Decode the request body as a JSON object, or a 422 error Response when invalid.
     *
     * @return array<string, mixed>|Response
     */
    private function decodeJsonObjectOrError(ServerRequestInterface $request): array|Response
    {
        $decoded = json_decode((string) $request->getBody(), true);
        if (!is_array($decoded)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        return $decoded;
    }
}
