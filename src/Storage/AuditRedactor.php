<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Contract\AuditRedactorInterface;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;

/**
 * Redacts secrets out of audit-log payloads before they are persisted.
 *
 * Three layers, applied in order over the whole argument tree and over the
 * free-text `reason`:
 *
 *   L1 — exact occurrences of resolved credential VALUES (most precise)
 *   L2 — values sitting under a sensitive KEY name (structured secrets)
 *   L3 — high-confidence value PATTERNS (free-text embeds the first two miss)
 *
 * Credential values are never held as state: names are collected per call and
 * values resolved through the resolver, which re-reads the workspace .env on
 * every lookup for hot-reload.
 */
final class AuditRedactor implements AuditRedactorInterface
{
    public const string PLACEHOLDER = '[REDACTED]';

    /** Key names whose value is always redacted, matched case-insensitively as a substring. */
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'credential',
        'private_key',
        'privatekey',
    ];

    /** High-confidence secret shapes. Each must match the secret itself, not surrounding text. */
    private const array VALUE_PATTERNS = [
        '/Bearer\s+[A-Za-z0-9._\-]{8,}/i',
        '/\bsk-[A-Za-z0-9._\-]{8,}/',
        '/\bghp_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/\bxox[bpsar]-[A-Za-z0-9\-]{10,}/',
        '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
    ];

    /**
     * @param \Closure(): array<int, string>|null $toolkitCredentialNames Lazy provider for
     *        toolkit-declared credential names. Invoked per redaction and guarded, because
     *        ToolkitDiscovery is initialized AFTER SessionStorage during boot.
     * @param array<int, string> $extraNames Additional credential names (core, provider, api.key).
     */
    public function __construct(
        private readonly ?CredentialResolverInterface $credentials = null,
        private readonly ?\Closure $toolkitCredentialNames = null,
        private readonly array $extraNames = [],
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function redact(array $arguments): array
    {
        $values = $this->secretValues();

        /** @var array<string, mixed> $result */
        $result = $this->redactNode($arguments, $values, false);

        return $result;
    }

    public function redactScalar(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->redactString($value, $this->secretValues());
    }

    /**
     * Walk any node. $underSensitiveKey short-circuits to a full replacement (L2).
     *
     * @param array<int, string> $values
     */
    private function redactNode(mixed $node, array $values, bool $underSensitiveKey): mixed
    {
        if ($underSensitiveKey && $node !== null) {
            return self::PLACEHOLDER;
        }

        if (is_array($node)) {
            $out = [];
            foreach ($node as $key => $child) {
                $sensitive = is_string($key) && $this->isSensitiveKey($key);
                $value = $this->redactNode($child, $values, $sensitive);

                // Keys carry secrets too (env maps are keyed BY the variable value in
                // some payloads). Redact the key with the same string layers, then make
                // sure two keys collapsing to the same placeholder do not drop a row.
                $outKey = is_string($key) ? $this->uniqueKey($out, $this->redactString($key, $values)) : $key;

                $out[$outKey] = $value;
            }

            return $out;
        }

        if (is_object($node)) {
            $encoded = json_decode(json_encode($node) ?: '{}', true);

            return is_array($encoded)
                ? $this->redactNode($encoded, $values, false)
                : self::PLACEHOLDER;
        }

        if (is_string($node)) {
            return $this->redactString($node, $values);
        }

        return $node;
    }

    /**
     * Disambiguate a redacted key that already exists, so no entry is silently lost.
     *
     * @param array<array-key, mixed> $out
     */
    private function uniqueKey(array $out, string $key): string
    {
        if (!array_key_exists($key, $out)) {
            return $key;
        }

        $suffix = 2;
        while (array_key_exists($key . '#' . $suffix, $out)) {
            $suffix++;
        }

        return $key . '#' . $suffix;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $values
     */
    private function redactString(string $text, array $values): string
    {
        // L1 — exact known values first, so the placeholder survives L3.
        foreach ($values as $secret) {
            if ($secret !== '' && str_contains($text, $secret)) {
                $text = str_replace($secret, self::PLACEHOLDER, $text);
            }
        }

        // L3 — pattern backstop.
        foreach (self::VALUE_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::PLACEHOLDER, $text);

            // A PCRE failure (backtrack/recursion limit on a large input) returns null.
            // We cannot know what the pattern would have matched, so over-redact the
            // whole string rather than persisting text that may still hold the secret.
            if ($replaced === null) {
                return self::PLACEHOLDER;
            }

            $text = $replaced;
        }

        return $text;
    }

    /**
     * Resolve every candidate credential name to its current value.
     *
     * @return array<int, string>
     */
    private function secretValues(): array
    {
        if ($this->credentials === null) {
            return [];
        }

        // Deliberately unguarded: if the resolver cannot enumerate its keys, L1 has no
        // name set and would silently emit output indistinguishable from a healthy
        // redaction. Propagate instead — SessionStorage::logAudit() catches this and
        // persists a `redaction-failed` placeholder in place of the arguments.
        $names = [...$this->extraNames, ...$this->credentials->keys()];

        if ($this->toolkitCredentialNames !== null) {
            try {
                $names = [...$names, ...($this->toolkitCredentialNames)()];
            } catch (\Throwable) {
                // Unlike keys() above, this one is swallowed on purpose: ToolkitDiscovery
                // is initialized AFTER SessionStorage, so a throw here is the expected
                // state during early boot, not a signal that a secret went unredacted.
                // Failing closed would break every audit write during startup. The
                // toolkit names are additive on top of the resolver's own key set.
            }
        }

        $values = [];
        foreach (array_unique($names) as $name) {
            // Also unguarded: a throwing get() means this credential's value is unknown
            // and therefore cannot be matched, so skipping it would leak that secret.
            $value = $this->credentials->get($name);

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        // Longest first, so a secret that contains another is redacted whole.
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $values;
    }
}
