<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

use CoquiBot\Coqui\Api\ApiErrorCode;

final class GroupSessionException extends SessionTypeException
{
    public function __construct(
        ApiErrorCode $errorCode,
        string $message,
        mixed $details = null,
    ) {
        parent::__construct($errorCode, $message, $details);
    }
}