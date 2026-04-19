<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Sanitizes HTML and converts it into markdown.
 */
final class HtmlExtractor implements ExtractorInterface
{
    /** @var list<string> */
    private const array UNSAFE_NODES = [
        'applet',
        'base',
        'canvas',
        'embed',
        'form',
        'head',
        'iframe',
        'input',
        'link',
        'meta',
        'noscript',
        'object',
        'option',
        'script',
        'select',
        'source',
        'style',
        'textarea',
    ];

    public function extract(string $absolutePath): ExtractorResult
    {
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        $html = trim($result->content);
        if ($html === '') {
            return ExtractorResult::fail('File is empty');
        }

        $sanitized = $this->sanitizeHtml($html);
        if ($sanitized === '') {
            return ExtractorResult::fail('HTML contains no extractable content');
        }

        try {
            $converter = new HtmlConverter([
                'header_style' => 'atx',
                'hard_break' => true,
                'remove_nodes' => implode(' ', self::UNSAFE_NODES),
                'strip_placeholder_links' => true,
                'strip_tags' => true,
            ]);
            $converter->getEnvironment()->addConverter(new TableConverter());
            $markdown = trim($converter->convert($sanitized));
        } catch (\Throwable $e) {
            return ExtractorResult::fail('HTML conversion failed: ' . $e->getMessage());
        }

        if ($markdown === '') {
            return ExtractorResult::fail('HTML contains no extractable content');
        }

        return ExtractorResult::ok($markdown, BackstoryTextReader::estimateTokens($markdown));
    }

    public function supportedExtensions(): array
    {
        return ['htm', 'html'];
    }

    private function sanitizeHtml(string $html): string
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML(
                '<!DOCTYPE html><html><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            if (!$loaded) {
                return '';
            }

            $xpath = new DOMXPath($dom);
            foreach (self::UNSAFE_NODES as $nodeName) {
                $nodes = $xpath->query('//'.$nodeName);
                if ($nodes === false) {
                    continue;
                }

                for ($index = $nodes->length - 1; $index >= 0; $index--) {
                    $node = $nodes->item($index);
                    if (!$node instanceof DOMNode || $node->parentNode === null) {
                        continue;
                    }

                    $node->parentNode->removeChild($node);
                }
            }

            $elements = $xpath->query('//*');
            if ($elements !== false) {
                foreach ($elements as $node) {
                    if ($node instanceof DOMElement) {
                        $this->sanitizeAttributes($node);
                    }
                }
            }

            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return '';
            }

            return trim($this->serializeChildren($body));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        $attributesToRemove = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'style') {
                $attributesToRemove[] = $attribute->name;
                continue;
            }

            if (!in_array($name, ['href', 'src', 'xlink:href'], true)) {
                continue;
            }

            $normalized = strtolower($value);
            if (str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'data:')) {
                $attributesToRemove[] = $attribute->name;
            }
        }

        foreach ($attributesToRemove as $name) {
            $element->removeAttribute($name);
        }
    }

    private function serializeChildren(DOMNode $node): string
    {
        $ownerDocument = $node->ownerDocument;
        if ($ownerDocument === null) {
            return '';
        }

        $html = '';
        foreach ($node->childNodes as $child) {
            $chunk = $ownerDocument->saveHTML($child);
            if ($chunk !== false) {
                $html .= $chunk;
            }
        }

        return $html;
    }
}