<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ParagraphRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Paragraph::assertInstanceOf($node);

        $content = rtrim($childRenderer->renderNodes($node->children()), "\n");

        if ($node->parent() instanceof ListItem) {
            $list = $node->parent()->parent();
            if ($list instanceof ListBlock && $list->isTight()) {
                return $content;
            }

            return $content . "\n";
        }

        return $content . "\n\n";
    }
}
