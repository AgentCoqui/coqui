<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Contract for toolkit-provided fullscreen interactive screens.
 */
interface ToolkitScreenInterface
{
    public function render(OutputInterface $output, int $width, int $height): void;

    public function handleKey(ToolkitKeyEvent $key): ?ToolkitScreenAction;

    public function tick(): bool;

    public function title(): string;
}