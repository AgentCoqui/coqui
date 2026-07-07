<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Support;

/**
 * Split a shell-like argument string while preserving quoted groups.
 */
final class ArgumentTokenizer
{
    /**
     * @return list<string>
     */
    public static function split(string $raw): array
    {
        $args = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $char = $raw[$i];

            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                    continue;
                }

                if ($char === '\\' && $i + 1 < $len && $raw[$i + 1] === $quoteChar) {
                    $current .= $quoteChar;
                    $i++;
                    continue;
                }

                $current .= $char;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inQuote = true;
                $quoteChar = $char;
                continue;
            }

            if (ctype_space($char)) {
                if ($current !== '') {
                    $args[] = $current;
                    $current = '';
                }

                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $args[] = $current;
        }

        return $args;
    }
}