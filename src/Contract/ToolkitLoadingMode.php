<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Loading mode for a toolkit — controls *when* it enters LLM context.
 *
 * Orthogonal to ToolkitVisibility (which controls *whether* the LLM sees it).
 *
 * - System:   Always loaded with full schema (hardcoded, immutable)
 * - Eager:    User override — always loaded regardless of budget
 * - Deferred: User override — always deferred regardless of budget/frequency
 * - Auto:     Budget gate decides based on token budget and usage frequency
 */
enum ToolkitLoadingMode: string
{
    /** Always loaded — hardcoded system toolkits (immutable). */
    case System = 'system';

    /** User override — always loaded with full schema regardless of budget. */
    case Eager = 'eager';

    /** User override — always deferred (StubToolkit) regardless of budget. */
    case Deferred = 'deferred';

    /** Default — budget gate decides based on token budget and usage frequency. */
    case Auto = 'auto';

    /**
     * Whether this mode can be persisted to the loading registry.
     *
     * System and Auto are internal-only modes. System is resolved from
     * CoquiDefaults::SYSTEM_TOOLKITS, Auto is the default when no override exists.
     */
    public function isPersistable(): bool
    {
        return $this === self::Eager || $this === self::Deferred;
    }

    /**
     * Whether this mode guarantees the toolkit is fully loaded (not deferred).
     */
    public function isLoaded(): bool
    {
        return $this === self::System || $this === self::Eager;
    }
}
