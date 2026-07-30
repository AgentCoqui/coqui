<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Represents the outcome of routing a slash command.
 *
 * Carries optional state changes (role, session) and exit codes
 * back to RunCommand without handlers needing direct access to mutable state.
 */
final readonly class RouteResult
{
    private function __construct(
        public bool $shouldContinue,
        public ?int $exitCode = null,
        public ?string $newActiveRole = null,
        public ?string $newSessionId = null,
        public ?string $newActiveProjectId = null,
        public ?string $newActivePersona = null,
    ) {}

    public static function continue(): self
    {
        return new self(shouldContinue: true);
    }

    public static function exit(int $code): self
    {
        return new self(shouldContinue: false, exitCode: $code);
    }

    public static function stateChange(
        ?string $newActiveRole = null,
        ?string $newSessionId = null,
        ?string $newActiveProjectId = null,
        ?string $newActivePersona = null,
    ): self {
        return new self(
            shouldContinue: true,
            newActiveRole: $newActiveRole,
            newSessionId: $newSessionId,
            newActiveProjectId: $newActiveProjectId,
            newActivePersona: $newActivePersona,
        );
    }
}
