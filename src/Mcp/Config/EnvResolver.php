<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Config;

/**
 * Resolves ${VAR_NAME} placeholders in environment variable values.
 *
 * Used when building the subprocess environment for an MCP server.
 * Placeholders reference credentials stored in .env via Coqui's
 * CredentialResolver (putenv() hot-reload makes them immediately
 * available via getenv()).
 *
 * Example:
 *   Input:  ["GITHUB_TOKEN" => "${GITHUB_TOKEN}", "FOO" => "literal"]
 *   Output: ["GITHUB_TOKEN" => "ghp_abc123...",    "FOO" => "literal"]
 */
final class EnvResolver
{
    private const string PLACEHOLDER_PATTERN = '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /**
     * Resolve all ${VAR} placeholders in an env map.
     *
     * Placeholders that reference undefined variables are replaced
     * with an empty string and tracked in the returned unresolved list.
     *
     * @param array<string, string> $env Raw env map from config
     *
     * @return array{resolved: array<string, string>, unresolved: list<string>}
     */
    public function resolve(array $env): array
    {
        $resolved = [];
        $unresolved = [];

        foreach ($env as $key => $value) {
            $resolved[$key] = (string) preg_replace_callback(
                self::PLACEHOLDER_PATTERN,
                static function (array $matches) use (&$unresolved): string {
                    $varName = $matches[1];
                    $envValue = getenv($varName);

                    if ($envValue === false || $envValue === '') {
                        $unresolved[] = $varName;

                        return '';
                    }

                    return $envValue;
                },
                $value,
            );
        }

        return [
            'resolved' => $resolved,
            'unresolved' => array_values(array_unique($unresolved)),
        ];
    }

    /**
     * Check which placeholders in an env map are missing from the environment.
     *
     * @param array<string, string> $env
     *
     * @return list<string> Variable names that are not set
     */
    public function findMissing(array $env): array
    {
        $missing = [];

        foreach ($env as $value) {
            if (preg_match_all(self::PLACEHOLDER_PATTERN, $value, $matches)) {
                foreach ($matches[1] as $varName) {
                    $envValue = getenv($varName);

                    if ($envValue === false || $envValue === '') {
                        $missing[] = $varName;
                    }
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
