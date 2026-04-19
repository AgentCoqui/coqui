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
                $frameParagraphGroups = $this->extractFrameParagraphGroups($slideNode);
                if ($frameParagraphGroups === []) {
                    continue;
                }

                $defaultTitle = OpenDocumentArchiveReader::attributeValue(
                    $slideNode,
                    OpenDocumentArchiveReader::DRAW_NS,
                    'name',
                );
                $title = $this->resolveTitle($frameParagraphGroups, $defaultTitle, $index);
                $paragraphs = $this->flattenParagraphGroups($frameParagraphGroups);

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

    /**
     * @return list<list<string>>
     */
    private function extractFrameParagraphGroups(\SimpleXMLElement $slideNode): array
    {
        $frameNodes = $slideNode->xpath('.//*[local-name()="frame"]');
        if (!is_array($frameNodes)) {
            return [];
        }

        $groups = [];
        foreach ($frameNodes as $frameNode) {
            $paragraphNodes = $frameNode->xpath('.//*[local-name()="text-box"]//*[local-name()="p"]');
            if (!is_array($paragraphNodes) || $paragraphNodes === []) {
                continue;
            }

            $group = [];
            foreach ($paragraphNodes as $paragraphNode) {
                $text = OpenDocumentArchiveReader::extractNodeText($paragraphNode);
                if ($text !== '') {
                    $group[] = $text;
                }
            }

            if ($group !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param list<list<string>> $frameParagraphGroups
     */
    private function resolveTitle(array &$frameParagraphGroups, string $defaultTitle, int $slideIndex): string
    {
        $fallbackTitle = $defaultTitle !== '' ? $defaultTitle : 'Slide ' . ($slideIndex + 1);
        if ($frameParagraphGroups === []) {
            return $fallbackTitle;
        }

        $firstGroup = $frameParagraphGroups[0];
        $useFirstParagraphAsTitle = count($frameParagraphGroups) > 1 && count($firstGroup) === 1;
        if (!$useFirstParagraphAsTitle && self::isGenericSlideName($defaultTitle)) {
            $useFirstParagraphAsTitle = true;
        }

        if ($useFirstParagraphAsTitle) {
            $title = array_shift($frameParagraphGroups[0]);
            if ($frameParagraphGroups[0] === []) {
                array_shift($frameParagraphGroups);
            }

            return $title ?? $fallbackTitle;
        }

        return $fallbackTitle;
    }

    /**
     * @param list<list<string>> $frameParagraphGroups
     * @return list<string>
     */
    private function flattenParagraphGroups(array $frameParagraphGroups): array
    {
        $paragraphs = [];
        foreach ($frameParagraphGroups as $group) {
            foreach ($group as $paragraph) {
                $paragraphs[] = $paragraph;
            }
        }

        return $paragraphs;
    }

    private static function isGenericSlideName(string $title): bool
    {
        if ($title === '') {
            return true;
        }

        return preg_match('/^(slide|page)\s*\d+$/i', $title) === 1;
    }
}