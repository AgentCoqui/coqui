<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;
use CoquiBot\Coqui\Tests\Conformance\Support\VectorManifest;

$validator = new ConformanceValidator();

it('accepts every valid golden vector', function (array $entry) use ($validator) {
    $data = json_decode(file_get_contents($entry['file']), false, flags: JSON_THROW_ON_ERROR);
    expect($validator->isValid($entry['schema'], $data))
        ->toBeTrue($entry['file'] . ' should validate against ' . $entry['schema']
            . ' but did not: ' . $validator->errorText($entry['schema'], $data));
})->with(VectorManifest::valid());

it('rejects every invalid golden vector', function (array $entry) use ($validator) {
    $data = json_decode(file_get_contents($entry['file']), false, flags: JSON_THROW_ON_ERROR);
    expect($validator->isValid($entry['schema'], $data))
        ->toBeFalse($entry['file'] . ' should be rejected by ' . $entry['schema'] . ' but was accepted');
})->with(VectorManifest::invalid());
