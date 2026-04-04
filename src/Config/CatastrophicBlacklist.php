<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;

/**
 * Maintains a list of catastrophic command patterns that are ALWAYS blocked,
 * even in --unsafe or --auto-approve mode.
 *
 * Combines hardcoded patterns (rm -rf /, fork bombs, etc.) with user-defined
 * additions from openclaw.json under agents.defaults.blacklist.
 */
final class CatastrophicBlacklist
{
    /**
     * Patterns that can never be bypassed — always checked regardless of mode.
     *
     * @var string[]
     */
    private const HARDCODED_PATTERNS = [
        '/\brm\s+(-[a-zA-Z]*f[a-zA-Z]*\s+|-[a-zA-Z]*r[a-zA-Z]*f[a-zA-Z]*\s+|--force\s+).*\s*\/(\s|$|\*)/i',
        '/\brm\s+-[a-zA-Z]*r[a-zA-Z]*\s+\/(\s|$|\*)/i',
        '/\bshutdown\b/i',
        '/\breboot\b/i',
        '/\bmkfs\b/i',
        '/\bdd\s+if=/i',
        '/:\(\)\{\s*:\|:&\s*\};:/i',                              // Fork bomb
        '/>\s*\/dev\/sd[a-z]/i',                                   // Overwrite disk
        '/\bchmod\s+(-R\s+)?777\s+\//i',                          // chmod 777 /
        '/\bchown\s+(-R\s+)?.*\s+\//i',                           // chown / recursively
        '/\bwget\s.*-O-?\s*\|\s*(bash|sh|zsh)\b/i',               // wget pipe to shell
        '/\bcurl\s.*\|\s*(bash|sh|zsh)\b/i',                      // curl pipe to shell
        '/>\s*\/etc\/(passwd|shadow|sudoers)\b/i',                 // Overwrite auth files
        '/\b(halt|poweroff|init\s+0)\b/i',                        // System halt

        // Dotfile / shell config persistence
        '/>>?\s*~\/\.(bashrc|bash_profile|profile|zshrc|zprofile|login|zshenv)\b/i',
        // SSH config and key injection
        '/>>?\s*~\/\.ssh\/(authorized_keys|config|known_hosts|id_[a-z0-9_]+)\b/i',
        // Crontab manipulation
        '/\bcrontab\s+-\s/i',                                        // crontab - (stdin install)
        '/\bcrontab\s+-r\b/i',                                     // crontab remove
        // /proc and /sys writes
        '/>>?\s*\/proc\//i',
        '/>>?\s*\/sys\//i',
        // Startup/init persistence
        '/>>?\s*\/etc\/(cron\.d|cron\.\w+|init\.d|systemd)\//i',
        // Launchd persistence (macOS)
        '/>>?\s*~\/Library\/LaunchAgents\//i',
        '/>>?\s*\/Library\/Launch(Agents|Daemons)\//i',
    ];

    /**
     * @param string[] $additionalPatterns User-configured patterns from openclaw.json
     */
    public function __construct(
        private readonly array $additionalPatterns = [],
    ) {}

    /**
     * Build from an openclaw.json config.
     *
     * Reads patterns from agents.defaults.blacklist (array of regex strings).
     */
    public static function fromConfig(ConfigInterface $config): self
    {
        $userPatterns = $config->get('agents.defaults.blacklist', []);

        if (!is_array($userPatterns)) {
            $userPatterns = [];
        }

        // Validate that each pattern is a string
        $userPatterns = array_filter($userPatterns, fn(mixed $p): bool => is_string($p));

        return new self(array_values($userPatterns));
    }

    /**
     * Check if a command or code string matches any catastrophic pattern.
     *
     * @return string|null The matched pattern description, or null if safe.
     */
    public function matches(string $input): ?string
    {
        foreach (self::HARDCODED_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                return "Blocked by catastrophic safety pattern: {$pattern}";
            }
        }

        foreach ($this->additionalPatterns as $pattern) {
            if (@preg_match($pattern, $input)) {
                return "Blocked by user-configured safety pattern: {$pattern}";
            }
        }

        return null;
    }

    /**
     * @return string[] All active patterns (hardcoded + user-configured).
     */
    public function allPatterns(): array
    {
        return array_merge(self::HARDCODED_PATTERNS, $this->additionalPatterns);
    }
}
