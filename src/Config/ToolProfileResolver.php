<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Resolves the always-eager core tool set for the active tool profile.
 *
 * The lean profile (default) keeps only a bootstrap core eager and defers
 * everything else behind tool_search. The full profile reproduces the legacy
 * everything-on behavior. An explicit agents.defaults.coreToolkits list
 * overrides the profile's toolkit preset for advanced self-hosters.
 */
final class ToolProfileResolver
{
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function profile(): string
    {
        $raw = $this->config->get('agents.defaults.toolProfile', CoquiDefaults::TOOL_PROFILE_DEFAULT);
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return $value === CoquiDefaults::TOOL_PROFILE_FULL
            ? CoquiDefaults::TOOL_PROFILE_FULL
            : CoquiDefaults::TOOL_PROFILE_LEAN;
    }

    public function isFull(): bool
    {
        return $this->profile() === CoquiDefaults::TOOL_PROFILE_FULL;
    }

    /**
     * Toolkit basenames that stay eager. An explicit coreToolkits config list
     * wins over the profile preset.
     *
     * @return list<string>
     */
    public function coreToolkits(): array
    {
        $override = $this->config->get('agents.defaults.coreToolkits');
        if (is_array($override)) {
            return array_values(array_filter($override, 'is_string'));
        }

        return $this->isFull()
            ? CoquiDefaults::SYSTEM_TOOLKITS
            : CoquiDefaults::LEAN_CORE_TOOLKITS;
    }

    /**
     * Standalone tool names that stay eager. Under full, all standalone tools
     * are eager (nothing deferred).
     *
     * @return list<string>
     */
    public function coreTools(): array
    {
        return $this->isFull()
            ? CoquiDefaults::ALL_STANDALONE_TOOLS
            : CoquiDefaults::LEAN_CORE_TOOLS;
    }
}
