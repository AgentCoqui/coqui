<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Contract\SessionType;

interface SessionTypeHandlerInterface
{
    public function type(): SessionType;

    public function create(SessionScope $scope): SessionTypeOperationResult;

    public function resolve(SessionScope $scope): SessionTypeOperationResult;

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function update(array $session, SessionUpdateRequest $request): array;
}