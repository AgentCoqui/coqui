<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Shared metadata for a REPL slash command.
 */
final readonly class ReplCommandSpec
{
    /**
     * @param list<string> $aliases
     * @param list<string> $firstArguments
     */
    public function __construct(
        public string $name,
        public string $usage,
        public string $description,
        public array $aliases = [],
        public array $firstArguments = [],
        public string $section = 'General',
    ) {}

    /**
     * @return list<string>
     */
    public function allNames(): array
    {
        return [$this->name, ...$this->aliases];
    }

    public function helpDescription(): string
    {
        if ($this->aliases === []) {
            return $this->description;
        }

        return sprintf('%s Aliases: %s.', $this->description, implode(', ', $this->aliases));
    }
}