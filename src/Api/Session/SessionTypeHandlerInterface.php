<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Contract\SessionType;

interface SessionTypeHandlerInterface
{
    public function type(): SessionType;

    public function create(SessionScope $scope): SessionTypeOperationResult;

    public function resolve(SessionScope $scope): SessionTypeOperationResult;
}