<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

use CoquiBot\Coqui\Api\ApiErrorCode;

/**
 * Signals a strict request-body rejection (unknown/missing/forbidden field,
 * malformed JSON, or an empty patch).
 *
 * Carries the CAP error code, the intended HTTP status (422 for a validation
 * rejection), and a `details` object naming the offending field(s). Handlers
 * catch it and render it verbatim via `Router::errorResponse(...)`.
 */
final class RequestBodyException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details Always a JSON object naming the offending field(s).
     */
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message,
        public readonly array $details,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
