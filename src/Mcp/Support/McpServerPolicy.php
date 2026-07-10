<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Support;

/**
 * Validates stdio MCP server definitions against an optional allow/deny policy.
 *
 * Policy entries are exact command tuples: [command, arg1, arg2, ...].
 */
final class McpServerPolicy
{
    /**
     * @var list<list<string>>
     */
    private array $allowedStdioCommands;

    /**
     * @var list<list<string>>
     */
    private array $deniedStdioCommands;

    /**
     * @param list<list<string>> $allowedStdioCommands
     * @param list<list<string>> $deniedStdioCommands
     */
    public function __construct(array $allowedStdioCommands = [], array $deniedStdioCommands = [])
    {
        $this->allowedStdioCommands = $this->normalizeTuples($allowedStdioCommands);
        $this->deniedStdioCommands = $this->normalizeTuples($deniedStdioCommands);
    }

    public static function fromConfigValues(mixed $allowed, mixed $denied): self
    {
        return new self(
            self::coerceConfigTuples($allowed),
            self::coerceConfigTuples($denied),
        );
    }

    /**
     * @param list<string> $args
     */
    public function assertAllowedStdioCommand(string $command, array $args = []): void
    {
        $violation = $this->validateStdioCommand($command, $args);
        if ($violation !== null) {
            throw new \InvalidArgumentException($violation);
        }
    }

    /**
     * @param list<string> $args
     */
    public function validateStdioCommand(string $command, array $args = []): ?string
    {
        if (preg_match('/[\r\n]/', $command) === 1) {
            return 'Denied: MCP server command cannot contain line breaks.';
        }

        foreach ($args as $index => $arg) {
            if (preg_match('/[\r\n]/', $arg) === 1) {
                return sprintf('Denied: MCP server argument %d cannot contain line breaks.', $index);
            }
        }

        $candidate = [$command, ...$args];
        $display = $this->formatTuple($candidate);

        if ($this->matchesTuple($candidate, $this->deniedStdioCommands)) {
            return sprintf('Denied: MCP stdio command %s is blocked by policy.', $display);
        }

        if ($this->allowedStdioCommands !== [] && !$this->matchesTuple($candidate, $this->allowedStdioCommands)) {
            return sprintf('Denied: MCP stdio command %s is not in the allowed policy.', $display);
        }

        return null;
    }

    /**
     * @param list<list<string>> $tuples
     * @return list<list<string>>
     */
    private function normalizeTuples(array $tuples): array
    {
        $normalized = [];

        foreach ($tuples as $tuple) {
            $normalized[] = array_map(
                static fn(string $value): string => trim($value),
                $tuple,
            );
        }

        return $normalized;
    }

    /**
     * @return list<list<string>>
     */
    private static function coerceConfigTuples(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $tuples = [];

        foreach ($value as $tuple) {
            if (!is_array($tuple) || !array_is_list($tuple) || $tuple === []) {
                continue;
            }

            $normalizedTuple = [];

            foreach ($tuple as $part) {
                if (!is_string($part) || trim($part) === '') {
                    continue 2;
                }

                $normalizedTuple[] = trim($part);
            }

            $tuples[] = $normalizedTuple;
        }

        return $tuples;
    }

    /**
     * @param list<string> $candidate
     * @param list<list<string>> $rules
     */
    private function matchesTuple(array $candidate, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($candidate === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tuple
     */
    private function formatTuple(array $tuple): string
    {
        return implode(' ', array_map(static fn(string $part): string => escapeshellarg($part), $tuple));
    }
}