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

    /**
     * @param list<ExtractorInterface>|null $additionalExtractors Extra extractors
     *        to register after the core set. When null, mod-provided extractors
     *        are discovered from installed packages. Pass an explicit array
     *        (including []) to bypass discovery — used by tests for determinism.
     */
    public function __construct(?array $additionalExtractors = null)
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

        if (OdtExtractor::isRuntimeSupported()) {
            $extractors[] = new OdtExtractor();
        }

        if (OdsExtractor::isRuntimeSupported()) {
            $extractors[] = new OdsExtractor();
        }

        if (OdpExtractor::isRuntimeSupported()) {
            $extractors[] = new OdpExtractor();
        }

        $additional = $additionalExtractors ?? (new BackstoryExtractorDiscovery())->discover();
        foreach ($additional as $extractor) {
            $extractors[] = $extractor;
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
