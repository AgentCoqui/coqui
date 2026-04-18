<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Shared text normalization and safety helpers for backstory extractors.
 */
final class BackstoryTextReader
{
    /** @var list<string> */
    private const array DETECTABLE_ENCODINGS = [
        'UTF-8',
        'UTF-16',
        'UTF-16LE',
        'UTF-16BE',
        'Windows-1252',
        'ISO-8859-1',
    ];

    public static function read(string $absolutePath): ExtractorResult
    {
        $content = file_get_contents($absolutePath);

        if ($content === false) {
            return ExtractorResult::fail('Failed to read file');
        }

        if (self::looksBinary($content)) {
            return ExtractorResult::fail('File appears to be binary or uses an unsupported encoding');
        }

        $normalized = self::normalizeEncoding($content);
        if ($normalized === null) {
            return ExtractorResult::fail('Failed to decode text content');
        }

        $normalized = self::stripUtf8Bom($normalized);
        $normalized = self::normalizeLineEndings($normalized);

        if (self::containsUnsupportedControlChars($normalized)) {
            return ExtractorResult::fail('File contains unsupported control characters');
        }

        return ExtractorResult::ok($normalized, self::estimateTokens($normalized));
    }

    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    public static function toCodeFence(string $content, string $language = ''): string
    {
        $language = trim($language);

        return '```' . $language . "\n" . rtrim($content) . "\n```";
    }

    private static function normalizeEncoding(string $content): ?string
    {
        $encoding = self::detectEncoding($content);
        if ($encoding === null) {
            return null;
        }

        $normalized = mb_convert_encoding($content, 'UTF-8', $encoding);

        return $normalized === false ? null : $normalized;
    }

    private static function detectEncoding(string $content): ?string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }

        if (str_starts_with($content, "\xFF\xFE")) {
            return 'UTF-16LE';
        }

        if (str_starts_with($content, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        $detected = mb_detect_encoding($content, self::DETECTABLE_ENCODINGS, true);
        if ($detected !== false) {
            return $detected;
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return 'UTF-8';
        }

        return null;
    }

    private static function stripUtf8Bom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }

    private static function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    private static function looksBinary(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        if (self::looksLikeUtf16($content)) {
            return false;
        }

        if (str_contains($content, "\0")) {
            return true;
        }

        $sample = substr($content, 0, 4096);
        $controlBytes = 0;
        $sampleLength = strlen($sample);

        for ($index = 0; $index < $sampleLength; $index++) {
            $byte = ord($sample[$index]);

            if ($byte <= 0x08 || ($byte >= 0x0E && $byte <= 0x1F)) {
                $controlBytes++;
            }
        }

        return ($controlBytes / $sampleLength) > 0.1;
    }

    private static function looksLikeUtf16(string $content): bool
    {
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return true;
        }

        $sample = substr($content, 0, 200);
        $length = strlen($sample);
        if ($length < 4) {
            return false;
        }

        $zeroEven = 0;
        $zeroOdd = 0;

        for ($index = 0; $index < $length; $index++) {
            if ($sample[$index] !== "\0") {
                continue;
            }

            if ($index % 2 === 0) {
                $zeroEven++;
                continue;
            }

            $zeroOdd++;
        }

        $threshold = max(3, (int) floor($length / 8));

        return $zeroEven >= $threshold || $zeroOdd >= $threshold;
    }

    private static function containsUnsupportedControlChars(string $content): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1;
    }
}