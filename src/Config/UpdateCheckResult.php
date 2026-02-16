<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Value object representing the result of a composer outdated check.
 */
final readonly class UpdateCheckResult
{
    /**
     * @param bool $hasUpdates Whether any packages have available updates.
     * @param array<int, array{name: string, current: string, latest: string, description: string}> $packages
     */
    public function __construct(
        public bool $hasUpdates,
        public array $packages = [],
        public string $error = '',
    ) {}

    public static function none(): self
    {
        return new self(hasUpdates: false);
    }

    public static function error(string $message): self
    {
        return new self(hasUpdates: false, error: $message);
    }

    /**
     * Format a human-readable summary for terminal display.
     */
    public function summary(): string
    {
        if ($this->error !== '') {
            return "Update check failed: {$this->error}";
        }

        if (!$this->hasUpdates) {
            return 'All packages are up to date.';
        }

        $lines = ['Updates available:'];
        foreach ($this->packages as $pkg) {
            $lines[] = "  {$pkg['name']}: {$pkg['current']} → {$pkg['latest']}";
        }

        return implode("\n", $lines);
    }
}
