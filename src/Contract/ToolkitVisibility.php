<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Three-tier visibility for toolkits and standalone tools.
 *
 * - Enabled  → full schema in LLM context (default)
 * - Stub     → minimal schema; LLM discovers full details via tool_search
 * - Disabled → not instantiated / not in any tool list; invisible to LLM
 *
 * Protection tiers:
 * - ALWAYS_ENABLED tools bypass all visibility checks (tool_search, credentials).
 * - CANNOT_DISABLE tools may be stubbed but never fully disabled (spawn_agent, etc.).
 */
enum ToolkitVisibility: string
{
    case Enabled  = 'enabled';
    case Stub     = 'stub';
    case Disabled = 'disabled';

    /**
     * Tools that can NEVER be stubbed or disabled.
     * Critical infrastructure — disabling breaks discovery/auth.
     */
    public const array ALWAYS_ENABLED = ['tool_search', 'credentials'];

    /**
     * Tools that can be stubbed but NOT fully disabled.
     * Functional but optional to surface with full schema.
     */
    public const array CANNOT_DISABLE = ['spawn_agent', 'vision_analyze', 'restart_coqui', 'summarize_conversation'];

    /**
     * Whether a tool name is protected from any visibility change.
     */
    public static function isAlwaysEnabled(string $toolName): bool
    {
        return in_array($toolName, self::ALWAYS_ENABLED, strict: true);
    }

    /**
     * Whether a tool can be moved to Disabled state.
     */
    public static function canDisable(string $toolName): bool
    {
        return !in_array($toolName, self::ALWAYS_ENABLED, strict: true)
            && !in_array($toolName, self::CANNOT_DISABLE, strict: true);
    }

    /**
     * Whether a tool can be moved to Stub state.
     */
    public static function canStub(string $toolName): bool
    {
        return !in_array($toolName, self::ALWAYS_ENABLED, strict: true);
    }
}
