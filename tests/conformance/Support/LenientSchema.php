<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance\Support;

use FilesystemIterator;

/**
 * Builds a wire-tolerant (CORE-36) copy of the vendored CAP 0.5.0 schema dir.
 *
 * A leniency-relaxed consumer MUST NOT reject unknown fields, so every closed
 * object (`additionalProperties: false`) is rewritten to accept extras
 * (`additionalProperties: true`). Every other constraint — `required`, types,
 * enums, `$ref` targets — is preserved, so the relaxed validator still enforces
 * structure; it only stops rejecting forward-compatible extra fields.
 *
 * The copy is written to a fresh system-temp dir OUTSIDE the repo (the vendored
 * spec under tests/conformance/spec is only ever read). Copying the whole flat
 * schema dir keeps sibling files (common.json, ...) present so relative `$ref`
 * resolution stays intact.
 */
final class LenientSchema
{
    private const SCHEMA_DIR = __DIR__ . '/../spec/schema';

    /** Creates the relaxed schema dir and returns its path. Caller must remove() it. */
    public static function build(): string
    {
        $target = sys_get_temp_dir() . '/coqui-lenient-schema-' . bin2hex(random_bytes(8));

        if (!mkdir($target, 0700, true) && !is_dir($target)) {
            throw new \RuntimeException("Unable to create lenient schema dir: {$target}");
        }

        foreach (glob(self::SCHEMA_DIR . '/*.json') ?: [] as $file) {
            $schema = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            file_put_contents(
                $target . '/' . basename($file),
                json_encode(self::relax($schema), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return $target;
    }

    /** Deletes the relaxed schema dir. */
    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
            @unlink((string) $entry);
        }

        @rmdir($dir);
    }

    /**
     * Recursively rewrites every `"additionalProperties": false` to `true`.
     * Object-valued or already-`true` `additionalProperties` are left untouched.
     *
     * @param mixed $node
     * @return mixed
     */
    private static function relax(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key === 'additionalProperties' && $value === false) {
                $node[$key] = true;
                continue;
            }

            $node[$key] = self::relax($value);
        }

        return $node;
    }
}
