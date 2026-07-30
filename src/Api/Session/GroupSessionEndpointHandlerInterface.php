<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

interface GroupSessionEndpointHandlerInterface
{
    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function listMembers(array $session): array;

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function replaceMembers(array $session, mixed $body): array;

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function addMember(array $session, mixed $body): array;

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function removeMember(array $session, string $persona, mixed $body): array;
}