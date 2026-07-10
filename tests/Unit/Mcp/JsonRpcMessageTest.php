<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\JsonRpc\JsonRpcError;
use CoquiBot\Coqui\Mcp\JsonRpc\Message;

// -- Factory Methods --

test('request creates a message with id and method', function () {
    $msg = Message::request(1, 'tools/list', ['cursor' => 'abc']);

    expect($msg->id)->toBe(1)
        ->and($msg->method)->toBe('tools/list')
        ->and($msg->params)->toBe(['cursor' => 'abc'])
        ->and($msg->result)->toBeNull()
        ->and($msg->error)->toBeNull();
});

test('request with empty params', function () {
    $msg = Message::request(42, 'initialize');

    expect($msg->id)->toBe(42)
        ->and($msg->method)->toBe('initialize')
        ->and($msg->params)->toBe([]);
});

test('notification creates a message without id', function () {
    $msg = Message::notification('notifications/initialized');

    expect($msg->id)->toBeNull()
        ->and($msg->method)->toBe('notifications/initialized')
        ->and($msg->params)->toBe([]);
});

test('notification with params', function () {
    $msg = Message::notification('progress', ['token' => 'abc', 'value' => 50]);

    expect($msg->id)->toBeNull()
        ->and($msg->params)->toBe(['token' => 'abc', 'value' => 50]);
});

// -- Type Checks --

test('isRequest returns true for request messages', function () {
    $msg = Message::request(1, 'test');

    expect($msg->isRequest())->toBeTrue()
        ->and($msg->isNotification())->toBeFalse();
});

test('isNotification returns true for notification messages', function () {
    $msg = Message::notification('test');

    expect($msg->isNotification())->toBeTrue()
        ->and($msg->isRequest())->toBeFalse();
});

test('isResponse identifies success response from JSON', function () {
    $json = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => ['tools' => []],
    ]);

    $msg = Message::fromJson($json);

    expect($msg->isResponse())->toBeTrue()
        ->and($msg->isError())->toBeFalse()
        ->and($msg->result)->toBe(['tools' => []]);
});

test('isError identifies error response from JSON', function () {
    $json = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => [
            'code' => -32601,
            'message' => 'Method not found',
        ],
    ]);

    $msg = Message::fromJson($json);

    expect($msg->isError())->toBeTrue()
        ->and($msg->isResponse())->toBeFalse()
        ->and($msg->error)->toBeInstanceOf(JsonRpcError::class)
        ->and($msg->error->code)->toBe(-32601)
        ->and($msg->error->message)->toBe('Method not found')
        ->and($msg->error->data)->toBeNull();
});

test('error response with data field', function () {
    $json = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => [
            'code' => -32042,
            'message' => 'URL elicitation required',
            'data' => ['url' => 'https://auth.example.com'],
        ],
    ]);

    $msg = Message::fromJson($json);

    expect($msg->error->data)->toBe(['url' => 'https://auth.example.com']);
});

// -- Serialization --

test('toJson produces valid JSON-RPC request', function () {
    $msg = Message::request(1, 'initialize', ['protocolVersion' => '2025-06-18']);
    $json = $msg->toJson();
    $decoded = json_decode($json, true);

    expect($decoded['jsonrpc'])->toBe('2.0')
        ->and($decoded['id'])->toBe(1)
        ->and($decoded['method'])->toBe('initialize')
        ->and($decoded['params']['protocolVersion'])->toBe('2025-06-18')
        ->and($decoded)->not->toHaveKey('result')
        ->and($decoded)->not->toHaveKey('error');
});

test('toJson produces valid JSON-RPC notification', function () {
    $msg = Message::notification('notifications/initialized');
    $json = $msg->toJson();
    $decoded = json_decode($json, true);

    expect($decoded['jsonrpc'])->toBe('2.0')
        ->and($decoded['method'])->toBe('notifications/initialized')
        ->and($decoded['params'])->toBe([])
        ->and($decoded)->not->toHaveKey('id');
});

test('toJson encodes empty params as an object on the wire', function () {
    $request = Message::request(42, 'tools/list');
    $notification = Message::notification('notifications/initialized');

    expect($request->toJson())->toContain('"params":{}')
        ->and($notification->toJson())->toContain('"params":{}');
});

test('toJson round-trips through fromJson for requests', function () {
    $original = Message::request(5, 'tools/call', ['name' => 'test', 'arguments' => ['x' => 1]]);
    $json = $original->toJson();
    $parsed = Message::fromJson($json);

    expect($parsed->id)->toBe(5)
        ->and($parsed->method)->toBe('tools/call')
        ->and($parsed->params)->toBe(['name' => 'test', 'arguments' => ['x' => 1]]);
});

// -- Parsing --

test('fromJson throws on invalid JSON', function () {
    Message::fromJson('not json');
})->throws(\JsonException::class);

test('fromJson throws on wrong jsonrpc version', function () {
    Message::fromJson(json_encode([
        'jsonrpc' => '1.0',
        'id' => 1,
        'method' => 'test',
    ]));
})->throws(\InvalidArgumentException::class, 'version');

test('fromJson throws on non-object JSON', function () {
    Message::fromJson('"just a string"');
})->throws(\InvalidArgumentException::class, 'object');

test('fromJson parses response with nested result', function () {
    $json = json_encode([
        'jsonrpc' => '2.0',
        'id' => 3,
        'result' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
        ],
    ]);

    $msg = Message::fromJson($json);

    expect($msg->result['protocolVersion'])->toBe('2025-06-18')
        ->and($msg->result['serverInfo']['name'])->toBe('test-server');
});

// -- IdGenerator --

test('IdGenerator produces sequential IDs', function () {
    $gen = new \CoquiBot\Coqui\Mcp\JsonRpc\IdGenerator();

    expect($gen->next())->toBe(1)
        ->and($gen->next())->toBe(2)
        ->and($gen->next())->toBe(3);
});

// -- JsonRpcError --

test('JsonRpcError is readonly value object', function () {
    $error = new JsonRpcError(code: -32600, message: 'Invalid Request', data: ['detail' => 'missing id']);

    expect($error->code)->toBe(-32600)
        ->and($error->message)->toBe('Invalid Request')
        ->and($error->data)->toBe(['detail' => 'missing id']);
});
