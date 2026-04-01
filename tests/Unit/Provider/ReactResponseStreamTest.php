<?php

declare(strict_types=1);

use CoquiBot\Coqui\Provider\ReactHttpChunk;
use CoquiBot\Coqui\Provider\ReactResponseStream;
use React\Stream\ThroughStream;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stub ResponseInterface for use as the key() return value.
 */
function makeStubResponse(): ResponseInterface
{
    return new class implements ResponseInterface {
        public function getStatusCode(): int { return 200; }
        public function getHeaders(bool $throw = true): array { return []; }
        public function getContent(bool $throw = true): string { return ''; }
        public function toArray(bool $throw = true): array { return []; }
        public function cancel(): void {}
        public function getInfo(?string $type = null): mixed { return null; }
    };
}

/**
 * Build a ReactResponseStream with a ThroughStream that has pre-queued data.
 *
 * Data is emitted BEFORE iteration begins so chunks land in the internal
 * queue — pullChunk() returns immediately without needing await()/Fiber.
 */
function makeStreamWithData(array $chunks, bool $endAfter = true, ?string $errorMessage = null): array
{
    $stream = new ThroughStream();
    $response = makeStubResponse();
    $iter = new ReactResponseStream($stream, $response);

    // Pre-queue data — listeners are attached in the constructor
    foreach ($chunks as $chunk) {
        $stream->write($chunk);
    }

    if ($errorMessage !== null) {
        $stream->emit('error', [new \RuntimeException($errorMessage)]);
    } elseif ($endAfter) {
        $stream->end();
    }

    return [$iter, $response];
}

test('implements ResponseStreamInterface', function () {
    [$iter] = makeStreamWithData(['hello']);

    expect($iter)->toBeInstanceOf(\Symfony\Contracts\HttpClient\ResponseStreamInterface::class);
});

test('rewind() returns first chunk with isFirst=true', function () {
    [$iter] = makeStreamWithData(['hello']);

    $iter->rewind();
    $chunk = $iter->current();

    expect($chunk)->toBeInstanceOf(ReactHttpChunk::class);
    expect($chunk->isFirst())->toBeTrue();
    expect($chunk->getContent())->toBe('hello');
});

test('next()/current() returns subsequent data chunks', function () {
    [$iter] = makeStreamWithData(['chunk1', 'chunk2']);

    $iter->rewind();
    $first = $iter->current();
    expect($first->getContent())->toBe('chunk1');
    expect($first->isFirst())->toBeTrue();

    $iter->next();
    $second = $iter->current();
    expect($second->getContent())->toBe('chunk2');
    expect($second->isFirst())->toBeFalse();
});

test('end event produces isLast=true chunk', function () {
    [$iter] = makeStreamWithData(['data']);

    $iter->rewind(); // 'data' chunk (isFirst)
    $iter->next();   // isLast chunk

    $last = $iter->current();
    expect($last->isLast())->toBeTrue();
    expect($last->getContent())->toBe('');
});

test('error event produces isLast=true chunk with error message', function () {
    [$iter] = makeStreamWithData([], endAfter: false, errorMessage: 'Connection reset');

    $iter->rewind();
    $chunk = $iter->current();

    expect($chunk->isLast())->toBeTrue();
    expect($chunk->getError())->toBe('Connection reset');
});

test('key() returns the response', function () {
    [$iter, $response] = makeStreamWithData(['data']);

    $iter->rewind();

    expect($iter->key())->toBe($response);
});

test('valid() returns true for data chunks', function () {
    [$iter] = makeStreamWithData(['hello']);

    $iter->rewind();

    expect($iter->valid())->toBeTrue();
});

test('valid() returns false after last chunk', function () {
    [$iter] = makeStreamWithData(['hello']);

    $iter->rewind(); // data chunk
    expect($iter->valid())->toBeTrue();

    $iter->next(); // last chunk (isLast=true)
    expect($iter->valid())->toBeFalse();
});

test('offset increments across chunks', function () {
    [$iter] = makeStreamWithData(['hello', 'world']);

    $iter->rewind();
    expect($iter->current()->getOffset())->toBe(0);

    $iter->next();
    expect($iter->current()->getOffset())->toBe(5); // strlen('hello')
});

test('empty stream produces only last chunk', function () {
    [$iter] = makeStreamWithData([]);

    $iter->rewind();
    $chunk = $iter->current();

    expect($chunk->isLast())->toBeTrue();
    expect($chunk->getContent())->toBe('');
});

test('full iteration collects all content', function () {
    [$iter] = makeStreamWithData(['Hello, ', 'world!']);

    $content = '';
    for ($iter->rewind(); $iter->valid(); $iter->next()) {
        $content .= $iter->current()->getContent();
    }

    expect($content)->toBe('Hello, world!');
});
