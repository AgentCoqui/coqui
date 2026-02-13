<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Best-effort static analysis of generated PHP code before execution.
 *
 * This is defense-in-depth — the interactive approval prompt is the primary
 * security gate. The sanitizer catches obvious dangerous patterns that the
 * LLM might produce (accidentally or via prompt injection).
 */
final class ScriptSanitizer
{
    /**
     * Function calls that are never allowed in generated scripts.
     *
     * @var string[]
     */
    private const DENIED_FUNCTIONS = [
        'eval',
        'exec',
        'system',
        'passthru',
        'shell_exec',
        'proc_open',
        'popen',
        'pcntl_exec',
        'dl',
        'putenv',
        'ini_set',
        'ini_alter',
        'apache_setenv',
    ];

    /**
     * Regex patterns that indicate dangerous constructs.
     *
     * @var string[]
     */
    private const DENIED_PATTERNS = [
        '/`[^`]+`/',                                          // Backtick execution
        '/\b(sudo|chmod\s+777|chown)\b/i',                   // Privilege escalation
        '/\bcurl\s.*\|\s*(bash|sh|zsh)\b/i',                 // Pipe to shell
        '/\bwget\s.*-O-?\s*\|\s*(bash|sh|zsh)\b/i',          // wget pipe to shell
        '/\bfile_put_contents\s*\(\s*[\'"][\/~]/i',           // Write to absolute paths
        '/\bunlink\s*\(\s*[\'"][\/~]/i',                      // Delete absolute paths
        '/\brmdir\s*\(\s*[\'"][\/~]/i',                       // Remove absolute dirs
        '/\brequire(_once)?\s*\(\s*[\'"][\/~]/i',             // Include from absolute paths
        '/\binclude(_once)?\s*\(\s*[\'"][\/~]/i',             // Include from absolute paths
    ];

    /**
     * Validate PHP code and return a list of issues found.
     *
     * @return string[] List of issues. Empty array means the code passed validation.
     */
    public function validate(string $code): array
    {
        $issues = [];

        // Check for denied function calls
        foreach (self::DENIED_FUNCTIONS as $func) {
            // Match function calls: func( or func (
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $code)) {
                $issues[] = "Denied function call: {$func}()";
            }
        }

        // Check for denied patterns
        foreach (self::DENIED_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                $issues[] = "Denied pattern detected: {$pattern}";
            }
        }

        return $issues;
    }

    /**
     * Check if the code is safe to execute.
     */
    public function isSafe(string $code): bool
    {
        return empty($this->validate($code));
    }
}
