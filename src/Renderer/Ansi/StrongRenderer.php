<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class StrongRenderer implements NodeRendererInterface
{
    private const string BOLD = "\033[1m";
    private const string RESET = "\033[22m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Strong::assertInstanceOf($node);

        return self::BOLD . $childRenderer->renderNodes($node->children()) . self::RESET;
    }
}
