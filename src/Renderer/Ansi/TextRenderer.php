<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TextRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Text::assertInstanceOf($node);
        assert($node instanceof Text);

        return $node->getLiteral();
    }
}
