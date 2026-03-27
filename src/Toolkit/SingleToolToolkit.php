<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * Wraps a single ToolInterface as a ToolkitInterface.
 *
 * Allows standalone tools (e.g. PhpExecuteTool, VisionTool) to be
 * passed through the toolkit filtering pipeline used by child agents.
 */
final readonly class SingleToolToolkit implements ToolkitInterface
{
    public function __construct(
        private ToolInterface $tool,
    ) {}

    public function tools(): array
    {
        return [$this->tool];
    }

    public function guidelines(): string
    {
        return '';
    }
}
