<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Extracts simple XML documents into a readable markdown outline and falls back
 * to a fenced XML block for more complex structures.
 */
final class XmlExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        $xml = trim($this->normalizeXmlDeclaration($result->content));
        if ($xml === '') {
            return ExtractorResult::fail('File is empty');
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$loaded || $dom->documentElement === null) {
                return ExtractorResult::fail('Invalid XML document');
            }

            $analysis = $this->analyzeElement($dom->documentElement);
            if ($this->isSimpleDocument($analysis)) {
                $markdown = trim(implode("\n", $this->renderElement($dom->documentElement)));

                return ExtractorResult::ok($markdown, BackstoryTextReader::estimateTokens($markdown));
            }

            $formattedXml = $this->formatXml($dom, $xml);
            $output = BackstoryTextReader::toCodeFence($formattedXml, 'xml');

            return ExtractorResult::ok($output, BackstoryTextReader::estimateTokens($output));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    public function supportedExtensions(): array
    {
        return ['xml'];
    }

    /**
     * @return array{element_count: int, max_depth: int, attribute_count: int, mixed_content: bool}
     */
    private function analyzeElement(DOMElement $element, int $depth = 1): array
    {
        $elementCount = 1;
        $maxDepth = $depth;
        $attributeCount = $element->attributes->length;
        $mixedContent = $this->hasMixedContent($element);

        foreach ($this->childElements($element) as $child) {
            $analysis = $this->analyzeElement($child, $depth + 1);
            $elementCount += $analysis['element_count'];
            $maxDepth = max($maxDepth, $analysis['max_depth']);
            $attributeCount += $analysis['attribute_count'];
            $mixedContent = $mixedContent || $analysis['mixed_content'];
        }

        return [
            'attribute_count' => $attributeCount,
            'element_count' => $elementCount,
            'max_depth' => $maxDepth,
            'mixed_content' => $mixedContent,
        ];
    }

    /**
     * @param array{element_count: int, max_depth: int, attribute_count: int, mixed_content: bool} $analysis
     */
    private function isSimpleDocument(array $analysis): bool
    {
        return !$analysis['mixed_content']
            && $analysis['element_count'] <= 40
            && $analysis['max_depth'] <= 5
            && $analysis['attribute_count'] <= 20;
    }

    private function hasMixedContent(DOMElement $element): bool
    {
        $hasChildElements = false;
        $hasDirectText = false;

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $hasChildElements = true;
                continue;
            }

            if (in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true) && trim($child->textContent) !== '') {
                $hasDirectText = true;
            }
        }

        return $hasChildElements && $hasDirectText;
    }

    /**
     * @return list<string>
     */
    private function renderElement(DOMElement $element, int $depth = 0): array
    {
        $indent = str_repeat('  ', $depth);
        $attributes = $this->formatAttributes($element);
        $label = $element->tagName;
        if ($attributes !== '') {
            $label .= ' [' . $attributes . ']';
        }

        $children = $this->childElements($element);
        $value = trim($this->collectDirectText($element));

        if ($children === []) {
            return [$indent . '- ' . ($value === '' ? $label : $label . ': ' . $value)];
        }

        $lines = [$indent . '- ' . $label];
        if ($value !== '') {
            $lines[] = $indent . '  - value: ' . $value;
        }

        foreach ($children as $child) {
            $lines = [...$lines, ...$this->renderElement($child, $depth + 1)];
        }

        return $lines;
    }

    /**
     * @return list<DOMElement>
     */
    private function childElements(DOMElement $element): array
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function collectDirectText(DOMElement $element): string
    {
        $parts = [];

        foreach ($element->childNodes as $child) {
            if (!in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                continue;
            }

            $text = trim($child->textContent);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }

    private function formatAttributes(DOMElement $element): string
    {
        $pairs = [];

        foreach ($element->attributes as $attribute) {
            $pairs[] = $attribute->name . ': ' . trim($attribute->value);
        }

        return implode(', ', $pairs);
    }

    private function formatXml(DOMDocument $dom, string $fallback): string
    {
        $dom->formatOutput = true;
        $formatted = $dom->saveXML();

        return $formatted !== false ? trim($formatted) : trim($fallback);
    }

    private function normalizeXmlDeclaration(string $xml): string
    {
        $normalized = preg_replace(
            '/<\?xml([^>]*?)encoding=["\'][^"\']+["\']([^>]*?)\?>/i',
            '<?xml$1encoding="UTF-8"$2?>',
            $xml,
            1,
        );

        return $normalized ?? $xml;
    }
}