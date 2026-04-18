<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use ZipArchive;

final class OdpExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        if (!self::isRuntimeSupported()) {
            return ExtractorResult::fail('ODP extraction requires the PHP zip extension');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath);
        if ($opened !== true) {
            return ExtractorResult::fail('Failed to open ODP archive');
        }

        try {
            $xml = OpenDocumentArchiveReader::loadContentXml($zip);
            if ($xml === null) {
                return ExtractorResult::fail('ODP deck is missing content.xml');
            }

            $slideNodes = $xml->xpath('/*[local-name()="document-content"]/*[local-name()="body"]/*[local-name()="presentation"]/*[local-name()="page"]');
            if (!is_array($slideNodes) || $slideNodes === []) {
                return ExtractorResult::fail('ODP deck contains no readable slides');
            }

            $sections = [];
            foreach ($slideNodes as $index => $slideNode) {
                $paragraphNodes = $slideNode->xpath('.//*[local-name()="p"]');
                if (!is_array($paragraphNodes) || $paragraphNodes === []) {
                    continue;
                }

                $paragraphs = [];
                foreach ($paragraphNodes as $paragraphNode) {
                    $text = OpenDocumentArchiveReader::extractNodeText($paragraphNode);
                    if ($text !== '') {
                        $paragraphs[] = $text;
                    }
                }

                if ($paragraphs === []) {
                    continue;
                }

                $defaultTitle = OpenDocumentArchiveReader::attributeValue(
                    $slideNode,
                    OpenDocumentArchiveReader::DRAW_NS,
                    'name',
                );
                $title = array_shift($paragraphs);

                $sectionLines = ['#### Slide ' . ($index + 1) . ': ' . $title];
                if ($paragraphs !== []) {
                    $sectionLines[] = '';
                    foreach ($paragraphs as $paragraph) {
                        $sectionLines[] = '- ' . $paragraph;
                    }
                }

                $sections[] = implode("\n", $sectionLines);
            }

            if ($sections === []) {
                return ExtractorResult::fail('ODP deck contains no extractable slide text');
            }

            $content = implode("\n\n", $sections);

            return ExtractorResult::ok($content, BackstoryTextReader::estimateTokens($content));
        } finally {
            $zip->close();
        }
    }

    public function supportedExtensions(): array
    {
        return ['odp'];
    }

    public static function isRuntimeSupported(): bool
    {
        return OpenDocumentArchiveReader::isRuntimeSupported();
    }
}