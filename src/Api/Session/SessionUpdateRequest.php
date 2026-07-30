<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

final readonly class SessionUpdateRequest
{
    public function __construct(
        public bool $updatesTitle = false,
        public ?string $title = null,
        public bool $updatesModelRole = false,
        public ?string $modelRole = null,
        public bool $updatesPersona = false,
        public ?string $persona = null,
        public bool $updatesGroupEnabled = false,
        public ?bool $groupEnabled = null,
        public bool $includesMembers = false,
        public bool $updatesGroupMaxRounds = false,
        public mixed $groupMaxRounds = null,
        public bool $confirmCloseActivePersonaSession = false,
    ) {}
}