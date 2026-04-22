<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use CoquiBot\Coqui\Renderer\MarkdownRenderer;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ImageRenderer implements NodeRendererInterface
{
    private const string DIM = "\033[2m";
    private const string RESET = "\033[22m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Image::assertInstanceOf($node);
        assert($node instanceof Image);

        $alt = $childRenderer->renderNodes($node->children());
        $url = $node->getUrl();

        $preview = MarkdownRenderer::renderLocalImagePreview($url, trim($alt));
        if ($preview !== null) {
            return $preview;
        }

        return self::DIM . '[image: ' . ($alt !== '' ? $alt : $url) . ']' . self::RESET;
    }
}
