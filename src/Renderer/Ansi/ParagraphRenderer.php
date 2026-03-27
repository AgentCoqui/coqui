<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ParagraphRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Paragraph::assertInstanceOf($node);

        $content = $childRenderer->renderNodes($node->children());

        // Tight list items don't get extra spacing — check parent
        if ($node->parent() instanceof \League\CommonMark\Extension\CommonMark\Node\Block\ListItem) {
            $listData = $node->parent()->parent();
            if ($listData instanceof \League\CommonMark\Extension\CommonMark\Node\Block\ListBlock && $listData->isTight()) {
                return $content . "\n";
            }
        }

        return $content . "\n\n";
    }
}
