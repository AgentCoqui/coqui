<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use SimpleXMLElement;
use ZipArchive;

/**
 * Extracts slide text from presentation OOXML decks into markdown sections.
 *
 * This extractor is optional at runtime and depends on ZipArchive support.
 */
final class PptxExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        if (!self::isRuntimeSupported()) {
            return ExtractorResult::fail('PPTX extraction requires the PHP zip extension');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath);
        if ($opened !== true) {
            return ExtractorResult::fail('Failed to open PPTX archive');
        }

        try {
            $presentationXml = $zip->getFromName('ppt/presentation.xml');
            if (!is_string($presentationXml)) {
                return ExtractorResult::fail('PPTX deck is missing ppt/presentation.xml');
            }

            $relationships = $this->readPresentationRelationships($zip);
            $slides = $this->readSlideDefinitions($presentationXml, $relationships);

            if ($slides === []) {
                return ExtractorResult::fail('PPTX deck contains no readable slides');
            }

            $sections = [];
            foreach ($slides as $index => $slide) {
                $slideXml = $zip->getFromName($slide['path']);
                if (!is_string($slideXml)) {
                    continue;
                }

                $paragraphs = $this->readSlideParagraphs($slideXml);
                $speakerNotes = $this->readSpeakerNotes($zip, $slide['path']);
                if ($paragraphs === [] && $speakerNotes === []) {
                    continue;
                }

                $title = 'Slide ' . ($index + 1);
                if ($paragraphs !== []) {
                    $title = array_shift($paragraphs);
                    if ($title === '') {
                        $title = 'Slide ' . ($index + 1);
                    }
                }

                $sectionLines = ['#### Slide ' . ($index + 1) . ': ' . $title];
                if ($paragraphs !== []) {
                    $sectionLines[] = '';
                    foreach ($paragraphs as $paragraph) {
                        $sectionLines[] = '- ' . $paragraph;
                    }
                }

                if ($speakerNotes !== []) {
                    $sectionLines[] = '';
                    $sectionLines[] = '##### Speaker Notes';
                    $sectionLines[] = '';
                    foreach ($speakerNotes as $note) {
                        $sectionLines[] = '- ' . $note;
                    }
                }

                $sections[] = implode("\n", $sectionLines);
            }

            if ($sections === []) {
                return ExtractorResult::fail('PPTX deck contains no extractable slide text');
            }

            $content = implode("\n\n", $sections);

            return ExtractorResult::ok($content, BackstoryTextReader::estimateTokens($content));
        } finally {
            $zip->close();
        }
    }

    public function supportedExtensions(): array
    {
        return ['pptx', 'pptm'];
    }

    public static function isRuntimeSupported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @return array<string, string>
     */
    private function readPresentationRelationships(ZipArchive $zip): array
    {
        $relationshipsXml = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if (!is_string($relationshipsXml)) {
            return [];
        }

        $xml = $this->loadXml($relationshipsXml);
        if ($xml === null) {
            return [];
        }

        $relationshipNodes = $xml->xpath('/*[local-name()="Relationships"]/*[local-name()="Relationship"]');
        if (!is_array($relationshipNodes)) {
            return [];
        }

        $relationships = [];
        foreach ($relationshipNodes as $relationship) {
            $id = trim((string) $relationship['Id']);
            $target = trim((string) $relationship['Target']);
            if ($id === '' || $target === '') {
                continue;
            }

            $relationships[$id] = $this->resolvePartTarget('ppt/presentation.xml', $target);
        }

        return $relationships;
    }

    /**
     * @return list<string>
     */
    private function readSpeakerNotes(ZipArchive $zip, string $slidePath): array
    {
        $notesPath = $this->readNotesSlidePath($zip, $slidePath);
        if ($notesPath === null) {
            return [];
        }

        $notesXml = $zip->getFromName($notesPath);
        if (!is_string($notesXml)) {
            return [];
        }

        return $this->readSlideParagraphs($notesXml);
    }

    private function readNotesSlidePath(ZipArchive $zip, string $slidePath): ?string
    {
        $relationshipsPath = dirname($slidePath) . '/_rels/' . basename($slidePath) . '.rels';
        $relationshipsXml = $zip->getFromName($relationshipsPath);
        if (!is_string($relationshipsXml)) {
            return null;
        }

        $xml = $this->loadXml($relationshipsXml);
        if ($xml === null) {
            return null;
        }

        $relationshipNodes = $xml->xpath('/*[local-name()="Relationships"]/*[local-name()="Relationship"]');
        if (!is_array($relationshipNodes)) {
            return null;
        }

        foreach ($relationshipNodes as $relationship) {
            $type = trim((string) $relationship['Type']);
            if (!str_ends_with($type, '/notesSlide')) {
                continue;
            }

            $target = trim((string) $relationship['Target']);
            if ($target === '') {
                continue;
            }

            return $this->resolvePartTarget($slidePath, $target);
        }

        return null;
    }

    /**
     * @param array<string, string> $relationships
     * @return list<array{path: string}>
     */
    private function readSlideDefinitions(string $presentationXml, array $relationships): array
    {
        $xml = $this->loadXml($presentationXml);
        if ($xml === null) {
            return [];
        }

        $slideNodes = $xml->xpath('/*[local-name()="presentation"]/*[local-name()="sldIdLst"]/*[local-name()="sldId"]');
        if (!is_array($slideNodes)) {
            return [];
        }

        $slides = [];
        foreach ($slideNodes as $slideNode) {
            $relationshipAttributes = $slideNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = trim((string) ($relationshipAttributes['id'] ?? ''));
            if ($relationshipId === '') {
                continue;
            }

            $path = $relationships[$relationshipId] ?? null;
            if ($path === null) {
                continue;
            }

            $slides[] = ['path' => $path];
        }

        return $slides;
    }

    /**
     * @return list<string>
     */
    private function readSlideParagraphs(string $slideXml): array
    {
        $xml = $this->loadXml($slideXml);
        if ($xml === null) {
            return [];
        }

        $paragraphNodes = $xml->xpath('//*[local-name()="txBody"]/*[local-name()="p"]');
        if (!is_array($paragraphNodes)) {
            return [];
        }

        $paragraphs = [];
        foreach ($paragraphNodes as $paragraphNode) {
            $text = $this->extractTextRuns($paragraphNode);
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        if ($paragraphs !== []) {
            return $paragraphs;
        }

        $fallbackText = $this->extractTextRuns($xml);

        return $fallbackText === '' ? [] : [$fallbackText];
    }

    private function loadXml(string $xml): ?SimpleXMLElement
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

    private function normalizeXmlText(string $text): string
    {
        return trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private function extractTextRuns(SimpleXMLElement $element): string
    {
        $textNodes = $element->xpath('.//*[local-name()="t"]');
        if (!is_array($textNodes) || $textNodes === []) {
            return $this->normalizeXmlText((string) $element);
        }

        $parts = [];
        foreach ($textNodes as $textNode) {
            $parts[] = (string) $textNode;
        }

        return $this->normalizeXmlText(implode(' ', $parts));
    }

    private function resolvePartTarget(string $sourcePartPath, string $target): string
    {
        $normalizedTarget = str_replace('\\', '/', $target);
        $normalizedTarget = preg_replace('#^/+#', '', $normalizedTarget) ?? $normalizedTarget;
        if (str_starts_with($normalizedTarget, 'ppt/')) {
            return $normalizedTarget;
        }

        $segments = [];
        $directory = dirname($sourcePartPath);
        if ($directory !== '.' && $directory !== '') {
            $segments = explode('/', trim($directory, '/'));
        }

        foreach (explode('/', $normalizedTarget) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}