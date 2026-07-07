<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Exception;

/**
 * MCP protocol-level error — invalid JSON-RPC, unexpected response, version mismatch.
 */
final class McpProtocolException extends \RuntimeException
{
    public static function unexpectedResponse(string $expected, string $actual): self
    {
        return new self(sprintf('Expected %s, got: %s', $expected, $actual));
    }

    public static function versionMismatch(string $requested, string $received): self
    {
        return new self(sprintf(
            'Protocol version mismatch — requested "%s", server responded with "%s"',
            $requested,
            $received,
        ));
    }

    public static function jsonRpcError(int $code, string $message, mixed $data = null): self
    {
        $text = sprintf('JSON-RPC error %d: %s', $code, $message);

        if ($data !== null) {
            $text .= ' — ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES));
        }

        return new self($text, $code);
    }
}
