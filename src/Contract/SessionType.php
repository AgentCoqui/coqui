<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Canonical session-type discriminator used across API, REPL, and execution.
 */
enum SessionType: string
{
    case Interactive = 'interactive';
    case Group = 'group';

    /**
     * @param array<string, mixed> $session
     */
    public static function fromSessionRow(array $session): self
    {
        $raw = $session['session_type'] ?? null;
        if (is_string($raw)) {
            $resolved = self::tryFrom($raw);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return ((int) ($session['group_enabled'] ?? 0)) === 1
            ? self::Group
            : self::Interactive;
    }

    public static function fromGroupFlag(bool $groupEnabled): self
    {
        return $groupEnabled ? self::Group : self::Interactive;
    }
}