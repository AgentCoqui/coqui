<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class HtmlInlineRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        HtmlInline::assertInstanceOf($node);
        assert($node instanceof HtmlInline);

        return $node->getLiteral();
    }
}
