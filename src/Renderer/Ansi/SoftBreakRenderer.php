<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class SoftBreakRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        return "\n";
    }
}
