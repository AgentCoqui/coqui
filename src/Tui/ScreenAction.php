<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

/**
 * Action returned by a screen's key handler to direct the ScreenRunner.
 *
 * Immutable value object with static constructors for each navigation intent.
 */
final readonly class ScreenAction
{
    private const string TYPE_EXIT = 'exit';
    private const string TYPE_PUSH = 'push';
    private const string TYPE_POP = 'pop';
    private const string TYPE_REFRESH = 'refresh';

    private function __construct(
        public string $type,
        public ?ScreenInterface $screen = null,
    ) {}

    /** Exit the TUI entirely and return to the REPL. */
    public static function exit(): self
    {
        return new self(self::TYPE_EXIT);
    }

    /** Push a new screen onto the stack (navigate forward). */
    public static function push(ScreenInterface $screen): self
    {
        return new self(self::TYPE_PUSH, $screen);
    }

    /** Pop the current screen off the stack (navigate back). */
    public static function pop(): self
    {
        return new self(self::TYPE_POP);
    }

    /** Re-render the current screen without navigation. */
    public static function refresh(): self
    {
        return new self(self::TYPE_REFRESH);
    }

    public function isExit(): bool
    {
        return $this->type === self::TYPE_EXIT;
    }

    public function isPush(): bool
    {
        return $this->type === self::TYPE_PUSH;
    }

    public function isPop(): bool
    {
        return $this->type === self::TYPE_POP;
    }
}
