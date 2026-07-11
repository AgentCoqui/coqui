<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\SseStream;

test('formats an SSE frame without an id', function (): void {
    expect(SseStream::format('activity', ['cursor' => 42]))
        ->toBe("event: activity\ndata: {\"cursor\":42}\n\n");
});

test('formats an SSE frame with an id line', function (): void {
    expect(SseStream::format('activity', ['cursor' => 42], 42))
        ->toBe("id: 42\nevent: activity\ndata: {\"cursor\":42}\n\n");
});

test('formats a connected frame with a string payload', function (): void {
    expect(SseStream::format('connected', ['loop_id' => 'lp_1']))
        ->toBe("event: connected\ndata: {\"loop_id\":\"lp_1\"}\n\n");
});

test('response is a 200 text/event-stream', function (): void {
    $response = (new SseStream())->response();
    expect($response->getStatusCode())->toBe(200);
    expect($response->getHeaderLine('Content-Type'))->toBe('text/event-stream');
});
