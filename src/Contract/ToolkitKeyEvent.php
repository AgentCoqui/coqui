<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Toolkit-facing key event used by fullscreen toolkit screens.
 */
final readonly class ToolkitKeyEvent
{
    public const string ARROW_UP = 'arrow_up';
    public const string ARROW_DOWN = 'arrow_down';
    public const string ARROW_LEFT = 'arrow_left';
    public const string ARROW_RIGHT = 'arrow_right';
    public const string ENTER = 'enter';
    public const string ESC = 'esc';
    public const string BACKSPACE = 'backspace';
    public const string DELETE = 'delete';
    public const string TAB = 'tab';
    public const string CHAR = 'char';
    public const string UNKNOWN = 'unknown';

    public function __construct(
        public string $type,
        public ?string $char = null,
    ) {}
}