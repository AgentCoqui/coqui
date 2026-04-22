<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

use CoquiBot\Coqui\Api\ApiErrorCode;

final class GroupSessionException extends \RuntimeException
{
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}