<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class ThematicBreakRenderer implements NodeRendererInterface
{
    private const string STYLE = "\033[2m";
    private const string RESET = "\033[0m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        ThematicBreak::assertInstanceOf($node);

        return self::STYLE . str_repeat('─', 40) . self::RESET . "\n\n";
    }
}
