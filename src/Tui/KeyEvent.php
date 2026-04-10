<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

/**
 * Decoded keypress event from raw terminal input.
 *
 * Translates multi-byte ANSI escape sequences and control characters into
 * typed key events. Used by ScreenRunner to dispatch keyboard input to
 * interactive TUI screens.
 */
final readonly class KeyEvent
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

    private function __construct(
        public string $type,
        public ?string $char = null,
    ) {}

    /**
     * Decode raw bytes read from STDIN into a KeyEvent.
     *
     * Handles ANSI escape sequences (\e[A..D for arrows, \e[3~ for delete),
     * control characters (enter, backspace, tab, ESC), and regular characters.
     */
    public static function fromBytes(string $raw): self
    {
        if ($raw === '') {
            return new self(self::UNKNOWN);
        }

        // ANSI escape sequences: \e[ followed by modifier
        if (str_starts_with($raw, "\e[")) {
            return match (true) {
                str_starts_with($raw, "\e[A") => new self(self::ARROW_UP),
                str_starts_with($raw, "\e[B") => new self(self::ARROW_DOWN),
                str_starts_with($raw, "\e[C") => new self(self::ARROW_RIGHT),
                str_starts_with($raw, "\e[D") => new self(self::ARROW_LEFT),
                str_starts_with($raw, "\e[3~") => new self(self::DELETE),
                default => new self(self::UNKNOWN),
            };
        }

        // Single ESC (0x1B) without subsequent bracket
        if ($raw === "\e") {
            return new self(self::ESC);
        }

        // Control characters
        $byte = ord($raw[0]);

        return match ($byte) {
            10, 13 => new self(self::ENTER),           // LF, CR
            127 => new self(self::BACKSPACE),           // DEL (macOS terminal)
            8 => new self(self::BACKSPACE),             // BS (some terminals)
            9 => new self(self::TAB),
            27 => new self(self::ESC),                  // ESC with trailing bytes
            default => $byte >= 32                      // Printable ASCII or UTF-8
                ? new self(self::CHAR, $raw)
                : new self(self::UNKNOWN),
        };
    }
}
