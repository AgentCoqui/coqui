<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class DocumentRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Document::assertInstanceOf($node);

        return rtrim($childRenderer->renderNodes($node->children()), "\n") . "\n";
    }
}
