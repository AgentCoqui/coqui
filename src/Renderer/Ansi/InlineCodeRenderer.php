<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class InlineCodeRenderer implements NodeRendererInterface
{
    private const string STYLE = "\033[33m";  // yellow
    private const string RESET = "\033[39m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Code::assertInstanceOf($node);
        assert($node instanceof Code);

        return self::STYLE . '`' . $node->getLiteral() . '`' . self::RESET;
    }
}
