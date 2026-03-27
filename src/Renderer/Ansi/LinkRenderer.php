<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class LinkRenderer implements NodeRendererInterface
{
    private const string UNDERLINE = "\033[4m";
    private const string RESET_UNDERLINE = "\033[24m";
    private const string DIM = "\033[2m";
    private const string RESET_DIM = "\033[22m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Link::assertInstanceOf($node);
        assert($node instanceof Link);

        $text = $childRenderer->renderNodes($node->children());
        $url = $node->getUrl();

        // If the link text is the same as the URL, just show it underlined
        if ($text === $url) {
            return self::UNDERLINE . $text . self::RESET_UNDERLINE;
        }

        return self::UNDERLINE . $text . self::RESET_UNDERLINE
            . self::DIM . ' (' . $url . ')' . self::RESET_DIM;
    }
}
