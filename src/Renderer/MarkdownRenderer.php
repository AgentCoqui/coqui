<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use CoquiBot\Coqui\Renderer\Ansi\AnsiRendererExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\MarkdownConverter;

final class MarkdownRenderer
{
    private static ?MarkdownConverter $converter = null;

    public static function render(string $markdown): string
    {
        $converter = self::getConverter();
        $html = $converter->convert($markdown);

        return (string) $html;
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
