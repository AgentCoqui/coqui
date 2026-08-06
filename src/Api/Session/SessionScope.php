<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Contract\SessionType;

final readonly class SessionScope
{
    /**
     * @param list<string> $groupMembers
     */
    public function __construct(
        public SessionType $type,
        public string $modelRole,
        public ?string $persona = null,
        public array $groupMembers = [],
        public ?int $groupMaxRounds = null,
        public bool $confirmCloseActivePersonaSession = false,
        public bool $confirmCloseActiveGroupSession = false,
    ) {}
}