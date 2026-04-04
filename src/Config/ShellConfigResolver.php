<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;

/**
 * Shared shell command resolution logic.
 *
 * Centralizes the three duplicate implementations that existed in
 * OrchestratorAgent, RunCommand, and BackgroundToolExecutor.
 */
final class ShellConfigResolver
{
    /** Read-only shell commands for readonly-shell access level. */
    public const array READ_ONLY_SHELL_COMMANDS = [
        'grep', 'cat', 'head', 'tail', 'wc', 'ls', 'uniq', 'diff',
    ];

    /**
     * Resolve shell allowed commands from config.
     *
     * Reads `agents.defaults.shellAllowedCommands` from openclaw.json.
     * If not set, returns empty array (all commands allowed — open mode).
     *
     * @return list<string>
     */
    public static function resolveAllowed(ConfigInterface $config): array
    {
        $configured = $config->get('agents.defaults.shellAllowedCommands');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return [];
    }

    /**
     * Resolve shell denied commands from config.
     *
     * By default, only `sudo` is denied. Users can unlock sudo access
     * by setting `agents.defaults.allowSudo: true` in openclaw.json.
     *
     * @return list<string>
     */
    public static function resolveDenied(ConfigInterface $config): array
    {
        $allowSudo = filter_var(
            $config->get('agents.defaults.allowSudo', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        return $allowSudo ? [] : ['sudo'];
    }
}
