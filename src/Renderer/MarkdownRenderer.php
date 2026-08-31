<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use CoquiBot\Coqui\Renderer\Ansi\AnsiRendererExtension;
use CoquiBot\Coqui\Support\ImagePreviewService;
use CoquiBot\Coqui\Support\ImagePreviewState;
use League\CommonMark\Environment\Environment;
use League\CommonMark\MarkdownConverter;

final class MarkdownRenderer
{
    private static ?MarkdownConverter $converter = null;
    private const string DIM = "\033[2m";
    private const string RESET_DIM = "\033[22m";
    private static ?ImagePreviewService $imagePreviewService = null;
    private static ?ImagePreviewState $imagePreviewState = null;

    public static function render(
        string $markdown,
        ?ImagePreviewService $imagePreviewService = null,
        ?ImagePreviewState $imagePreviewState = null,
    ): string
    {
        $previousService = self::$imagePreviewService;
        $previousState = self::$imagePreviewState;
        self::$imagePreviewService = $imagePreviewService;
        self::$imagePreviewState = $imagePreviewState;

        $converter = self::getConverter();

        try {
            $html = $converter->convert($markdown);
        } finally {
            self::$imagePreviewService = $previousService;
            self::$imagePreviewState = $previousState;
        }

        return (string) $html;
    }

    public static function renderLocalImagePreview(string $url, string $label = ''): ?string
    {
        if (self::$imagePreviewService === null || self::$imagePreviewState === null) {
            return null;
        }

        if (self::$imagePreviewState->hasRenderedPreview() || !self::$imagePreviewService->canPreviewPath($url)) {
            return null;
        }

        try {
            $payload = self::$imagePreviewService->preview($url);
        } catch (\RuntimeException) {
            return null;
        }

        $preview = is_string($payload['preview']) ? trim($payload['preview']) : '';
        if ($preview === '') {
            return null;
        }

        if (!self::$imagePreviewState->consume()) {
            return null;
        }

        $path = $payload['path'];
        $title = trim($label) !== '' ? trim($label) : basename($path);

        return self::DIM . '[image preview: ' . $title . ']' . self::RESET_DIM . "\n" . $preview;
    }

    private static function getConverter(): MarkdownConverter
    {
        if (self::$converter === null) {
            $environment = new Environment();
            $environment->addExtension(new AnsiRendererExtension());
            self::$converter = new MarkdownConverter($environment);
        }

        return self::$converter;
    }
}
