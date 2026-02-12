<?php

declare(strict_types=1);

namespace Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gates tool execution by prompting the user for confirmation.
 *
 * Configured with a map of tool names to actions (or `['*']` for all actions)
 * that require interactive approval before the agent can proceed.
 *
 * Example:
 *   new InteractiveApprovalPolicy($io, [
 *       'composer' => ['require', 'remove', 'update'],
 *       'exec'     => ['*'],
 *       'php_execute' => ['*'],
 *   ]);
 */
final class InteractiveApprovalPolicy implements ToolExecutionPolicyInterface
{
    /**
     * @param array<string, string[]> $gatedTools Tool name => list of actions requiring approval.
     *                                             Use ['*'] to gate all invocations of a tool.
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly array $gatedTools = [],
    ) {}

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        if (!$this->requiresApproval($toolName, $arguments)) {
            return true;
        }

        $this->io->newLine();
        $this->io->writeln('<fg=yellow>⚠ Approval required</>');
        $this->io->writeln("<fg=gray>Tool:</> <fg=cyan>{$toolName}</>");
        $this->renderArguments($arguments);

        $confirmed = $this->io->confirm('Allow this action?', false);

        if (!$confirmed) {
            return "User denied execution of '{$toolName}'";
        }

        return true;
    }

    /** @param array<string, mixed> $arguments */
    private function requiresApproval(string $toolName, array $arguments): bool
    {
        if (!isset($this->gatedTools[$toolName])) {
            return false;
        }

        $gatedActions = $this->gatedTools[$toolName];

        // Wildcard — gate every invocation
        if ($gatedActions === ['*']) {
            return true;
        }

        // Check if the specific action is gated
        $action = $arguments['action'] ?? $arguments['command'] ?? null;

        if ($action === null) {
            // No action field — gate by default if the tool is listed
            return true;
        }

        return in_array((string) $action, $gatedActions, true);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function renderArguments(array $arguments): void
    {
        foreach ($arguments as $key => $value) {
            $display = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_string($value) => $this->truncate($value, 120),
                is_numeric($value) => (string) $value,
                is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[...]',
                default => '(complex)',
            };

            $this->io->writeln("<fg=gray>{$key}:</> {$display}");
        }
    }

    private function truncate(string $text, int $maxLength): string
    {
        $text = str_replace(["\n", "\r"], ' ', $text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
