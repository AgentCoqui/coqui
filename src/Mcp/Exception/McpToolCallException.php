<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Exception;

/**
 * An MCP tool call returned isError: true or failed to execute.
 */
final class McpToolCallException extends \RuntimeException
{
    /** @param array<int, mixed> $content */
    public static function fromErrorContent(string $toolName, array $content): self
    {
        $texts = [];

        foreach ($content as $item) {
            if (is_array($item) && isset($item['text'])) {
                $texts[] = $item['text'];
            }
        }

        $message = $texts !== []
            ? implode("\n", $texts)
            : 'Tool returned an error with no message';

        return new self(sprintf('MCP tool "%s" failed: %s', $toolName, $message));
    }

    public static function notFound(string $toolName, string $serverName): self
    {
        return new self(sprintf('Tool "%s" not found on MCP server "%s"', $toolName, $serverName));
    }
}
