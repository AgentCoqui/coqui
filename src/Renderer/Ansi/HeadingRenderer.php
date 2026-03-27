<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class HeadingRenderer implements NodeRendererInterface
{
    /** @var array<int, string> ANSI styles per heading level */
    private const array LEVEL_STYLES = [
        1 => "\033[1;36m",  // bold cyan
        2 => "\033[1;34m",  // bold blue
        3 => "\033[1;35m",  // bold magenta
        4 => "\033[1;33m",  // bold yellow
        5 => "\033[1;32m",  // bold green
        6 => "\033[1;37m",  // bold white
    ];

    private const string RESET = "\033[0m";

    /** @var array<int, string> prefix per heading level */
    private const array LEVEL_PREFIXES = [
        1 => '# ',
        2 => '## ',
        3 => '### ',
        4 => '#### ',
        5 => '##### ',
        6 => '###### ',
    ];

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Heading::assertInstanceOf($node);
        assert($node instanceof Heading);

        $level = $node->getLevel();
        $style = self::LEVEL_STYLES[$level] ?? self::LEVEL_STYLES[6];
        $prefix = self::LEVEL_PREFIXES[$level] ?? '';
        $content = $childRenderer->renderNodes($node->children());

        return $style . $prefix . $content . self::RESET . "\n\n";
    }
}
