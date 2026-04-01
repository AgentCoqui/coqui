<?php

declare(strict_types=1);

use CoquiBot\Coqui\Provider\ReactHttpChunk;

test('defaults: not first, not last, not timeout, no error', function () {
    $chunk = new ReactHttpChunk('hello');

    expect($chunk->isFirst())->toBeFalse();
    expect($chunk->isLast())->toBeFalse();
    expect($chunk->isTimeout())->toBeFalse();
    expect($chunk->getError())->toBeNull();
    expect($chunk->getOffset())->toBe(0);
});

test('getContent returns provided content', function () {
    $chunk = new ReactHttpChunk('test data');

    expect($chunk->getContent())->toBe('test data');
});

test('isFirst returns constructor value', function () {
    $chunk = new ReactHttpChunk('', isFirst: true);

    expect($chunk->isFirst())->toBeTrue();
});

test('isLast returns constructor value', function () {
    $chunk = new ReactHttpChunk('', isLast: true);

    expect($chunk->isLast())->toBeTrue();
});

test('getOffset returns provided offset', function () {
    $chunk = new ReactHttpChunk('data', offset: 42);

    expect($chunk->getOffset())->toBe(42);
});

test('getError returns null by default', function () {
    $chunk = new ReactHttpChunk('data');

    expect($chunk->getError())->toBeNull();
});

test('getError returns provided error message', function () {
    $chunk = new ReactHttpChunk('', error: 'Connection reset');

    expect($chunk->getError())->toBe('Connection reset');
});

test('isTimeout always returns false', function () {
    $chunk = new ReactHttpChunk('', isFirst: true, isLast: true, error: 'timeout');

    expect($chunk->isTimeout())->toBeFalse();
});

test('getInformationalStatus always returns null', function () {
    $chunk = new ReactHttpChunk('data');

    expect($chunk->getInformationalStatus())->toBeNull();
});

test('implements ChunkInterface', function () {
    $chunk = new ReactHttpChunk('');

    expect($chunk)->toBeInstanceOf(\Symfony\Contracts\HttpClient\ChunkInterface::class);
});
