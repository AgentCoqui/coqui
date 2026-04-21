<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Toolkit-facing host for fullscreen interactive screens.
 */
interface ToolkitScreenHostInterface
{
    public function isInteractiveTerminal(): bool;

    public function runScreen(ToolkitScreenInterface $screen): void;
}