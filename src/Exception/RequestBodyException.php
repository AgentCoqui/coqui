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

    /**
     * Render this rejection as the typed in-process thrown-error payload
     * (CAP `error-thrown.json`): the same closed `code` catalog and
     * human-readable `error` the HTTP binding carries. Empty details are
     * omitted; present details serialize as a JSON object, never `[]`.
     *
     * @return array{error: string, code: string, details?: object}
     */
    public function toThrownError(): array
    {
        $out = [
            'error' => $this->getMessage(),
            'code' => $this->errorCode->value,
        ];

        if ($this->details !== []) {
            $out['details'] = (object) $this->details;
        }

        return $out;
    }
}
