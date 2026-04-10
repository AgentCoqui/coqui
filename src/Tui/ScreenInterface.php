<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Contract for interactive TUI screens.
 *
 * Each screen renders a full-screen view, handles keyboard input, and
 * supports periodic data refresh. Screens are composed via a stack in
 * ScreenRunner — pushing a screen navigates forward, popping goes back.
 *
 * Designed for reuse across different TUI views (loops, tasks, schedules).
 */
interface ScreenInterface
{
    /**
     * Render the full screen content to the output.
     *
     * Called after every screen clear. Should write complete output for the
     * visible area. Width and height represent the terminal dimensions.
     */
    public function render(OutputInterface $output, int $width, int $height): void;

    /**
     * Handle a keypress and return an action for the screen runner.
     *
     * Return null to trigger a re-render (data changed locally).
     * Return a ScreenAction to navigate (push, pop, exit) or explicitly refresh.
     */
    public function handleKey(KeyEvent $key): ?ScreenAction;

    /**
     * Periodic data refresh callback.
     *
     * Called on a regular interval (e.g. every 1 second) by the screen runner.
     * Implementations should re-query data sources and return true if the
     * display needs to be updated.
     */
    public function tick(): bool;

    /**
     * Screen title for the header bar.
     */
    public function title(): string;
}
