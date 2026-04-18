<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use ZipArchive;

final class OdtExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        if (!self::isRuntimeSupported()) {
            return ExtractorResult::fail('ODT extraction requires the PHP zip extension');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath);
        if ($opened !== true) {
            return ExtractorResult::fail('Failed to open ODT archive');
        }

        try {
            $xml = OpenDocumentArchiveReader::loadContentXml($zip);
            if ($xml === null) {
                return ExtractorResult::fail('ODT document is missing content.xml');
            }

            $paragraphNodes = $xml->xpath('/*[local-name()="document-content"]/*[local-name()="body"]/*[local-name()="text"]//*[local-name()="h" or local-name()="p"]');
            if (!is_array($paragraphNodes)) {
                return ExtractorResult::fail('ODT document contains no extractable text');
            }

            $blocks = [];
            foreach ($paragraphNodes as $paragraphNode) {
                $text = OpenDocumentArchiveReader::extractNodeText($paragraphNode);
                if ($text !== '') {
                    $blocks[] = [
                        'content' => $this->renderBlock($paragraphNode, $text),
                        'is_list_item' => $this->listDepth($paragraphNode) > 0,
                    ];
                }
            }

            if ($blocks === []) {
                return ExtractorResult::fail('ODT document contains no extractable text');
            }

            $content = $this->joinBlocks($blocks);

            return ExtractorResult::ok($content, BackstoryTextReader::estimateTokens($content));
        } finally {
            $zip->close();
        }
    }

    public function supportedExtensions(): array
    {
        return ['odt'];
    }

    public static function isRuntimeSupported(): bool
    {
        return OpenDocumentArchiveReader::isRuntimeSupported();
    }

    private function renderBlock(\SimpleXMLElement $node, string $text): string
    {
        if (OpenDocumentArchiveReader::localName($node) === 'h') {
            $level = (int) OpenDocumentArchiveReader::attributeValue(
                $node,
                OpenDocumentArchiveReader::TEXT_NS,
                'outline-level',
            );
            $level = max(1, min(3, $level));

            return str_repeat('#', 3 + $level) . ' ' . $text;
        }

        $listDepth = $this->listDepth($node);
        if ($listDepth > 0) {
            return str_repeat('  ', $listDepth - 1) . '- ' . $text;
        }

        return $text;
    }

    private function listDepth(\SimpleXMLElement $node): int
    {
        $ancestors = $node->xpath('ancestor::*[local-name()="list"]');

        return is_array($ancestors) ? count($ancestors) : 0;
    }

    /**
     * @param list<array{content: string, is_list_item: bool}> $blocks
     */
    private function joinBlocks(array $blocks): string
    {
        $content = '';

        foreach ($blocks as $index => $block) {
            if ($index > 0) {
                $previous = $blocks[$index - 1];
                $content .= $previous['is_list_item'] && $block['is_list_item'] ? "\n" : "\n\n";
            }

            $content .= $block['content'];
        }

        return $content;
    }
}