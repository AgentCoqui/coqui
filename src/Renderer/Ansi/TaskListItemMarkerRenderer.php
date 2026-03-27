<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TaskListItemMarkerRenderer implements NodeRendererInterface
{
    private const string CHECK_STYLE = "\033[32m";  // green
    private const string UNCHECK_STYLE = "\033[90m";  // gray
    private const string RESET = "\033[39m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        TaskListItemMarker::assertInstanceOf($node);
        assert($node instanceof TaskListItemMarker);

        if ($node->isChecked()) {
            return self::CHECK_STYLE . '✓' . self::RESET . ' ';
        }

        return self::UNCHECK_STYLE . '○' . self::RESET . ' ';
    }
}
