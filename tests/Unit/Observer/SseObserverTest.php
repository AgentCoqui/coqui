<?php

declare(strict_types=1);

use CoquiBot\Coqui\Observer\SseObserver;
use CoquiBot\Coqui\Tests\Unit\Observer\TestWritableStream;

// --- agent.reasoning ---

test('agent.reasoning emits SSE reasoning event with content', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.reasoning', 'I am thinking...');

    expect($stream->buffer)->toContain('event: reasoning');
    expect($stream->buffer)->toContain('"content":"I am thinking..."');
    expect($stream->buffer)->toEndWith("\n\n");
});

test('agent.reasoning SSE format is correct', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.reasoning', 'token');

    // Full SSE format: event: <type>\ndata: <json>\n\n
    expect($stream->buffer)->toBe("event: reasoning\ndata: {\"content\":\"token\"}\n\n");
});

test('agent.reasoning with non-string data emits empty content', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.reasoning', 42);

    expect($stream->buffer)->toContain('event: reasoning');
    expect($stream->buffer)->toContain('"content":""');
});

test('multiple reasoning events each emit a separate SSE event', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.reasoning', 'chunk A');
    $observer->handleEvent('agent.reasoning', 'chunk B');

    expect(substr_count($stream->buffer, 'event: reasoning'))->toBe(2);
    expect($stream->buffer)->toContain('"content":"chunk A"');
    expect($stream->buffer)->toContain('"content":"chunk B"');
});

// --- other events still work ---

test('agent.start emits agent_start SSE event', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.start', null);

    expect($stream->buffer)->toContain('event: agent_start');
});

test('agent.text_delta emits text_delta SSE event with content', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.text_delta', 'Hello');

    expect($stream->buffer)->toContain('event: text_delta');
    expect($stream->buffer)->toContain('"content":"Hello"');
});

test('agent.iteration emits iteration SSE event with number', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.iteration', 3);

    expect($stream->buffer)->toContain('event: iteration');
    expect($stream->buffer)->toContain('"number":3');
});

test('unknown event is silently ignored', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('some.unknown.event', 'data');

    expect($stream->buffer)->toBe('');
});

test('stream not writable prevents output', function () {
    $stream = new TestWritableStream(writable: false);
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.reasoning', 'thinking');

    expect($stream->buffer)->toBe('');
});

test('agent.error emits error SSE event with message', function () {
    $stream = new TestWritableStream();
    $observer = new SseObserver($stream);

    $observer->handleEvent('agent.error', 'Something went wrong');

    expect($stream->buffer)->toContain('event: error');
    expect($stream->buffer)->toContain('"message":"Something went wrong"');
});
