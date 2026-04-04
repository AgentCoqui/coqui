<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Best-effort static analysis of generated PHP code before execution.
 *
 * This is defense-in-depth — the interactive approval prompt is the primary
 * security gate. The sanitizer catches obvious dangerous patterns that the
 * LLM might produce (accidentally or via prompt injection).
 *
 * In unsafe mode, the standard denied functions/patterns are skipped,
 * but catastrophic patterns (rm -rf /, fork bombs, etc.) are always enforced.
 */
final class ScriptSanitizer
{
    /**
     * Function calls that are never allowed in generated scripts (safe mode only).
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
        // Filesystem write functions — prevent writes outside workspace
        'file_put_contents',
        'fopen',
        'fwrite',
        'fputs',
        'mkdir',
        'rmdir',
        'unlink',
        'rename',
        'copy',
        'touch',
        'chmod',
        'chown',
        'chgrp',
        'symlink',
        'link',
        'tempnam',
        // SPL and stream write functions — defense-in-depth
        'fputcsv',
    ];

    /**
     * Regex patterns that indicate dangerous constructs (safe mode only).
     *
     * @var string[]
     */
    private const DENIED_PATTERNS = [
        '/`[^`]+`/',                                          // Backtick execution
        '/\b(sudo|chmod\s+777|chown)\b/i',                   // Privilege escalation
        '/\bcurl\s.*\|\s*(bash|sh|zsh)\b/i',                 // Pipe to shell
        '/\bwget\s.*-O-?\s*\|\s*(bash|sh|zsh)\b/i',          // wget pipe to shell
        '/\brequire(_once)?\s*\(\s*[\'"][\/~]/i',             // Include from absolute paths
        '/\binclude(_once)?\s*\(\s*[\'"][\/~]/i',             // Include from absolute paths
    ];

    public function __construct(
        private readonly bool $unsafe = false,
        private readonly ?CatastrophicBlacklist $blacklist = null,
    ) {}

    /**
     * Validate PHP code and return a list of issues found.
     *
     * In safe mode: checks denied functions, denied patterns, and catastrophic patterns.
     * In unsafe mode: only checks catastrophic patterns.
     *
     * @return string[] List of issues. Empty array means the code passed validation.
     */
    public function validate(string $code): array
    {
        $issues = [];

        // Catastrophic patterns are ALWAYS checked regardless of mode
        if ($this->blacklist !== null) {
            $match = $this->blacklist->matches($code);
            if ($match !== null) {
                $issues[] = $match;
            }
        }

        // In unsafe mode, skip standard denied functions and patterns
        if ($this->unsafe) {
            return $issues;
        }

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

    /**
     * Whether this sanitizer is running in unsafe mode.
     */
    public function isUnsafe(): bool
    {
        return $this->unsafe;
    }
}
