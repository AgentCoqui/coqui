<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance\Support;

/**
 * Reads the vendored conformance vector manifest and yields the valid/invalid
 * buckets as Pest datasets. Mirrors validate-vectors.mjs (lenient bucket excluded).
 */
final class VectorManifest
{
    private const SPEC_ROOT = __DIR__ . '/../spec';

    /** @return array<string, array{0: array{file: string, schema: string}}> */
    public static function valid(): array
    {
        return self::bucket('valid');
    }

    /** @return array<string, array{0: array{file: string, schema: string}}> */
    public static function invalid(): array
    {
        return self::bucket('invalid');
    }

    /** @return array<string, array{0: array{file: string, schema: string}}> */
    private static function bucket(string $name): array
    {
        $manifest = json_decode(
            file_get_contents(self::SPEC_ROOT . '/conformance/vectors/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $rows = [];
        foreach ($manifest[$name] as $entry) {
            $schemaName = basename($entry['schema']); // strip the $id URL to the file name
            $rows[$entry['file']] = [[
                'file' => self::SPEC_ROOT . '/' . $entry['file'],
                'schema' => $schemaName,
            ]];
        }

        return $rows;
    }
}
