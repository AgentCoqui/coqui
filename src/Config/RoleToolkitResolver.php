<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\ToolkitVisibility;

/**
 * Resolves toolkit/tool visibility for a given role based on declarative frontmatter patterns.
 *
 * Parses the `toolkits` field from role frontmatter (comma-separated +/- patterns)
 * and evaluates them against toolkit class basenames, tool names, and package names.
 *
 * Pattern format: "+Pattern" (allow) or "-Pattern" (deny), comma-separated.
 * Special patterns: "+*" (allow all), "-*" (deny all).
 *
 * Evaluation: left-to-right, last match wins. Default mode determined by the
 * first wildcard rule (-* = deny-by-default, +* = allow-by-default).
 *
 * ALWAYS_ENABLED tools (tool_search, credentials) bypass all role filtering.
 */
final class RoleToolkitResolver
{
    /** @var list<array{allow: bool, pattern: string}> Ordered rules parsed from the toolkits string */
    private readonly array $rules;

    /** Whether the default (no matching rule) is to allow or deny */
    private readonly bool $defaultAllow;

    public function __construct(?string $toolkitsPattern)
    {
        if ($toolkitsPattern === null || trim($toolkitsPattern) === '') {
            $this->rules = [];
            $this->defaultAllow = true;
            return;
        }

        $rules = [];
        $defaultAllow = true;
        $tokens = array_map('trim', explode(',', $toolkitsPattern));

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $allow = true;
            if (str_starts_with($token, '+')) {
                $allow = true;
                $token = substr($token, 1);
            } elseif (str_starts_with($token, '-')) {
                $allow = false;
                $token = substr($token, 1);
            }

            if ($token === '') {
                continue;
            }

            // First wildcard determines default mode
            if ($token === '*' && $rules === []) {
                $defaultAllow = $allow;
            }

            $rules[] = ['allow' => $allow, 'pattern' => $token];
        }

        $this->rules = $rules;
        $this->defaultAllow = $defaultAllow;
    }

    /**
     * Check if a toolkit class is allowed for the current role.
     *
     * Matches against the class basename (e.g., "FilesystemToolkit")
     * and optionally against a Composer package name.
     */
    public function isToolkitAllowed(string $toolkitClass, ?string $packageName = null): bool
    {
        if ($this->rules === []) {
            return true;
        }

        $basename = $this->classBasename($toolkitClass);

        return $this->evaluate($basename, $packageName);
    }

    /**
     * Check if an individual tool is allowed for the current role.
     *
     * ALWAYS_ENABLED tools bypass all role filtering.
     */
    public function isToolAllowed(string $toolName): bool
    {
        if (ToolkitVisibility::isAlwaysEnabled($toolName)) {
            return true;
        }

        if ($this->rules === []) {
            return true;
        }

        return $this->evaluate($toolName, null);
    }

    /**
     * Combine role policy with global visibility.
     *
     * If the role denies the identifier, returns Disabled regardless of global visibility.
     * If the role allows, defers to the global visibility setting.
     */
    public function getEffectiveVisibility(
        string $identifier,
        ToolkitVisibility $globalVisibility,
        ?string $packageName = null,
    ): ToolkitVisibility {
        if (ToolkitVisibility::isAlwaysEnabled($identifier)) {
            return ToolkitVisibility::Enabled;
        }

        if (!$this->evaluate($identifier, $packageName)) {
            return ToolkitVisibility::Disabled;
        }

        return $globalVisibility;
    }

    /**
     * Whether this resolver has any rules (i.e., the role has a toolkits field).
     */
    public function hasRules(): bool
    {
        return $this->rules !== [];
    }

    /**
     * Evaluate an identifier against the rule set. Last match wins.
     */
    private function evaluate(string $identifier, ?string $packageName): bool
    {
        $result = $this->defaultAllow;

        foreach ($this->rules as $rule) {
            if ($this->matches($rule['pattern'], $identifier, $packageName)) {
                $result = $rule['allow'];
            }
        }

        return $result;
    }

    /**
     * Check if a pattern matches the given identifier or package name.
     */
    private function matches(string $pattern, string $identifier, ?string $packageName): bool
    {
        if ($pattern === '*') {
            return true;
        }

        // Exact match against identifier (toolkit basename or tool name)
        if (strcasecmp($pattern, $identifier) === 0) {
            return true;
        }

        // Match against package name if provided
        if ($packageName !== null && strcasecmp($pattern, $packageName) === 0) {
            return true;
        }

        return false;
    }

    private function classBasename(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
