<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ListItemRenderer implements NodeRendererInterface
{
    private const string BULLET_STYLE = "\033[36m";  // cyan
    private const string RESET = "\033[39m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        ListItem::assertInstanceOf($node);

        $content = rtrim($childRenderer->renderNodes($node->children()), "\n");

        /** @var ListBlock|null $parent */
        $parent = $node->parent();
        $isOrdered = $parent instanceof ListBlock && $parent->getListData()->type === ListBlock::TYPE_ORDERED;

        if ($isOrdered) {
            $start = $parent->getListData()->start ?? 1;
            // Calculate item index
            $index = $start;
            $sibling = $parent->firstChild();
            while ($sibling !== null && $sibling !== $node) {
                $index++;
                $sibling = $sibling->next();
            }
            $bullet = self::BULLET_STYLE . $index . '.' . self::RESET;
        } else {
            $bullet = self::BULLET_STYLE . '•' . self::RESET;
        }

        // Indent continuation lines
        $lines = explode("\n", $content);
        $first = array_shift($lines);
        $result = '  ' . $bullet . ' ' . $first;

        foreach ($lines as $line) {
            $result .= "\n" . '    ' . $line;
        }

        return $result . "\n";
    }
}
