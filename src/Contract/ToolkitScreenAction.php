<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Navigation or refresh action returned by a toolkit fullscreen screen.
 */
final readonly class ToolkitScreenAction
{
    private const string TYPE_EXIT = 'exit';
    private const string TYPE_PUSH = 'push';
    private const string TYPE_POP = 'pop';
    private const string TYPE_REFRESH = 'refresh';

    private function __construct(
        public string $type,
        public ?ToolkitScreenInterface $screen = null,
    ) {}

    public static function exit(): self
    {
        return new self(self::TYPE_EXIT);
    }

    public static function push(ToolkitScreenInterface $screen): self
    {
        return new self(self::TYPE_PUSH, $screen);
    }

    public static function pop(): self
    {
        return new self(self::TYPE_POP);
    }

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