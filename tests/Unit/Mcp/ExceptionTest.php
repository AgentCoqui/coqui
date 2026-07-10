<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Exception\McpConnectionException;
use CoquiBot\Coqui\Mcp\Exception\McpProtocolException;
use CoquiBot\Coqui\Mcp\Exception\McpToolCallException;

// -- McpConnectionException --

test('processStartFailed creates exception with command info', function () {
    $e = McpConnectionException::processStartFailed('npx', 'Permission denied');

    expect($e)->toBeInstanceOf(McpConnectionException::class)
        ->and($e->getMessage())->toContain('npx')
        ->and($e->getMessage())->toContain('Permission denied');
});

test('initializeFailed creates exception', function () {
    $e = McpConnectionException::initializeFailed('test-server', 'timeout');

    expect($e->getMessage())->toContain('test-server')
        ->and($e->getMessage())->toContain('timeout');
});

test('disconnected creates exception', function () {
    $e = McpConnectionException::disconnected('test-server');

    expect($e->getMessage())->toContain('test-server');
});

test('timeout creates exception', function () {
    $e = McpConnectionException::timeout('test-server', 30);

    expect($e->getMessage())->toContain('30')
        ->and($e->getMessage())->toContain('test-server');
});

// -- McpProtocolException --

test('unexpectedResponse creates exception', function () {
    $e = McpProtocolException::unexpectedResponse('result field', 'notification');

    expect($e)->toBeInstanceOf(McpProtocolException::class)
        ->and($e->getMessage())->toContain('result field');
});

test('versionMismatch creates exception', function () {
    $e = McpProtocolException::versionMismatch('2025-06-18', '2024-11-05');

    expect($e->getMessage())->toContain('2025-06-18')
        ->and($e->getMessage())->toContain('2024-11-05');
});

test('jsonRpcError creates exception from error data', function () {
    $e = McpProtocolException::jsonRpcError(-32601, 'Method not found');

    expect($e->getMessage())->toContain('Method not found')
        ->and($e->getMessage())->toContain('-32601');
});

// -- McpToolCallException --

test('fromErrorContent creates exception', function () {
    $e = McpToolCallException::fromErrorContent('create_issue', [
        ['type' => 'text', 'text' => 'Rate limit exceeded'],
    ]);

    expect($e)->toBeInstanceOf(McpToolCallException::class)
        ->and($e->getMessage())->toContain('create_issue')
        ->and($e->getMessage())->toContain('Rate limit exceeded');
});

test('notFound creates exception', function () {
    $e = McpToolCallException::notFound('create_issue', 'github');

    expect($e->getMessage())->toContain('create_issue')
        ->and($e->getMessage())->toContain('github');
});
