<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;
use CoquiBot\Coqui\Tests\Conformance\Support\LenientSchema;
use CoquiBot\Coqui\Tests\Conformance\Support\VectorManifest;

$validator = new ConformanceValidator();

// A wire-tolerant (CORE-36) validator: the same schemas with every closed object
// relaxed to accept unknown fields. Built once; torn down after this file's tests.
$lenientSchemaDir = LenientSchema::build();
$lenientValidator = new ConformanceValidator($lenientSchemaDir);
afterAll(fn () => LenientSchema::remove($lenientSchemaDir));

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

// CORE-36 forward-tolerance: a lenient vector carries a forward-incompatible unknown
// field that STRICT validation rejects (proving it is genuinely lenient-bucketed), yet
// a wire-tolerant consumer MUST accept it. Both halves are asserted per vector.
it('rejects lenient vectors strictly yet accepts them under wire-tolerant leniency', function (array $entry) use ($validator, $lenientValidator) {
    $data = json_decode(file_get_contents($entry['file']), false, flags: JSON_THROW_ON_ERROR);

    // 1. Strict rejects — documents WHY the vector is bucketed lenient (unknown field present).
    expect($validator->isValid($entry['schema'], $data))
        ->toBeFalse($entry['file'] . ' should carry a forward-incompatible unknown field that strict '
            . $entry['schema'] . ' rejects, but strict accepted it');
    expect($validator->errorText($entry['schema'], $data))
        ->toContain('reasoning_effort');

    // 2. Wire-tolerant leniency accepts it — the schema's closed objects are relaxed.
    expect($lenientValidator->isValid($entry['schema'], $data))
        ->toBeTrue($entry['file'] . ' must validate under the leniency-relaxed schema '
            . '(forward-compatible extra fields tolerated) but did not: '
            . $lenientValidator->errorText($entry['schema'], $data));
})->with(VectorManifest::lenient());

// Teeth: relaxing additionalProperties tolerates only UNKNOWN fields — it must not disable
// required-field/structural validation. Strip a required field and the lenient validator
// must still reject the document.
it('leniency validator still enforces required fields (not a rubber stamp)', function () use ($lenientValidator) {
    $entry = array_values(VectorManifest::lenient())[0][0];
    $data = json_decode(file_get_contents($entry['file']), false, flags: JSON_THROW_ON_ERROR);
    unset($data->id); // required by persona.json

    expect($lenientValidator->isValid($entry['schema'], $data))
        ->toBeFalse('relaxing additionalProperties must not disable required-field validation');
})->group('conformance');
