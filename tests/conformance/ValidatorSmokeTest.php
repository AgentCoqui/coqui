<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

$vector = fn (string $rel): object => json_decode(
    file_get_contents(__DIR__ . '/spec/' . $rel),
    false,
    flags: JSON_THROW_ON_ERROR
);

it('accepts a minimal valid persona (cross-file $ref resolves)', function () use ($vector) {
    $v = new ConformanceValidator();
    expect($v->isValid('persona.json', $vector('conformance/vectors/valid/persona.min.json')))
        ->toBeTrue();
});

it('rejects a persona whose allowed_roles omit orchestrator', function () use ($vector) {
    $v = new ConformanceValidator();
    expect($v->isValid('persona.json', $vector('conformance/vectors/invalid/persona.no-orchestrator.json')))
        ->toBeFalse();
});

it('reports a non-empty error string for an invalid object', function () use ($vector) {
    $v = new ConformanceValidator();
    $err = $v->errorText('persona.json', $vector('conformance/vectors/invalid/persona.no-orchestrator.json'));
    expect($err)->not->toBe('');
});
