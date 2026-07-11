<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;

/**
 * Shared shell command resolution logic.
 *
 * Centralizes the duplicate shell command resolution that existed in
 * OrchestratorAgent, RunCommand, and SpawnAgentTool.
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

    /**
     * Whether shell write sandboxing is enabled.
     *
     * Reads `agents.defaults.shell.sandboxWrites` from openclaw.json.
     * Defaults to true — shell commands cannot write outside workspace/mounts.
     */
    public static function resolveSandboxWrites(ConfigInterface $config): bool
    {
        return filter_var(
            $config->get('agents.defaults.shell.sandboxWrites', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Whether subprocess environment scrubbing is enabled.
     *
     * Reads `agents.defaults.shell.scrubEnvironment` from openclaw.json.
     * Defaults to true — API keys and secrets are stripped from subprocesses.
     */
    public static function resolveScrubEnvironment(ConfigInterface $config): bool
    {
        return filter_var(
            $config->get('agents.defaults.shell.scrubEnvironment', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
