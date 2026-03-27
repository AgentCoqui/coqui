<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class StrikethroughRenderer implements NodeRendererInterface
{
    private const string STRIKETHROUGH = "\033[9m";
    private const string RESET = "\033[29m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Strikethrough::assertInstanceOf($node);

        return self::STRIKETHROUGH . $childRenderer->renderNodes($node->children()) . self::RESET;
    }
}
