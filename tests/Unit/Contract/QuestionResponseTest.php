<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;

function makeRequest(
    QuestionFormat $format,
    array $optionLabels = [],
    bool $allowOther = false,
    ?QuestionResponse $suggested = null,
): QuestionRequest {
    $options = array_map(fn(string $l) => new QuestionOption($l), $optionLabels);
    $suggested ??= match ($format) {
        QuestionFormat::SingleSelect => new QuestionResponse([$optionLabels[0]]),
        QuestionFormat::MultiSelect => new QuestionResponse([]),
        QuestionFormat::FreeText => new QuestionResponse([], 'default'),
    };

    return new QuestionRequest(
        id: 'q1',
        prompt: 'Pick',
        format: $format,
        options: $options,
        allowOther: $allowOther,
        suggested: $suggested,
    );
}

test('single-select accepts exactly one known label', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b', 'c']);
    expect((new QuestionResponse(['b']))->isValidFor($q))->toBeTrue();
});

test('single-select rejects unknown label', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b']);
    expect((new QuestionResponse(['z']))->isValidFor($q))->toBeFalse();
});

test('single-select rejects more than one label', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b']);
    expect((new QuestionResponse(['a', 'b']))->isValidFor($q))->toBeFalse();
});

test('single-select rejects zero labels without other', function () {
    $q = makeRequest(QuestionFormat::SingleSelect, ['a', 'b']);
    expect((new QuestionResponse([]))->isValidFor($q))->toBeFalse();
});

test('single-select accepts Other text only when allowOther', function () {
    $with = makeRequest(QuestionFormat::SingleSelect, ['a', 'b'], allowOther: true);
    $without = makeRequest(QuestionFormat::SingleSelect, ['a', 'b'], allowOther: false);
    expect((new QuestionResponse([], 'custom'))->isValidFor($with))->toBeTrue();
    expect((new QuestionResponse([], 'custom'))->isValidFor($without))->toBeFalse();
});

test('multi-select accepts zero-or-more known labels', function () {
    $q = makeRequest(QuestionFormat::MultiSelect, ['a', 'b', 'c']);
    expect((new QuestionResponse([]))->isValidFor($q))->toBeTrue();
    expect((new QuestionResponse(['a', 'c']))->isValidFor($q))->toBeTrue();
});

test('multi-select rejects any unknown label', function () {
    $q = makeRequest(QuestionFormat::MultiSelect, ['a', 'b']);
    expect((new QuestionResponse(['a', 'z']))->isValidFor($q))->toBeFalse();
});

test('multi-select allows text only with allowOther', function () {
    $with = makeRequest(QuestionFormat::MultiSelect, ['a'], allowOther: true);
    $without = makeRequest(QuestionFormat::MultiSelect, ['a'], allowOther: false);
    expect((new QuestionResponse(['a'], 'extra'))->isValidFor($with))->toBeTrue();
    expect((new QuestionResponse(['a'], 'extra'))->isValidFor($without))->toBeFalse();
});

test('free-text requires non-empty text and empty selected', function () {
    $q = makeRequest(QuestionFormat::FreeText);
    expect((new QuestionResponse([], 'hello'))->isValidFor($q))->toBeTrue();
    expect((new QuestionResponse([], null))->isValidFor($q))->toBeFalse();
    expect((new QuestionResponse([], ''))->isValidFor($q))->toBeFalse();
    expect((new QuestionResponse(['a'], 'hello'))->isValidFor($q))->toBeFalse();
});

test('response round-trips through array', function () {
    $r = new QuestionResponse(['a', 'b'], 'note');
    expect(QuestionResponse::fromArray($r->toArray()))->toEqual($r);
});
