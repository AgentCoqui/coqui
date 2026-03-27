<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class BlockQuoteRenderer implements NodeRendererInterface
{
    private const string BAR_STYLE = "\033[2;37m";  // dim white
    private const string RESET = "\033[0m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        BlockQuote::assertInstanceOf($node);

        $content = rtrim($childRenderer->renderNodes($node->children()), "\n");
        $lines = explode("\n", $content);

        $output = '';
        foreach ($lines as $line) {
            $output .= self::BAR_STYLE . '  │ ' . self::RESET . $line . "\n";
        }

        return $output . "\n";
    }
}
