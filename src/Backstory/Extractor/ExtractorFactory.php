<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Maps file extensions to their content extractor.
 */
final class ExtractorFactory
{
    /** @var array<string, ExtractorInterface> Extension → extractor */
    private array $map = [];

    public function __construct()
    {
        $extractors = [
            new TextExtractor(),
            new MarkdownExtractor(),
            new JsonExtractor(),
            new YamlExtractor(),
            new CsvExtractor(),
            new HtmlExtractor(),
            new XmlExtractor(),
            new RtfExtractor(),
            new SqlExtractor(),
            new CodeBlockExtractor(),
            new PdfExtractor(),
            new DocxExtractor(),
        ];

        if (XlsxExtractor::isRuntimeSupported()) {
            $extractors[] = new XlsxExtractor();
        }

        if (PptxExtractor::isRuntimeSupported()) {
            $extractors[] = new PptxExtractor();
        }

        foreach ($extractors as $extractor) {
            foreach ($extractor->supportedExtensions() as $ext) {
                $this->map[$ext] = $extractor;
            }
        }
    }

    public function get(string $extension): ?ExtractorInterface
    {
        return $this->map[strtolower($extension)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return array_keys($this->map);
    }

    public function isSupported(string $extension): bool
    {
        return isset($this->map[strtolower($extension)]);
    }
}
