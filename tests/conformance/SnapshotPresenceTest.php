<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

$specRoot = __DIR__ . '/spec';

it('vendored the schema directory', function () use ($specRoot) {
    expect(is_file($specRoot . '/schema/persona.json'))->toBeTrue();
    expect(is_file($specRoot . '/schema/common.json'))->toBeTrue();
});

it('vendored the vector manifest and its referenced seed loops', function () use ($specRoot) {
    expect(is_file($specRoot . '/conformance/vectors/manifest.json'))->toBeTrue();
    expect(is_file($specRoot . '/seeds/loops/reflection.json'))->toBeTrue();
});

it('every manifest-referenced file exists in the snapshot', function () use ($specRoot) {
    $manifest = json_decode(
        file_get_contents($specRoot . '/conformance/vectors/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $missing = [];
    foreach (['valid', 'invalid', 'lenient'] as $bucket) {
        foreach ($manifest[$bucket] as $entry) {
            if (!is_file($specRoot . '/' . $entry['file'])) {
                $missing[] = $entry['file'];
            }
        }
    }
    expect($missing)->toBe([]);
});

it('recorded a snapshot stamp', function () use ($specRoot) {
    expect(is_file($specRoot . '/SNAPSHOT.txt'))->toBeTrue();
});
