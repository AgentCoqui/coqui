<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Security guardrails for agent-driven config modifications.
 *
 * Defines which config sections the agent can modify via ConfigTool.
 * Security-sensitive settings (blacklist, shell allowlist, API keys,
 * workspace path, mounts) are always denied.
 */
final class ConfigGuard
{
    /**
     * Dot-notation prefixes the agent IS allowed to modify.
     *
     * @var string[]
     */
    private const array ALLOWED_PREFIXES = [
        'agents.defaults.editHistory.',
        'agents.defaults.model.primary',
        'agents.defaults.model.fallbacks',
        'agents.defaults.roles.',
        'agents.defaults.maxIterations',
        'agents.defaults.maxTools',
        'agents.defaults.hints',
    ];

    /**
     * Dot-notation prefixes that are always denied, even if they match an allowed prefix.
     * Evaluated before ALLOWED_PREFIXES for explicit blocking.
     *
     * @var string[]
     */
    private const array DENIED_PREFIXES = [
        'agents.defaults.blacklist',
        'agents.defaults.shellAllowedCommands',
        'agents.defaults.mcp.allowedStdioCommands',
        'agents.defaults.mcp.deniedStdioCommands',
        'agents.defaults.workspace',
        'agents.defaults.mounts',
        'api.',
        'models.providers.',
    ];

    /**
     * Check if the agent is allowed to modify a given config key.
     */
    public function canModify(string $dotKey): bool
    {
        // Explicit deny takes priority
        foreach (self::DENIED_PREFIXES as $denied) {
            if ($dotKey === $denied || str_starts_with($dotKey, $denied)) {
                return false;
            }
        }

        // Check against allowed prefixes
        foreach (self::ALLOWED_PREFIXES as $allowed) {
            if ($dotKey === $allowed || str_starts_with($dotKey, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter a data array to only include agent-writable keys.
     *
     * Operates on a flat dot-notation map and returns only allowed entries.
     *
     * @param array<string, mixed> $flatData Dot-notation key => value map
     * @return array<string, mixed> Only the allowed entries
     */
    public function filterWritableKeys(array $flatData): array
    {
        return array_filter(
            $flatData,
            fn(mixed $value, string $key): bool => $this->canModify($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Return the reason a key is denied, or null if it's allowed.
     */
    public function denyReason(string $dotKey): ?string
    {
        if (!$this->canModify($dotKey)) {
            return match (true) {
                str_starts_with($dotKey, 'agents.defaults.blacklist') => 'Security blacklist patterns cannot be modified by the agent',
                str_starts_with($dotKey, 'agents.defaults.shellAllowedCommands') => 'Shell command allowlist cannot be modified by the agent',
                str_starts_with($dotKey, 'agents.defaults.mcp.allowedStdioCommands') => 'MCP stdio allowlist cannot be modified by the agent',
                str_starts_with($dotKey, 'agents.defaults.mcp.deniedStdioCommands') => 'MCP stdio denylist cannot be modified by the agent',
                str_starts_with($dotKey, 'agents.defaults.workspace') => 'Workspace path cannot be modified by the agent',
                str_starts_with($dotKey, 'agents.defaults.mounts') => 'Mount definitions cannot be modified by the agent',
                str_starts_with($dotKey, 'api.') => 'API configuration cannot be modified by the agent',
                str_starts_with($dotKey, 'models.providers.') => 'Provider configurations cannot be modified by the agent (use role assignments instead)',
                default => sprintf('Key "%s" is not in the allowed modification list', $dotKey),
            };
        }

        return null;
    }
}
