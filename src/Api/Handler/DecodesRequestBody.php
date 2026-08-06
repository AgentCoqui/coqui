<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Precondition;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Exception\RequestBodyException;
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

    /**
     * Decode a strict authoring (create) body.
     *
     * Rejects any key outside `required ∪ optional` (server-owned fields like
     * id/version/timestamps are simply absent from the allow-set, so they are
     * rejected here) and any missing required key. All rejections are 422
     * `validation_error` with a `details` object naming the offending field(s).
     *
     * @param list<string> $required
     * @param list<string> $optional
     * @return array<string, mixed>
     * @throws RequestBodyException
     */
    private function decodeAuthoringBody(ServerRequestInterface $request, array $required, array $optional): array
    {
        $body = $this->decodeJsonObjectStrict($request);

        $unexpected = array_values(array_diff(array_keys($body), [...$required, ...$optional]));
        if ($unexpected !== []) {
            throw new RequestBodyException(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unexpected field(s): %s', implode(', ', $unexpected)),
                ['unexpected_fields' => $unexpected],
            );
        }

        $missing = array_values(array_diff($required, array_keys($body)));
        if ($missing !== []) {
            throw new RequestBodyException(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Missing required field(s): %s', implode(', ', $missing)),
                ['missing_fields' => $missing],
            );
        }

        return $body;
    }

    /**
     * Decode a strict PATCH body.
     *
     * Rejects any key outside `allowed` and an empty `{}` (at least one field is
     * required to describe the change). Both are 422 `validation_error`.
     *
     * @param list<string> $allowed
     * @return array<string, mixed>
     * @throws RequestBodyException
     */
    private function decodePatchBody(ServerRequestInterface $request, array $allowed): array
    {
        $body = $this->decodeJsonObjectStrict($request);

        $unexpected = array_values(array_diff(array_keys($body), $allowed));
        if ($unexpected !== []) {
            throw new RequestBodyException(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unexpected field(s): %s', implode(', ', $unexpected)),
                ['unexpected_fields' => $unexpected],
            );
        }

        if ($body === []) {
            throw new RequestBodyException(
                ApiErrorCode::VALIDATION_ERROR,
                'at least one field required',
                ['reason' => 'empty_patch'],
            );
        }

        return $body;
    }

    /**
     * Read the optimistic-concurrency preconditions (If-Match / If-None-Match).
     */
    private function readPrecondition(ServerRequestInterface $request): Precondition
    {
        return Precondition::fromRequest($request);
    }

    /**
     * Decode the body as a JSON object or throw a 422 RequestBodyException.
     *
     * @return array<string, mixed>
     * @throws RequestBodyException
     */
    private function decodeJsonObjectStrict(ServerRequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);
        if (!is_array($decoded)) {
            throw new RequestBodyException(
                ApiErrorCode::VALIDATION_ERROR,
                'Request body must be a JSON object',
                ['reason' => 'invalid_json'],
            );
        }

        return $decoded;
    }
}
