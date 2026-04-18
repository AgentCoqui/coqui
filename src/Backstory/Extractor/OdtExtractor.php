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

            $paragraphs = [];
            foreach ($paragraphNodes as $paragraphNode) {
                $text = OpenDocumentArchiveReader::extractNodeText($paragraphNode);
                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }

            if ($paragraphs === []) {
                return ExtractorResult::fail('ODT document contains no extractable text');
            }

            $content = implode("\n\n", $paragraphs);

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
}