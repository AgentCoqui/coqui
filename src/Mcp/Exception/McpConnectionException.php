<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Exception;

/**
 * Connection to an MCP server failed — process didn't start, crashed, or timed out.
 */
final class McpConnectionException extends \RuntimeException
{
    public static function processStartFailed(string $command, string $reason = ''): self
    {
        return new self(self::appendReason(
            sprintf('Failed to start MCP server process: %s', $command),
            $reason,
        ));
    }

    public static function initializeFailed(string $server, string $reason): self
    {
        return new self(self::appendReason(
            sprintf('MCP server "%s" initialization failed', $server),
            $reason,
        ));
    }

    public static function disconnected(string $server, string $reason = ''): self
    {
        return new self(self::appendReason(
            sprintf('MCP server "%s" is not connected', $server),
            $reason,
        ));
    }

    public static function timeout(string $server, int $seconds, string $reason = ''): self
    {
        return new self(self::appendReason(
            sprintf('MCP server "%s" did not respond within %d seconds', $server, $seconds),
            $reason,
        ));
    }

    private static function appendReason(string $message, string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            return $message;
        }

        return $message . ' — ' . $reason;
    }
}
