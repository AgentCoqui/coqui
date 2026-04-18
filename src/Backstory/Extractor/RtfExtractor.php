<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Performs a conservative plain-text extraction from RTF documents.
 */
final class RtfExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        $content = trim($result->content);
        if ($content === '') {
            return ExtractorResult::fail('File is empty');
        }

        if (!preg_match('/^\{\\\\rtf/i', ltrim($content))) {
            return ExtractorResult::fail('Invalid RTF document');
        }

        $text = $this->decodeRtf($content);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return ExtractorResult::fail('RTF contains no extractable text');
        }

        return ExtractorResult::ok($text, BackstoryTextReader::estimateTokens($text));
    }

    public function supportedExtensions(): array
    {
        return ['rtf'];
    }

    private function decodeRtf(string $content): string
    {
        $content = preg_replace_callback(
            '/\\\\u(-?\d+)\\??/',
            static function (array $matches): string {
                $codepoint = (int) $matches[1];
                if ($codepoint < 0) {
                    $codepoint += 65536;
                }

                return mb_chr($codepoint, 'UTF-8');
            },
            $content,
        ) ?? $content;

        $content = preg_replace_callback(
            "/\\\\'([0-9a-fA-F]{2})/",
            static function (array $matches): string {
                return mb_convert_encoding(chr((int) hexdec($matches[1])), 'UTF-8', 'Windows-1252');
            },
            $content,
        ) ?? $content;

        $content = strtr($content, [
            '\\par' => "\n",
            '\\line' => "\n",
            '\\tab' => "\t",
            '\\bullet' => '•',
            '\\emdash' => '—',
            '\\endash' => '–',
            '\\lquote' => '‘',
            '\\rquote' => '’',
            '\\ldblquote' => '“',
            '\\rdblquote' => '”',
            '\\~' => ' ',
            '\\_' => '‑',
        ]);

        $content = preg_replace('/\{\\\\\*[^{}]*\}/', '', $content) ?? $content;
        $content = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', '', $content) ?? $content;
        $content = str_replace(['\\{', '\\}', '\\\\'], ['{', '}', '\\'], $content);
        $content = str_replace(['{', '}'], '', $content);

        return trim((string) preg_replace('/[ \t]+/', ' ', $content));
    }
}