<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;

test('request round-trips through array', function () {
    $q = new QuestionRequest(
        id: 'q1',
        prompt: 'Which fruit?',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('apple', 'a fruit'), new QuestionOption('pear')],
        allowOther: true,
        suggested: new QuestionResponse(['apple']),
        header: 'Fruit',
    );

    $restored = QuestionRequest::fromArray($q->toArray());

    expect($restored->id)->toBe('q1');
    expect($restored->format)->toBe(QuestionFormat::SingleSelect);
    expect($restored->optionLabels())->toBe(['apple', 'pear']);
    expect($restored->allowOther)->toBeTrue();
    expect($restored->suggested->selected)->toBe(['apple']);
    expect($restored->header)->toBe('Fruit');
});

test('request rejects a suggested answer that is invalid for it', function () {
    expect(fn() => new QuestionRequest(
        id: 'q1',
        prompt: 'Pick',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('a')],
        allowOther: false,
        suggested: new QuestionResponse(['nonexistent']),
    ))->toThrow(InvalidArgumentException::class);
});

test('select request rejects empty options', function () {
    expect(fn() => new QuestionRequest(
        id: 'q1',
        prompt: 'Pick',
        format: QuestionFormat::SingleSelect,
        options: [],
        allowOther: false,
        suggested: new QuestionResponse([], 'x'),
    ))->toThrow(InvalidArgumentException::class);
});
