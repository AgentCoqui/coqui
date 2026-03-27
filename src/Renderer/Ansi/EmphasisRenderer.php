<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class EmphasisRenderer implements NodeRendererInterface
{
    private const string ITALIC = "\033[3m";
    private const string RESET = "\033[23m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Emphasis::assertInstanceOf($node);

        return self::ITALIC . $childRenderer->renderNodes($node->children()) . self::RESET;
    }
}
