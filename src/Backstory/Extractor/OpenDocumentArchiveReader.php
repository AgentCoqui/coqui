<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use DOMNode;
use SimpleXMLElement;
use ZipArchive;

final class OpenDocumentArchiveReader
{
    public const string OFFICE_NS = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    public const string TABLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    public const string DRAW_NS = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';
    public const string TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    public static function isRuntimeSupported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public static function loadContentXml(ZipArchive $zip): ?SimpleXMLElement
    {
        $contentXml = $zip->getFromName('content.xml');
        if (!is_string($contentXml)) {
            return null;
        }

        return self::loadXml($contentXml);
    }

    public static function loadXml(string $xml): ?SimpleXMLElement
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $element = simplexml_load_string($xml);

            return $element instanceof SimpleXMLElement ? $element : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    public static function extractNodeText(SimpleXMLElement $element): string
    {
        $node = dom_import_simplexml($element);

        $parts = [];
        self::collectNodeText($node, $parts);

        return self::normalizeText(implode('', $parts));
    }

    public static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function localName(SimpleXMLElement $element): string
    {
        $node = dom_import_simplexml($element);

        return (string) ($node->localName ?? '');
    }

    public static function attributeValue(SimpleXMLElement $element, ?string $namespace, string $name): string
    {
        $attributes = $namespace === null
            ? $element->attributes()
            : $element->attributes($namespace);

        return trim((string) ($attributes[$name] ?? ''));
    }

    public static function repeatCount(SimpleXMLElement $element, string $namespace, string $name, int $max = 256): int
    {
        $value = (int) self::attributeValue($element, $namespace, $name);
        if ($value < 1) {
            return 1;
        }

        return min($value, $max);
    }

    /**
     * @param array<int, string> $parts
     */
    private static function collectNodeText(DOMNode $node, array &$parts): void
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $parts[] = $node->nodeValue ?? '';
            return;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $localName = $node->localName ?? '';
        if ($localName === 's') {
            $count = 1;
            if ($node->attributes !== null) {
                foreach ($node->attributes as $attribute) {
                    if ($attribute->localName === 'c') {
                        $count = max(1, min(16, (int) $attribute->nodeValue));
                        break;
                    }
                }
            }

            $parts[] = str_repeat(' ', $count);
            return;
        }

        if ($localName === 'line-break') {
            $parts[] = "\n";
            return;
        }

        foreach ($node->childNodes as $childNode) {
            self::collectNodeText($childNode, $parts);
        }
    }
}